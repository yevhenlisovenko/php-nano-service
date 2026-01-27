# Changelog

All notable changes to `nano-service` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [Unreleased]

### Fixed
- **PHP 8.4**: Fixed deprecation warning for nullable `$debugCallback` parameter in `NanoConsumer` interface (2026-01-22)
- **CRITICAL**: Removed duplicate `$statsD` property declaration in `NanoConsumer` class (2026-01-20)
- **StatsDConfig**: Fixed validation running when full array config provided (2026-01-20)
- **PHP 8.4**: Fixed deprecation warning in `NanoServiceMessage::getTimestampWithMs()` (2026-01-20)

### Added
- Comprehensive test coverage for StatsD metrics (202 tests, 375 assertions)

---

## [6.5.0] - 2026-01-27

### Added
- `HttpMetrics` helper class for HTTP request metrics
- `PublishMetrics` helper class for job publishing metrics
- `MetricsBuckets` utility class for consistent bucketing
- HTTP latency buckets with SLO-focused thresholds
- Publish latency buckets for async operations
- Payload size categorization
- Error categorization for root cause analysis

---

## [6.0.0] - 2026-01-19

### Added
- `StatsDConfig` for centralized configuration
- `PublishErrorType` enum for error categorization
- Publisher metrics (5 new metrics)
- Enhanced consumer metrics (3 new metrics)
- Connection health metrics (6 new metrics)
- Configurable sampling support
- Metrics disabled by default (opt-in)

### Changed
- Metrics are now opt-in (`STATSD_ENABLED=false` by default)

---

## [5.x.x+1] - 2026-01-16

### Fixed - Channel Leak (CRITICAL)

**Incident:** `2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2`

#### Problem
RabbitMQ connections were accumulating thousands of channels, eventually hitting the 2,047 channel limit and causing "No free channel ids" errors.

**Root Cause:** The `getChannel()` method created new channels for each instance but never stored them in the shared channel pool (`self::$sharedChannel`).

**Impact in Production:**
- provider-easyweek-live: 2,047 channels per connection (maxed out)
- provider-sendgrid-live: 2,047 channels per connection (maxed out)
- Total cluster: 17,840 channels across 135 connections

#### Solution

**Fixed `NanoServiceClass::getChannel()`:**
```php
public function getChannel()
{
    if (self::$sharedChannel && self::$sharedChannel->is_open()) {
        return self::$sharedChannel;
    }

    if (! $this->channel || !$this->channel->is_open()) {
        $this->channel = $this->getConnection()->channel();
        self::$sharedChannel = $this->channel;  // FIX: Store for reuse
    }

    return $this->channel;
}
```

**Added destructor for safety:**
```php
public function __destruct()
{
    if ($this->channel
        && $this->channel !== self::$sharedChannel
        && method_exists($this->channel, 'is_open')
        && $this->channel->is_open()
    ) {
        try {
            $this->channel->close();
        } catch (\Throwable $e) {
            // Suppress errors during shutdown
        }
    }
}
```

#### Results
- **97% reduction** in channel count (17,840 → ~500)
- Channels per connection: 3-6 (down from 56-2,047)
- No "No free channel ids" errors

#### Migration
No code changes required. Update dependency:
```bash
composer update yevhenlisovenko/nano-service
```

---

## [5.x] - Previous

- Basic consumer metrics (event_started_count, event_processed_duration)
- RabbitMQ publisher and consumer classes
- Message signing and verification
- Retry mechanism with exponential backoff

---

## References

- [Metrics Documentation](METRICS.md)
- [Configuration Guide](CONFIGURATION.md)
- [Troubleshooting Guide](TROUBLESHOOTING.md)
