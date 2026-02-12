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

| Variable | Description | Example |
|----------|-------------|---------|
| `STATSD_ENABLED` | Enable metrics | `true` (default: `false`) |
| `STATSD_HOST` | StatsD server host | `10.192.0.15` |
| `STATSD_PORT` | StatsD server port | `8125` |
| `STATSD_NAMESPACE` | Metric namespace (unique per service) | `myservice` |
| `STATSD_SAMPLE_OK` | Success sampling rate 0.0-1.0 | `0.1` |
| `STATSD_SAMPLE_PAYLOAD` | Payload sampling rate 0.0-1.0 | `0.1` |
| `APP_ENV` | Environment tag | `production` |

Error metrics are always sent at 100% regardless of sampling.

### Sampling by traffic volume

| Traffic | `STATSD_SAMPLE_OK` |
|---------|-------------------|
| < 100 events/sec | `1.0` (100%) |
| 100-1000 events/sec | `0.1` (10%) |
| > 1000 events/sec | `0.01` (1%) |

---

## Connection Lifecycle (Optional)

| Variable | Description | Example |
|----------|-------------|---------|
| `CONNECTION_MAX_JOBS` | Reconnect RabbitMQ + DB after N messages | `10000` (default: `0` = disabled) |

Useful for preventing stale connections in long-running workers.

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
