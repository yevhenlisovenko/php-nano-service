# Nano-Service

PHP library for event-driven microservices using RabbitMQ.

Reliable event publishing and consuming with outbox/inbox pattern, circuit breaker, idempotency, and observability.

## Installation

```bash
composer require yevhenlisovenko/nano-service:^8
```

## Features

- **Publisher** — events to RabbitMQ with database fallback (outbox pattern). In-process circuit breaker for HTTP/CLI contexts.
- **Consumer** — events with retry logic, dead-letter queue, idempotency (inbox pattern). Crash-and-restart recovery model (k8s `restartPolicy: Always`) — see [v8.0.0 CHANGELOG](docs/CHANGELOG.md#800---2026-05-14).
- **Metrics** — opt-in StatsD metrics for publisher, consumer, HTTP, and connections
- **Connection pooling** — shared static connections/channels, prevents channel exhaustion
- **Distributed tracing** — trace_id chains across event hops

## Documentation

| Document | Description |
|----------|-------------|
| [CONFIGURATION.md](docs/CONFIGURATION.md) | All environment variables |
| [METRICS.md](docs/METRICS.md) | All metrics, tags, and when they fire |
| [INTEGRATION.md](docs/INTEGRATION.md) | How to integrate as publisher or consumer |
| [TRACE_USAGE.md](docs/TRACE_USAGE.md) | Distributed tracing with `appendTraceId()` |
| [LOGGING_STANDARDS.md](docs/LOGGING_STANDARDS.md) | Structured logging schema for observability |
| [DEPLOYMENT.md](docs/DEPLOYMENT.md) | Kubernetes templates and rollout strategy |
| [TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | Common issues and solutions |
| [CHANGELOG.md](docs/CHANGELOG.md) | Version history and migration guides |

### Architecture

| Document | Description |
|----------|-------------|
| [Publishing Deep Dive](docs/ARCHITECTURE_PUBLISHING_DEEP_DIVE.md) | Outbox pattern, event tracing, error handling |
| [Consuming Deep Dive](docs/ARCHITECTURE_CONSUMING_DEEP_DIVE.md) | Inbox pattern, crash-and-restart recovery, retry logic |

### Development

| Document | Description |
|----------|-------------|
| [CLAUDE.md](CLAUDE.md) | LLM development rules |
| [Code Review](docs/development/CODE_REVIEW.md) | Code review checklist |
| [Bug Fixes](docs/development/BUGFIXES.md) | Known issues and fixes |

## License

MIT License
