#!/bin/bash
# EW-418 repro: the inbox lock owner dies mid-processing (kill -9 = OOMKilled); the redelivery must not be dropped.
# Usage: ./test-crash-mid-message.sh [INBOX_LOCK_WAIT_MAX]   (0 reproduces the pre-8.3 loss)
#        ./test-crash-mid-message.sh --down                  (tear the stand down)
set -euo pipefail
cd "$(dirname "$0")"
DC() { docker compose -p nanocrash -f docker-compose.crash.yml "$@"; }

if [ "${1:-}" = "--down" ]; then DC down -v; exit 0; fi
export INBOX_LOCK_WAIT_MAX="${1:-3}"

DC up -d --build rabbitmq postgres >/dev/null 2>&1
# RabbitMQ with the delayed plugin can take 2-3 min to boot on a laptop
for i in $(seq 1 150); do
  if DC exec -T rabbitmq rabbitmq-diagnostics -q check_port_connectivity >/dev/null 2>&1 \
     && DC exec -T postgres pg_isready -U test_user >/dev/null 2>&1; then break; fi
  sleep 2
done

DC exec -T postgres psql -U test_user -d test_db -q <<'SQL'
CREATE TABLE IF NOT EXISTS public.inbox (
  id SERIAL PRIMARY KEY, consumer_service VARCHAR(255) NOT NULL, producer_service VARCHAR(255) NOT NULL,
  event_type VARCHAR(255) NOT NULL, message_body JSONB NOT NULL, message_id TEXT NOT NULL,
  status VARCHAR(50) NOT NULL, retry_count INT DEFAULT 1, last_error TEXT, created_at TIMESTAMP DEFAULT NOW(),
  processed_at TIMESTAMP, locked_at TIMESTAMP, locked_by VARCHAR(255),
  CONSTRAINT inbox_message_consumer_unique UNIQUE (message_id, consumer_service));
CREATE TABLE IF NOT EXISTS public.outbox (
  id SERIAL PRIMARY KEY, producer_service VARCHAR(255) NOT NULL, event_type VARCHAR(255) NOT NULL,
  message_body JSONB NOT NULL, partition_key VARCHAR(255), message_id TEXT NOT NULL, status VARCHAR(50) NOT NULL,
  created_at TIMESTAMP DEFAULT NOW(), published_at TIMESTAMP, retry_count INT DEFAULT 0, last_error TEXT,
  locked_at TIMESTAMP, locked_by VARCHAR(255));
TRUNCATE public.inbox, public.outbox;
SQL
DC rm -sf consumer-a consumer-b >/dev/null 2>&1 || true
DC exec -T rabbitmq rabbitmqctl -q purge_queue test.test-consumer >/dev/null 2>&1 || true
DC exec -T rabbitmq rabbitmqctl -q eval 'rabbit_exchange:declare(rabbit_misc:r(<<"/">>, exchange, <<"test.bus">>), topic, true, false, false, [], <<"test">>).' >/dev/null

DC up -d consumer-a consumer-b >/dev/null 2>&1
sleep 8
DC ps --format 'table {{.Service}}\t{{.State}}'

echo "== publish one message"
DC run --rm -T consumer-a php -r '
require "/app/vendor/autoload.php";
$_ENV["AMQP_MICROSERVICE_NAME"] = "test-publisher";
foreach (["AMQP_HOST","AMQP_PORT","AMQP_USER","AMQP_PASS","AMQP_VHOST","AMQP_PROJECT","DB_BOX_HOST","DB_BOX_PORT","DB_BOX_NAME","DB_BOX_USER","DB_BOX_PASS","DB_BOX_SCHEMA","STATSD_ENABLED"] as $k) { $_ENV[$k] = getenv($k); }
$m = new AlexFN\NanoService\NanoServiceMessage(); $m->addPayload(["user_id" => 1]);
(new AlexFN\NanoService\NanoPublisher())->setMessage($m)->publish("user.created"); echo "published " . $m->getId() . "\n";' 2>&1 | grep -v '^{"message"'
sleep 4

OWNER=""
for c in consumer-a consumer-b; do
  if DC logs "$c" 2>/dev/null | grep -q handler_started; then OWNER="$c"; fi
done
[ -n "$OWNER" ] || { echo "no consumer started the handler"; DC logs consumer-a consumer-b | tail -20; exit 1; }
SURVIVOR=$([ "$OWNER" = consumer-a ] && echo consumer-b || echo consumer-a)
echo "== owner=$OWNER killed -9 at $(date -u +%T) (like OOMKilled), survivor=$SURVIVOR"
docker kill -s KILL "$(DC ps -q "$OWNER")" >/dev/null

for i in $(seq 1 70); do
  if DC logs "$SURVIVOR" 2>/dev/null | grep -q handler_finished; then echo "== survivor finished the message after ${i}s"; break; fi
  sleep 1
done

echo "== survivor timeline (UTC)"
DC logs -t "$SURVIVOR" 2>/dev/null | grep '"message":"' \
  | sed -E 's/^[^|]*\| *[0-9-]*T([0-9:]{8})\.[0-9]+Z (.*)$/\1 \2/' \
  | grep -o '^[0-9:]* .*"message":"[a-z_]*"' | sed -E 's/ .*"message":"/  /; s/"$//' \
  | grep -v "signal_handlers\|lifecycle\|consumer_started\|consumer_init\|inbox_processed_failed\|claim_inbox_failed"
echo "== inbox row"
DC exec -T postgres psql -U test_user -d test_db -Atc "select status||' locked_by='||coalesce(locked_by,'-')||' retry_count='||retry_count||' locked_at='||coalesce(locked_at::time(0)::text,'-') from public.inbox where consumer_service='test-consumer'"
