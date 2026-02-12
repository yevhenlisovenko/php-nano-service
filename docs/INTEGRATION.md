# Integration Guide

How to integrate nano-service as a publisher, consumer, or both in your microservice.

---

## Who Is This For

nano-service is designed for **Slim-based event-driven microservices** — NOT for Laravel monoliths.

Use cases:
- Background workers that consume events and process business logic
- Publishers that dispatch events from HTTP handlers or CLI commands
- Services that both publish and consume events

---

## Step 1: Install and Configure

```bash
composer require yevhenlisovenko/nano-service:^7.0
```

Set all required environment variables — see [CONFIGURATION.md](CONFIGURATION.md).

Each service MUST have its own PostgreSQL database and schema.

---

## Step 2: Set Up as Publisher

**Business purpose:** Send events to RabbitMQ so other services can react to them. Uses hybrid outbox pattern — publishes to RabbitMQ immediately, stores in database as fallback.

1. Create `NanoPublisher` instance once, reuse across all operations
2. Create `NanoServiceMessage` with payload
3. Call `publish()` with event name
4. Use `ensureConnectionOrSleep()` for circuit breaker in long-running workers
5. Set outage callbacks for logging

Architecture deep dive: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md)

---

## Step 3: Set Up as Consumer

**Business purpose:** Receive events from RabbitMQ, process them with retry logic and idempotency guarantees. Failed messages go to dead-letter queue after max retries.

1. Create `NanoConsumer` instance
2. Define events to listen to via `events()`
3. Set retry count via `tries()` and backoff via `backoff()`
4. Implement `consume()` callback for business logic
5. Implement `catch()` for retry handling and `failed()` for permanent failures
6. Consumer handles inbox idempotency automatically via `DB_BOX_SCHEMA`

Architecture deep dive: [ARCHITECTURE_CONSUMING_DEEP_DIVE.md](ARCHITECTURE_CONSUMING_DEEP_DIVE.md)

---

## Architecture Rules

### Service Structure

```
src/
├── Model/              # Eloquent models
├── Repository/         # ONLY place for database queries
├── Services/           # Business logic
├── Console/            # CLI commands (workers)
├── Action/             # Controller actions (single responsibility)
├── Controller/         # Thin controllers (delegate to Actions)
├── Request/            # Request validation
├── Response/           # Response DTOs
└── Factory/            # Object creation (Logger, connections)
```

### Mandatory Patterns

- **Idempotency** — every consumer MUST check `event_id` before processing (inbox pattern handles this)
- **Circuit breaker** — every publisher MUST use `ensureConnectionOrSleep()` in long-running loops
- **Fail-fast config** — NO fallback values for env vars, throw `RuntimeException` on missing
- **Structured logging** — use `LoggerFactory` with JSON output (Loki-compatible), context arrays, never string concat
- **`declare(strict_types=1)`** — every PHP file
- **`\Throwable` not `\Exception`** — catch errors too
- **`final` classes** — with interfaces for mocking in tests
- **Request → DTO → Action → Resource** — flow pattern for HTTP handlers

### Database Rules

- Each service has its own database and schema
- Event log table for idempotency tracking (status: pending/processing/completed/failed)
- 30-day retention with cleanup CronJob
- All queries in Repository classes only

---

## Metrics

Automatic metrics are collected when `STATSD_ENABLED=true` — no code changes needed.

For helper classes (`HttpMetrics`, `PublishMetrics`, `MetricsBuckets`) and custom metrics, see [METRICS.md](METRICS.md).

Key rule: always use try-finally pattern so metrics are recorded even on exceptions.

---

## Deployment Checklist

- [ ] All env vars set (see [CONFIGURATION.md](CONFIGURATION.md))
- [ ] Own database/schema created with outbox/inbox tables
- [ ] Circuit breaker implemented for publishers
- [ ] Idempotency via inbox pattern for consumers
- [ ] LoggerFactory with JSON output
- [ ] StatsD enabled and Grafana dashboard created
- [ ] Unit tests (>80% coverage) + resilience tests
- [ ] 30-day cleanup CronJob deployed

---

## Common Mistakes

- Using fallback values for env vars (`$_ENV['HOST'] ?? 'localhost'`)
- Database queries in controllers instead of repositories
- Missing idempotency check in consumers
- No circuit breaker in publisher loops (hammers DB during outage)
- String concatenation in logs instead of structured context arrays
- Running `composer` on host instead of Docker

---

## Support

- Example services: hook2event, provider_alphasms_v2
- Test suite: `tests/Unit/`
- Production issues: check Grafana dashboards first, then Loki logs
