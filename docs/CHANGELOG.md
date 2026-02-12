# Changelog

All notable changes to `nano-service` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [7.4.0] - 2026-02-12

### Added
- **Consumer Memory Tracking**: Automatic memory usage tracking for all consumed events
  - **Why**: Enables capacity planning and detection of memory leaks or inefficient handlers
  - **Metric**: `event_processed_memory_mb` (gauge, value × 100 for precision)
  - **Tags**: `nano_service_name`, `event_name`, `retry`, `status`
  - **Usage**: Divide by 100 in Prometheus queries to get actual MB value
  - **Example Query**: `avg(event_processed_memory_mb{nano_service_name="myservice"}) / 100`
  - **Zero Cost**: Only collected when `STATSD_ENABLED=true`, no overhead when disabled
  - **Affected**: `StatsDClient::start()` and `StatsDClient::end()` methods
  - **Benefits**:
    - ✅ Identify memory-hungry event handlers
    - ✅ Optimize pod memory requests/limits based on actual usage
    - ✅ Detect memory leaks over time
    - ✅ Plan resource allocation per service

### Documentation
- Updated [METRICS.md](METRICS.md) with `event_processed_memory_mb` metric details

---

## [7.3.0] - 2026-02-12

### Added
- **`STATSD_PROJECT` Environment Variable**: Project prefix for Grafana metric autocomplete (⭐ Recommended)
  - Enables easy metric discovery by typing project prefix (e.g., `ew_`) in Grafana
  - Defaults to `nano_service` if not set (backwards compatible)
  - Final metric format: `{STATSD_PROJECT}_{STATSD_NAMESPACE}.{metric_name}`
  - Example: `ew_myservice.rmq_publish_total`
  - See [CONFIGURATION.md](CONFIGURATION.md) and [METRICS.md](METRICS.md) for details
- **`getPrefix()` Method** in StatsDConfig: Returns the configured project prefix

### Changed
- **UUID v7 Migration**: Upgraded from UUID v4 to UUID v7 for better database performance
  - **Why**: UUID v7 is time-ordered (sortable), improving database indexing and query performance
  - **Impact**: New messages will use UUID v7, existing UUID v4 messages remain compatible
  - **Benefits**:
    - ✅ Better database index locality (sequential writes instead of random)
    - ✅ Sortable by creation time (timestamp embedded in UUID)
    - ✅ Reduces index fragmentation in high-throughput systems
    - ✅ Backwards compatible (can handle both v4 and v7)
  - **Affected**: `NanoServiceMessage::defaultProperty()`, `LoggerFactory::generateId()`
  - **Migration**: No action required - change is transparent and backwards compatible

### Documentation
- Updated [CONFIGURATION.md](CONFIGURATION.md) with `STATSD_PROJECT` details
- Updated [METRICS.md](METRICS.md) with metric naming convention examples
- Enhanced StatsDConfig docblock with prefix usage examples

---

## [7.2.0] - 2026-02-12

### Fixed
- **🚨 CRITICAL: Concurrent Processing Race Condition (Issue 1)**: Fixed atomic claim mechanism to prevent duplicate message processing
  - **Problem**: `existsInInbox()` bypass allowed concurrent processing when multiple workers received the same message (RabbitMQ redelivery during connection drops, pod restarts, heartbeat timeouts)
  - **Impact**: Without this fix, messages could be processed simultaneously by multiple workers, causing duplicate invoices, emails, payments, etc.
  - **Solution**: Implemented atomic row-level locking with `locked_at` and `locked_by` columns
  - **Breaking**: Requires `locked_at` and `locked_by` columns in inbox table (see migration below)
  - **Backwards Compatible**: New columns are nullable, existing deployments work without migration (but without concurrency protection)

### Added
- **`tryClaimInboxMessage()` Method** in EventRepository: Atomic claim mechanism for safe concurrent processing
  - Uses UPDATE with conditions to claim messages atomically
  - Only claims messages in 'failed' status or 'processing' with stale locks
  - Prevents race conditions during RabbitMQ redeliveries
- **`getWorkerId()` Method** in NanoConsumer: Returns worker identifier for locking
  - Uses `POD_NAME` (Kubernetes) if available, otherwise `hostname:pid`
  - Enables tracking which worker owns which message
- **`INBOX_LOCK_STALE_THRESHOLD` Environment Variable**: Configurable stale lock detection (default: 300 seconds = 5 minutes)
  - Determines when to consider a lock abandoned after worker crash
  - Should be greater than your longest message processing time

### Changed
- **`insertMessageToInbox()` Method**: Now uses atomic claim pattern instead of unsafe `existsInInbox()` check
  - First tries INSERT (for new messages)
  - On duplicate key, tries atomic CLAIM (for retries/redeliveries)
  - Only proceeds if INSERT succeeded OR CLAIM succeeded
  - Skips processing if row is actively locked by another worker
- **`insertInbox()` Method**: Added optional `$lockedBy` parameter for atomic locking
  - When provided, sets `locked_at=NOW()` and `locked_by=<worker_id>`
  - Backwards compatible - parameter is optional

### Database Migration Required

The inbox table requires two new columns for atomic locking:

```sql
-- Add locking columns
ALTER TABLE pg2event.inbox
ADD COLUMN IF NOT EXISTS locked_at TIMESTAMP DEFAULT NULL;

ALTER TABLE pg2event.inbox
ADD COLUMN IF NOT EXISTS locked_by VARCHAR(255) DEFAULT NULL;

-- Create index for stale lock detection
CREATE INDEX IF NOT EXISTS idx_inbox_locked_at ON pg2event.inbox(locked_at)
WHERE locked_at IS NOT NULL;

-- Create composite index for claim queries
CREATE INDEX IF NOT EXISTS idx_inbox_claim_lookup ON pg2event.inbox(message_id, consumer_service, status, locked_at);
```

**Note**: If using `public.inbox` schema instead of `pg2event.inbox`, replace schema name accordingly.

### Reference
- **Issue Analysis**: `/Users/begimov/Downloads/CONCURRENCY_ISSUES.md` - Detailed analysis of all 6 concurrency issues
- **Fixed**: Issue 1 - Concurrent Processing via `existsInInbox` Bypass (CRITICAL severity)
- **Configuration**: See [CONFIGURATION.md](CONFIGURATION.md) for `INBOX_LOCK_STALE_THRESHOLD` and `POD_NAME` details

---

## [7.1.0] - 2026-02-10

### Added
- **`appendTraceId()` Method**: Convenience method for building trace chains when creating callback/relay messages
  - Automatically appends message IDs to existing trace chain
  - Reduces manual array merging from 3 lines to 1 line (67% reduction)
  - Supports fluent interface for chaining
  - See [TRACE_USAGE.md](TRACE_USAGE.md) for examples

### Changed
- **Interface Contract Completeness**: Added missing method signatures to `NanoServiceMessageContract`
  - `getId()`, `setId()`, `setMessageId()`
  - `setTraceId()`, `getTraceId()`, `appendTraceId()`
  - `getEventName()`, `getPublisherName()`, `getRetryCount()`
  - Fixes IDE static analysis errors

### Removed
- **SystemPing Handler**: Removed deprecated `SystemHandlers/SystemPing.php` class
  - Was deprecated in v7.0.0 when `system.ping.1` event was removed
  - No active references in codebase
  - Cleaned up unused imports in `NanoConsumer` and test files

### Migration Example

```php
// Before (v7.0.0)
$parentTraceIds = $message->getTraceId();
$message->setTraceId(array_merge($parentTraceIds, [$newId]));

// After (v7.1.0)
$message->appendTraceId($newId);
```

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
