# Nano-Service

PHP library for event-driven microservices using RabbitMQ.

**Purpose:** Reliable event publishing and consuming between microservices with built-in outbox/inbox pattern, circuit breaker, idempotency, and observability.

## Installation

```bash
composer require yevhenlisovenko/nano-service:^7.4.3
```

## What It Does

- **Publisher** — sends events to RabbitMQ with automatic database fallback (outbox pattern)
- **Consumer** — receives events with retry logic, dead-letter queue, and idempotency (inbox pattern)
- **Circuit breaker** — automatic outage detection, graceful degradation, auto-recovery
- **Metrics** — opt-in StatsD metrics for publisher, consumer, and connection health
- **Connection pooling** — shared static connections/channels, prevents channel exhaustion

## Documentation

| Document | What's inside |
|----------|---------------|
| [docs/CONFIGURATION.md](docs/CONFIGURATION.md) | All environment variables (RabbitMQ, PostgreSQL, StatsD, Connection) |
| [docs/INTEGRATION.md](docs/INTEGRATION.md) | How to integrate as publisher or consumer, architecture rules |
| [docs/TRACE_USAGE.md](docs/TRACE_USAGE.md) | Distributed tracing examples, trace chain building with `appendTraceId()` |
| [docs/METRICS.md](docs/METRICS.md) | Metric names, tags, Prometheus queries, helper classes |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Kubernetes templates, GitLab CI, rollout strategy |
| [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | Common issues and solutions |
| [docs/CHANGELOG.md](docs/CHANGELOG.md) | Version history |

### Architecture Deep Dives

| Document | What's inside |
|----------|---------------|
| [docs/ARCHITECTURE_PUBLISHING_DEEP_DIVE.md](docs/ARCHITECTURE_PUBLISHING_DEEP_DIVE.md) | Publishing flow, outbox pattern, event tracing |
| [docs/ARCHITECTURE_CONSUMING_DEEP_DIVE.md](docs/ARCHITECTURE_CONSUMING_DEEP_DIVE.md) | Consuming flow, inbox pattern, circuit breaker |

### Development

| Document | What's inside |
|----------|---------------|
| [CLAUDE.md](CLAUDE.md) | AI/LLM development rules |
| [docs/development/CODE_REVIEW.md](docs/development/CODE_REVIEW.md) | Code review checklist |
| [docs/development/BUGFIXES.md](docs/development/BUGFIXES.md) | Known issues and fixes |

## License

MIT License
