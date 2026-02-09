# Changelog

All notable changes to `nano-service` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [7.0.0] - 2026-02-09

### Added
- **PostgreSQL Outbox/Inbox Pattern**: Hybrid publish strategy — immediate RabbitMQ publish with database fallback for reliability
- **EventRepository**: Singleton PDO-based repository for outbox/inbox operations with retry logic and exponential backoff
- **Consumer Circuit Breaker**: Automatic outage detection with configurable sleep, graceful degradation on RabbitMQ/database failures
- **MessageValidator**: Extracted message validation to a dedicated class
- **Event Tracing**: Distributed tracing via `trace_id` system attribute stored in PostgreSQL
- **Inbox Idempotency**: Duplicate event prevention at the library level using inbox pattern
- **Connection Lifecycle Management**: `CONNECTION_MAX_JOBS` env variable to auto-reconnect after N messages
- **Error Tracking Metrics**: `ConsumerErrorType` and `OutboxErrorType` enums for granular error categorization
- **Loki-Compatible Logging**: JSON-structured logging via updated `LoggerFactory`
- **HttpMetrics** helper class for HTTP request metrics
- **PublishMetrics** helper class for job publishing metrics
- **MetricsBuckets** utility class for consistent bucketing (latency, payload size, error categorization)
- Comprehensive test coverage (EventRepository, NanoConsumer, NanoPublisher, NanoServiceMessage)

### Changed
- Publisher now uses hybrid outbox pattern: publish to RabbitMQ immediately, store in database as fallback
- Consumer refactored with two-phase initialization (safe components + RabbitMQ)
- `AMQP_PUBLISHER_ENABLED` flag and related logic removed — publisher is always available
- `system.ping.1` event removed
- Message `id` is now required

### Required Environment Variables (New)

#### PostgreSQL (Required for outbox/inbox)

| Variable | Description | Example |
|----------|-------------|---------|
| `DB_BOX_HOST` | PostgreSQL host | `postgres.internal` |
| `DB_BOX_PORT` | PostgreSQL port | `5432` |
| `DB_BOX_NAME` | Database name | `nanoservice-myservice` |
| `DB_BOX_USER` | Database username | `myservice` |
| `DB_BOX_PASS` | Database password | `secret` |
| `DB_BOX_SCHEMA` | PostgreSQL schema | `myservice` |

---

## References

- [Metrics Documentation](METRICS.md)
- [Configuration Guide](CONFIGURATION.md)
- [Troubleshooting Guide](TROUBLESHOOTING.md)
