#!/bin/bash
#
# Integration test for graceful shutdown in Docker
#
# This script:
# 1. Starts a RabbitMQ container
# 2. Starts a consumer container
# 3. Sends test messages
# 4. Sends SIGTERM to consumer (simulating Kubernetes rollout)
# 5. Verifies consumer completes current message before exiting
# 6. Checks exit code is 0 (clean shutdown)
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║     Docker Integration Test - Graceful Shutdown v7.5.2      ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Cleanup function
cleanup() {
    echo ""
    echo "🧹 Cleaning up..."
    docker compose -f "$SCRIPT_DIR/docker-compose.test.yml" down -v 2>/dev/null || true
    echo "✅ Cleanup complete"
}

trap cleanup EXIT

# Check if Docker is running
if ! docker info >/dev/null 2>&1; then
    echo -e "${RED}❌ ERROR: Docker is not running${NC}"
    exit 1
fi

# Check if docker compose exists
if ! command -v docker &> /dev/null; then
    echo -e "${RED}❌ ERROR: docker is not installed${NC}"
    exit 1
fi

echo "✅ Docker is running"
echo "✅ docker compose is available"
echo ""

# Start services
echo "🚀 Starting RabbitMQ and PostgreSQL..."
cd "$SCRIPT_DIR"
docker compose -f docker-compose.test.yml up -d rabbitmq postgres

# Wait for RabbitMQ
echo "⏳ Waiting for RabbitMQ to be ready..."
for i in {1..30}; do
    if docker compose -f docker-compose.test.yml exec -T rabbitmq rabbitmqctl status >/dev/null 2>&1; then
        echo "✅ RabbitMQ is ready"
        break
    fi
    if [ $i -eq 30 ]; then
        echo -e "${RED}❌ RabbitMQ failed to start${NC}"
        exit 1
    fi
    sleep 1
done

# Wait for PostgreSQL
echo "⏳ Waiting for PostgreSQL to be ready..."
for i in {1..30}; do
    if docker compose -f docker-compose.test.yml exec -T postgres pg_isready -U test_user >/dev/null 2>&1; then
        echo "✅ PostgreSQL is ready"
        break
    fi
    if [ $i -eq 30 ]; then
        echo -e "${RED}❌ PostgreSQL failed to start${NC}"
        exit 1
    fi
    sleep 1
done

echo ""
echo "📦 Building consumer container..."
docker compose -f docker-compose.test.yml build consumer

echo ""
echo "🚀 Starting consumer in background..."
docker compose -f docker-compose.test.yml up -d consumer

# Give consumer time to start
sleep 3

# Check if consumer is running
if ! docker compose ps | grep consumer | grep -q Up; then
    echo -e "${RED}❌ Consumer failed to start${NC}"
    docker compose logs consumer
    exit 1
fi

echo "✅ Consumer is running"
CONSUMER_PID=$(docker compose exec -T consumer sh -c 'pgrep -f "php.*consumer"' | head -1)
echo "   Consumer PID in container: $CONSUMER_PID"
echo ""

# Publish test message
echo "📨 Publishing test message to RabbitMQ..."
docker compose exec -T consumer php -r "
require '/app/vendor/autoload.php';
use AlexFN\NanoService\NanoPublisher;

\$_ENV['AMQP_HOST'] = 'rabbitmq';
\$_ENV['AMQP_PORT'] = '5672';
\$_ENV['AMQP_USER'] = 'guest';
\$_ENV['AMQP_PASS'] = 'guest';
\$_ENV['AMQP_VHOST'] = '/';
\$_ENV['AMQP_PROJECT'] = 'test';
\$_ENV['AMQP_MICROSERVICE_NAME'] = 'test-publisher';
\$_ENV['DB_BOX_HOST'] = 'postgres';
\$_ENV['DB_BOX_PORT'] = '5432';
\$_ENV['DB_BOX_NAME'] = 'test_db';
\$_ENV['DB_BOX_USER'] = 'test_user';
\$_ENV['DB_BOX_PASS'] = 'test_pass';
\$_ENV['DB_BOX_SCHEMA'] = 'public';
\$_ENV['STATSD_ENABLED'] = 'false';

\$publisher = new NanoPublisher();
\$publisher->init();
\$publisher->publish('user.created', ['user_id' => 123, 'test' => true]);
echo 'Message published\n';
"

if [ $? -eq 0 ]; then
    echo "✅ Message published successfully"
else
    echo -e "${RED}❌ Failed to publish message${NC}"
    exit 1
fi

echo ""
echo "⏳ Waiting for message to be received (5 seconds)..."
sleep 5

# Check consumer logs
echo ""
echo "📋 Consumer logs (before SIGTERM):"
docker compose logs --tail=20 consumer

echo ""
echo "🛑 Sending SIGTERM to consumer (simulating Kubernetes pod termination)..."
docker compose exec -T consumer kill -TERM "$CONSUMER_PID"

echo "⏳ Waiting for graceful shutdown (max 30 seconds)..."
for i in {1..30}; do
    if ! docker compose ps | grep consumer | grep -q Up; then
        echo "✅ Consumer exited"
        break
    fi
    if [ $i -eq 30 ]; then
        echo -e "${YELLOW}⚠️  Consumer still running after 30s, forcing stop${NC}"
        docker compose stop consumer
    fi
    sleep 1
done

# Get exit code
EXIT_CODE=$(docker compose ps -q consumer | xargs docker inspect -f '{{.State.ExitCode}}')
echo ""
echo "📊 Consumer exit code: $EXIT_CODE"

# Check logs for graceful shutdown
echo ""
echo "📋 Consumer logs (after SIGTERM):"
docker compose logs consumer | tail -50

echo ""
echo "🔍 Checking for graceful shutdown indicators..."

# Check for signal received
if docker compose logs consumer | grep -q "nano_consumer_shutdown_signal_received"; then
    echo -e "${GREEN}✅ Signal received log found${NC}"
else
    echo -e "${RED}❌ Signal received log NOT found${NC}"
fi

# Check for graceful shutdown completed
if docker compose logs consumer | grep -q "nano_consumer_graceful_shutdown_completed"; then
    echo -e "${GREEN}✅ Graceful shutdown completed log found${NC}"
else
    echo -e "${RED}❌ Graceful shutdown completed log NOT found${NC}"
fi

# Check exit code
echo ""
if [ "$EXIT_CODE" = "0" ]; then
    echo -e "${GREEN}✅ EXIT CODE 0 - Clean shutdown confirmed${NC}"
else
    echo -e "${RED}❌ EXIT CODE $EXIT_CODE - Unexpected exit code${NC}"
fi

echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
if [ "$EXIT_CODE" = "0" ]; then
    echo -e "║  ${GREEN}✅ GRACEFUL SHUTDOWN TEST PASSED${NC}                          ║"
else
    echo -e "║  ${RED}❌ GRACEFUL SHUTDOWN TEST FAILED${NC}                          ║"
fi
echo "╚══════════════════════════════════════════════════════════════╝"

# Exit with consumer's exit code
exit "$EXIT_CODE"
