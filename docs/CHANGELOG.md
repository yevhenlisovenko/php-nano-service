# Changelog

All notable changes to `nano-service` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [7.5.0] - 2026-02-13

### Changed
- **Simplified `StatsDConfig`**: Removed overcomplicated env var handling (212 → 87 lines)
  - Removed `STATSD_PROJECT` env var and `getPrefix()` — namespace is now the project name directly
  - `STATSD_NAMESPACE` is the project name (e.g. `ew`), not the service name
  - Metric format changed: `{STATSD_NAMESPACE}.{metric_name}` (was `{STATSD_PROJECT}_{STATSD_NAMESPACE}`)
  - All env vars are required when enabled — no fallback defaults
- **Default tags via League StatsD**: `nano_service_name` (from `AMQP_MICROSERVICE_NAME`) is now a default tag on all metrics
  - Removed manual `nano_service_name` tag from all metric calls in `NanoConsumer` and `MessageValidator`
  - Tag is automatically appended by the StatsD client to every metric
- **Standardized tag name**: Renamed `event` tag to `event_name` across `NanoPublisher` and `PublishMetrics` for consistency with `NanoConsumer`
- **Fixed `timing()` and `histogram()` signatures**: Removed `$sampleRate` parameter (League StatsD `timing()` doesn't support it — was silently ignored)
- **Memory metric improved**: `event_processed_memory_bytes` now uses `memory_get_peak_usage(true)` with `memory_reset_peak_usage()` (PHP 8.2+) for accurate per-event peak tracking. Changed from histogram to gauge (absolute value, not distribution).

### Removed
- `STATSD_PROJECT` environment variable (use `STATSD_NAMESPACE` as project name)
- `StatsDConfig::getPrefix()` method

### Migration
- Rename `STATSD_PROJECT` to `STATSD_NAMESPACE` if you were using both (or just set `STATSD_NAMESPACE` to your project name)
- If you called `StatsDConfig::getPrefix()`, use `getNamespace()` instead
- If you passed `$sampleRate` to `$statsD->timing()` or `$statsD->histogram()`, remove it (was ignored anyway)
- Grafana dashboards: update metric queries from `ew_myservice.` to `ew.` format
- Grafana dashboards: update tag filters from `event=` to `event_name=`

---

## [7.4.4] - 2026-02-13

### Fixed
- **StatsD Histogram Method Call**: Fixed incorrect method call in `StatsDClient::end()`
  - Changed `$this->statsd->histogram()` to `$this->histogram()` for `event_processed_memory_bytes` metric
  - Ensures proper metric handling through the wrapper method (includes enabled checks and tag formatting)
  - Affected: `StatsDClient::end()` method

---

## [7.4.3] - 2026-02-13

### Fixed
- **Memory Metric Bug**: Fixed `event_processed_memory_bytes` metric calculation
  - Resolved incorrect memory usage reporting in StatsD metrics
  - Ensures accurate memory tracking for consumed events

---

## [7.4.2] - 2026-02-13

### Fixed
- **🚨 CRITICAL: Retry Messages Silently Dropped**: Fixed inbox lock not being released when republishing for retry
  - **Problem**: When a message failed and was republished for retry, the inbox lock (`locked_at`, `locked_by`) was not released. When the retry message arrived (e.g., 10 seconds later), `tryClaimInboxMessage()` rejected it because the lock was not stale yet (threshold: 300 seconds). The retry message was ACK'd and lost, causing messages to get only 1 attempt instead of the configured 3 retries.
  - **Impact**: All retry messages with backoff delay < `INBOX_LOCK_STALE_THRESHOLD` (default 300s) were silently dropped. Messages stuck in `processing` status in inbox table, gone from RabbitMQ.
  - **Solution**: `updateInboxRetryCount()` now clears `locked_at` and `locked_by` when updating retry count, allowing retry messages to be claimed immediately.
  - **Affected**: `EventRepository::updateInboxRetryCount()` - now releases lock on retry
  - **Migration**: Messages currently stuck in `processing` status need manual cleanup (see below)

### Migration for Stuck Messages

If you have messages stuck in `processing` status from the bug, run this cleanup query **after deploying v7.4.2**:

```sql
-- Find stuck messages (older than 1 hour)
SELECT message_id, consumer_service, event_name, locked_at
FROM inbox
WHERE status = 'processing'
  AND locked_at < NOW() - INTERVAL '1 hour';

-- Mark as failed (recommended - preserves history)
UPDATE inbox
SET status = 'failed',
    last_error = 'Zombie from v7.2.0-v7.4.1 retry bug - manually cleaned after v7.4.2 fix',
    locked_at = NULL,
    locked_by = NULL
WHERE status = 'processing'
  AND locked_at < NOW() - INTERVAL '1 hour';
```

**Note**: Only run cleanup **after** deploying the fix, otherwise new messages will continue to get stuck.

---

## [7.4.1] - 2026-02-12

### Fixed
- **Memory Tracking Bug**: Initialize `$startMemory = 0` to prevent uninitialized property error
  - Without initialization, accessing the property before `start()` is called would cause fatal error
  - Now safely defaults to 0 if StatsD is disabled or start() is not called

---

## [7.4.0] - 2026-02-12

### Added
- **Consumer Memory Tracking**: Automatic memory usage tracking for all consumed events
  - **Why**: Enables capacity planning and detection of memory leaks or inefficient handlers
  - **Metric**: `event_processed_memory_bytes` (gauge, raw bytes)
  - **Tags**: `nano_service_name`, `event_name`, `retry`, `status`
  - **Usage**: Use Prometheus unit conversion for display (e.g., `/1024/1024` for MB)
  - **Example Query**: `event_processed_memory_bytes{nano_service_name="myservice"} / 1024 / 1024`
  - **Zero Cost**: Only collected when `STATSD_ENABLED=true`, no overhead when disabled
  - **Affected**: `StatsDClient::start()` and `StatsDClient::end()` methods
  - **Benefits**:
    - ✅ Identify memory-hungry event handlers
    - ✅ Optimize pod memory requests/limits based on actual usage
    - ✅ Detect memory leaks over time
    - ✅ Plan resource allocation per service

### Documentation
- Updated [METRICS.md](METRICS.md) with `event_processed_memory_bytes` metric details

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
