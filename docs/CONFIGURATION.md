# Configuration

Single source of truth for all nano-service environment variables.

All variables use fail-fast validation — missing required variables throw `RuntimeException` at startup.

---

## RabbitMQ (Required)

| Variable | Description | Example |
|----------|-------------|---------|
| `AMQP_HOST` | RabbitMQ host | `rabbitmq.internal` |
| `AMQP_PORT` | RabbitMQ port | `5672` |
| `AMQP_USER` | Username | `user` |
| `AMQP_PASS` | Password | `password` |
| `AMQP_VHOST` | Virtual host | `/` |
| `AMQP_PROJECT` | Project namespace | `myproject` |
| `AMQP_MICROSERVICE_NAME` | Service identifier (used in queue names) | `myservice` |

---

## PostgreSQL (Required)

Used by the outbox/inbox pattern for reliable event delivery and idempotency.

| Variable | Description | Example |
|----------|-------------|---------|
| `DB_BOX_HOST` | PostgreSQL host | `postgres.internal` |
| `DB_BOX_PORT` | PostgreSQL port | `5432` |
| `DB_BOX_NAME` | Database name | `nanoservice-myservice` |
| `DB_BOX_USER` | Database username | `myservice` |
| `DB_BOX_PASS` | Database password | `secret` |
| `DB_BOX_SCHEMA` | PostgreSQL schema (outbox/inbox tables live here) | `myservice` |

---

## StatsD Metrics (Optional)

Disabled by default. When `STATSD_ENABLED=true`, all StatsD variables become **required**.

| Variable | Description | Example | Required |
|----------|-------------|---------|----------|
| `STATSD_ENABLED` | Enable metrics | `true` (default: `false`) | No |
| `STATSD_HOST` | StatsD server host | `10.192.0.15` | Yes (if enabled) |
| `STATSD_PORT` | StatsD server port | `8125` | Yes (if enabled) |
| `STATSD_NAMESPACE` | Project name (not service name) | `ew` | Yes (if enabled) |
| `APP_ENV` | Environment tag (default: `unknown`) | `production` | No |

**Default tags** (auto-added to every metric):
- `nano_service_name` — from `AMQP_MICROSERVICE_NAME` env var
- `env` — from `APP_ENV` env var (defaults to `unknown` if not set)

**Metric naming format:** `{STATSD_NAMESPACE}.{metric_name}`
- Example: `ew.event_started_count`
- In Grafana, type `ew.` to autocomplete all metrics for the "ew" project

All metrics are sent at 100% (no sampling). StatsD uses UDP (fire-and-forget) so overhead is minimal.

---

## Connection Lifecycle (Optional)

Controls automatic reconnection for long-running workers to prevent memory leaks and stale connections.

| Variable | Description | Example |
|----------|-------------|---------|
| `CONNECTION_MAX_JOBS` | Reconnect RabbitMQ + DB after N successfully processed messages | `10000` (default: `0` = disabled) |

**How it works:**
- Worker tracks the count of **successfully processed** messages (ACK'd without errors)
- When count reaches `CONNECTION_MAX_JOBS`, the worker:
  1. Exits the consume loop gracefully (cancels consumer)
  2. Closes and nulls all RabbitMQ connections and channels
  3. Resets PostgreSQL connection pool
  4. Resets counter to 0
  5. Resumes consumption with fresh connections
- Only successful messages increment the counter (failed/retried messages don't count)
- On RabbitMQ errors, counter resets to prevent stale state

**Recommended values:**

| Workload type | `CONNECTION_MAX_JOBS` | Rationale |
|--------------|----------------------|-----------|
| High throughput (>1000 msg/min) | `10000` - `50000` | Balance reconnect overhead vs stale connection risk |
| Medium throughput (100-1000 msg/min) | `5000` - `10000` | More frequent reconnects acceptable |
| Low throughput (<100 msg/min) | `1000` - `5000` | Memory leaks accumulate faster at low rate |
| Memory-intensive processing | `500` - `2000` | Aggressive cleanup for large payloads |

**Metrics emitted:**
- `rmq_consumer_connection_reinit_total` (counter) - Total reinitialization events, tagged with `reason: max_jobs`
- `rmq_consumer_connection_reinit_duration_ms` (timing) - Time spent reinitializing connections

**Logs emitted:**
- `nano_consumer_lifecycle_enabled` (INFO) - Feature enabled at startup
- `nano_consumer_max_jobs_exceeded` (INFO) - Threshold reached, triggering reinit
- `nano_consumer_connections_reinitializing` (INFO) - Starting reinit
- `nano_consumer_connections_reinitialized` (INFO) - Reinit completed
- `nano_consumer_connections_reinit_failed` (ERROR) - Reinit error (retries anyway)

**Use cases:**
- Preventing memory leaks in PHP workers processing large payloads
- Avoiding stale database connections after network issues
- Forcing DNS re-resolution after infrastructure changes (Cilium proxy restarts)
- Clearing accumulated internal state in long-running processes

---

## Graceful Shutdown (Automatic)

**Graceful shutdown is enabled automatically when the `pcntl` extension is available.**

Consumer handles POSIX signals for clean shutdown during Kubernetes deployments and manual termination:

| Signal | Source | Behavior |
|--------|--------|----------|
| `SIGTERM` | Kubernetes pod termination | Stop accepting new messages, finish current message, close connections |
| `SIGINT` | User presses Ctrl+C | Same as SIGTERM |
| `SIGHUP` | Terminal disconnect | Same as SIGTERM |

**How it works:**
1. Signal received (e.g., SIGTERM from Kubernetes during rolling deployment)
2. Consumer cancels RabbitMQ consumer (stops receiving new messages)
3. Current message processing completes (ACK sent, inbox updated)
4. Connections closed gracefully
5. Process exits cleanly with code 0

**Metrics emitted:**
- `rmq_consumer_graceful_shutdown_total` (counter) - Total graceful shutdowns, tagged with `reason: signal`
- `rmq_consumer_graceful_shutdown_duration_ms` (timing) - Time spent shutting down

**Logs emitted:**
- `nano_consumer_shutdown_signal_received` (INFO) - Signal received (SIGTERM/SIGINT/SIGHUP)
- `nano_consumer_graceful_shutdown_initiated` (INFO) - Shutdown started
- `nano_consumer_graceful_shutdown_completed` (INFO) - Shutdown finished
- `nano_consumer_pcntl_not_available` (WARNING) - PCNTL extension missing (graceful shutdown disabled)

**Requirements:**
- PHP compiled with `--enable-pcntl` OR `php-pcntl` package installed
- Check availability: `php -m | grep pcntl`
- Without PCNTL: Consumer relies on Kubernetes `terminationGracePeriodSeconds` (30s default)

**Kubernetes recommendations:**
- Set `terminationGracePeriodSeconds` based on your slowest message processing time
- For fast messages (<5s): `terminationGracePeriodSeconds: 30` (default)
- For slow messages (5-30s): `terminationGracePeriodSeconds: 60`
- For very slow messages (30s-2min): `terminationGracePeriodSeconds: 120`

**Important:** Without PCNTL, messages in progress during pod termination will be:
- **Lost** if processing takes longer than `terminationGracePeriodSeconds`
- **Redelivered** by RabbitMQ (inbox pattern ensures idempotency)
- **Duplicate risk** if ACK was sent but inbox not updated before SIGKILL

---

## Consumer Concurrency Control (Optional)

| Variable | Description | Example |
|----------|-------------|---------|
| `INBOX_LOCK_STALE_THRESHOLD` | Seconds before considering a lock stale/abandoned | `300` (default: `300` = 5 minutes) |
| `POD_NAME` | Worker identifier for locking (auto-set by Kubernetes) | `myservice-worker-abc123` |

**How it works:**
- When a consumer processes a message, it atomically locks the inbox row with `locked_at=NOW()` and `locked_by=<worker_id>`
- If another worker receives the same message (redelivery), it checks if the lock is stale before claiming
- Stale threshold should be > your longest message processing time to avoid premature claims
- If `POD_NAME` is not set, falls back to `hostname:pid`

**Use cases:**
- Prevents duplicate processing during RabbitMQ redeliveries (connection drops, pod restarts)
- Allows safe retry of failed messages without risk of concurrent execution
- Enables detection of abandoned/crashed workers for alerting

**Recommended values:**

| Message processing time | `INBOX_LOCK_STALE_THRESHOLD` |
|------------------------|------------------------------|
| < 1 minute | `300` (5 min) - default |
| 1-5 minutes | `600` (10 min) |
| 5-10 minutes | `900` (15 min) |
| > 10 minutes | Consider async workers |

**Security note:** This fix addresses **Issue 1 - Concurrent Processing via existsInInbox Bypass** (see CONCURRENCY_ISSUES.md)

---

## Security

Never hardcode `AMQP_PASS`, `DB_BOX_USER`, `DB_BOX_PASS` — use Kubernetes Secrets.

`STATSD_HOST` must use Downward API (`status.hostIP`) in Kubernetes — it's pod-specific.

StatsD uses UDP (fire-and-forget, no auth, no encryption) — keep on internal network only.

---

## Quick Verification

```bash
kubectl exec <pod> -- env | grep -E "(AMQP|DB_BOX|STATSD)"
```

See [DEPLOYMENT.md](DEPLOYMENT.md) for Kubernetes templates.
See [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for common issues.
