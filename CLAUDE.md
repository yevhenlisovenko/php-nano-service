# Claude Code Guidelines for nano-service

LLM rules for safe development of this production library.

---

## Absolute Rules

1. **NEVER git add/commit/push** — user commits manually
2. **NEVER break backwards compatibility** — no signature changes, no removed public methods, no changed defaults
3. **New features must be opt-in** — disabled by default, enabled via env vars
4. **Metrics must never break the app** — fire-and-forget UDP, silently fail on error
5. **Tags must be low cardinality** — NEVER use user_id, invoice_id, request_id, UUID as tags
6. **Reuse connections** — static shared `$sharedConnection` and `$sharedChannel`, never create in loops
7. **Update docs** — [METRICS.md](docs/METRICS.md), [CONFIGURATION.md](docs/CONFIGURATION.md), [CHANGELOG.md](docs/CHANGELOG.md)

---

## Current State (v8.0.0)

- `AMQP_MICROSERVICE_NAME` validated once in `NanoServiceClass` constructor
- Default tags (`nano_service_name`, `env`) auto-added by StatsD client to every metric
- No sampling system — all metrics sent at 100% via UDP
- `StatsDClient` methods match League StatsD 1:1: `increment`, `decrement`, `timing`, `gauge`, `set`
- No `histogram()` method — use `gauge()` for absolute values, `timing()` for distributions
- `HttpMetrics` and `PublishMetrics` have no `$service` parameter
- Tag name is `event_name` (not `event`) across all metrics
- **Consumer recovery is crash-and-restart** (since v8.0.0): `NanoConsumer::consume()` throws on AMQP failure → process exits non-zero → k8s `restartPolicy: Always` restarts the pod. NO in-process retry loop, NO `outageMode` for consumers. `NanoPublisher` keeps the in-process retry pattern (HTTP-FPM context).
- Heartbeat tuning via env: `AMQP_HEARTBEAT_SECONDS` (default 30), `AMQP_READ_WRITE_TIMEOUT_SECONDS` (default 60, clamped to ≥2× heartbeat), `AMQP_CONNECTION_TIMEOUT_SECONDS` (default 10), `AMQP_CONSUMER_INNER_WAIT_TIMEOUT_SECONDS` (default heartbeat/2). NEVER call `$channel->wait(null, false, 0)` — `timeout=0` makes php-amqplib skip `checkHeartBeat()` entirely; this was the 2026-05-14 e2e incident root cause.

---

## Checklist Before Changes

1. Backwards compatible?
2. New features opt-in (disabled by default)?
3. Metric tags bounded (enum values only)?
4. No new required ENV vars without major version?
5. Documentation updated?
6. Tests pass with metrics disabled AND enabled?

---

## Common Pitfalls

- Don't change existing metric names — breaks Grafana dashboards
- Don't add required ENV vars — breaks existing deployments
- Don't create channels in loops — causes channel exhaustion (see incident 2026-01-16)
- Don't throw new exception types — breaks existing error handling
- Don't enable features by default — services auto-update via composer

---

## Services Using nano-service

- easyweek-service-backend
- nanoservice-elasticsearch
- nanoservice-event2clickhouse
- (see devops/catalog/ownership.yaml for complete list)

---

## References

- [docs/CONFIGURATION.md](docs/CONFIGURATION.md) — all env vars
- [docs/METRICS.md](docs/METRICS.md) — all metrics with tags
- [docs/CHANGELOG.md](docs/CHANGELOG.md) — version history
- [docs/INTEGRATION.md](docs/INTEGRATION.md) — how to integrate
- Incident: `incidents/2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2`
