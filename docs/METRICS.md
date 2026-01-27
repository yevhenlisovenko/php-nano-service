# nano-service Metrics Documentation

**Version:** 6.5+
**Last Updated:** 2026-01-27

---

## Overview

nano-service provides comprehensive StatsD metrics instrumentation for monitoring RabbitMQ operations, including:
- **Publisher metrics**: Publish rate, latency, errors, payload sizes
- **Consumer metrics**: Processing rate, latency, retries, DLX events, ACK failures
- **Connection health**: Connection and channel status, errors, lifecycle events
- **HTTP metrics helpers** (v6.5+): Ready-to-use helpers for HTTP request metrics
- **Job metrics helpers** (v6.5+): Ready-to-use helpers for async job processing metrics

All metrics are **opt-in by default** for safe production rollout.

---

## Quick Start

### 1. Enable Metrics

Set environment variables:

```bash
STATSD_ENABLED=true              # Required to enable metrics
STATSD_HOST=10.192.0.15          # StatsD server (use status.hostIP in k8s)
STATSD_PORT=8125                 # StatsD UDP port
STATSD_NAMESPACE=myservice       # Service name for metrics
```

### 2. Configure in Kubernetes

```yaml
env:
- name: STATSD_ENABLED
  value: "true"
- name: STATSD_HOST
  valueFrom:
    fieldRef:
      fieldPath: status.hostIP   # Node IP for DaemonSet deployment
- name: STATSD_PORT
  value: "8125"
- name: STATSD_NAMESPACE
  value: "myservice"
- name: APP_ENV
  value: "production"
```

### 3. Deploy statsd-exporter

Deploy statsd-exporter DaemonSet to your cluster:
- Listens on UDP 8125 (node-local)
- Exposes Prometheus metrics on TCP 9102
- See your DevOps team for infrastructure setup

---

## Configuration Reference

### Required Environment Variables (when STATSD_ENABLED=true)

| Variable | Description | Example | Required |
|----------|-------------|---------|----------|
| `STATSD_ENABLED` | Enable metrics collection | `true` or `false` | No (default: `false`) |
| `STATSD_HOST` | StatsD server host | `10.192.0.15` (node IP in k8s) | ✅ Yes |
| `STATSD_PORT` | StatsD server port | `8125` | ✅ Yes |
| `STATSD_NAMESPACE` | Metric namespace prefix | `myservice` | ✅ Yes |
| `STATSD_SAMPLE_OK` | Sampling rate for success metrics | `0.1` | ✅ Yes |
| `STATSD_SAMPLE_PAYLOAD` | Sampling rate for payload size metrics | `0.1` | ✅ Yes |

⚠️ **Important**: When `STATSD_ENABLED=true`, ALL the variables above are required. If any are missing, the application will throw a `RuntimeException` at startup with a message listing the missing variables.

### Optional Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_ENV` | Application environment tag | `production` |

### Sampling Rates Explained

Sampling reduces overhead by only sending a percentage of metrics:

- `1.0` = 100% (send all metrics)
- `0.1` = 10% (send 1 in 10 metrics)
- `0.01` = 1% (send 1 in 100 metrics)

**Note:** Error metrics are ALWAYS sent at 100% regardless of sampling configuration.

---

## Metrics Collected

### Publisher Metrics

Automatically collected when using `NanoPublisher::publish()`:

| Metric | Type | Tags | Description |
|--------|------|------|-------------|
| `rmq_publish_total` | Counter | `service`, `event`, `env` | Total publish attempts |
| `rmq_publish_success_total` | Counter | `service`, `event`, `env` | Successful publishes |
| `rmq_publish_error_total` | Counter | `service`, `event`, `env`, `error_type` | Failed publishes |
| `rmq_publish_duration_ms` | Histogram | `service`, `event`, `env` | Publish latency in milliseconds |
| `rmq_payload_bytes` | Histogram | `service`, `event`, `env` | Message payload size in bytes |

**Error Types (bounded set):**
- `connection_error` - Can't connect to RabbitMQ
- `channel_error` - Channel operation failed
- `timeout` - Publish timeout exceeded
- `encoding_error` - Message serialization failed
- `config_error` - Invalid configuration
- `unknown` - Uncategorized error (investigate & categorize)

### Consumer Metrics

Automatically collected when using `NanoConsumer::consume()`:

| Metric | Type | Tags | Description |
|--------|------|------|-------------|
| `event_started_count` | Counter | `nano_service_name`, `event_name`, `retry` | Event consumption started |
| `event_processed_duration` | Histogram | `nano_service_name`, `event_name`, `retry`, `status` | Event processing time in milliseconds |
| `rmq_consumer_payload_bytes` | Histogram | `nano_service_name`, `event_name` | Consumed message size in bytes |
| `rmq_consumer_dlx_total` | Counter | `nano_service_name`, `event_name`, `reason` | Dead-letter queue events |
| `rmq_consumer_ack_failed_total` | Counter | `nano_service_name`, `event_name` | Message acknowledgment failures |

**Retry Tags:**
- `first` - First attempt
- `retry` - Retry attempt (not first, not last)
- `last` - Final retry before DLX

**Status Tags:**
- `success` - Event processed successfully
- `failed` - Event processing failed

### Connection Health Metrics

Automatically collected during connection/channel lifecycle:

| Metric | Type | Tags | Description |
|--------|------|------|-------------|
| `rmq_connection_total` | Counter | `service`, `status` | Connection open events |
| `rmq_connection_active` | Gauge | `service` | Active connections (0 or 1) |
| `rmq_connection_errors_total` | Counter | `service`, `error_type` | Connection failures |
| `rmq_channel_total` | Counter | `service`, `status` | Channel open events |
| `rmq_channel_active` | Gauge | `service` | Active channels (0 or 1) |
| `rmq_channel_errors_total` | Counter | `service`, `error_type` | Channel failures |

---

## Usage Examples

### Basic Publisher

```php
use AlexFN\NanoService\NanoPublisher;
use AlexFN\NanoService\NanoServiceMessage;

$publisher = new NanoPublisher();
$message = new NanoServiceMessage();

$message->setPayload(['user_id' => 123, 'action' => 'created']);
$publisher->setMessage($message);

// Metrics automatically tracked:
// - rmq_publish_total
// - rmq_publish_success_total
// - rmq_publish_duration_ms
// - rmq_payload_bytes
$publisher->publish('user.created');
```

### Basic Consumer

```php
use AlexFN\NanoService\NanoConsumer;

$consumer = new NanoConsumer();
$consumer
    ->events('user.created', 'user.updated')
    ->tries(3)
    ->backoff(5)
    ->consume(function ($message) {
        // Process message
        // Metrics automatically tracked:
        // - event_started_count
        // - event_processed_duration
        // - rmq_consumer_payload_bytes
        echo "Processing: " . $message->getEventName() . "\n";
    });
```

### Handling Failures

```php
$consumer
    ->events('user.created')
    ->tries(3)
    ->catch(function ($exception, $message) {
        // Called on retry
        echo "Retry: " . $exception->getMessage() . "\n";
    })
    ->failed(function ($exception, $message) {
        // Called when max retries exceeded
        // Metrics tracked: rmq_consumer_dlx_total
        echo "Failed permanently: " . $exception->getMessage() . "\n";
    })
    ->consume($callback);
```

---

## Metrics Helper Classes (v6.5+)

### Overview

nano-service v6.5 introduces helper classes that simplify metrics collection for common use cases:

| Helper | Use Case | Key Features |
|--------|----------|--------------|
| `HttpMetrics` | HTTP request handling | Timing, memory, status codes, latency buckets, payload sizes |
| `PublishMetrics` | Job/message publishing | Timing, retries, provider extraction, latency buckets |
| `MetricsBuckets` | Utility functions | Bucketing, categorization, normalization |

These helpers encapsulate the **try-finally pattern** for guaranteed metrics recording.

---

### HttpMetrics

Simplifies HTTP request metrics collection with automatic timing and error handling.

```php
use AlexFN\NanoService\Clients\StatsDClient\HttpMetrics;
use AlexFN\NanoService\Clients\StatsDClient\StatsDClient;

$metrics = new HttpMetrics(new StatsDClient(), 'myservice', 'stripe', 'POST');
$metrics->start();

try {
    // Process HTTP request...
    $metrics->trackContentType($request->getContentType());
    $metrics->trackPayloadSize(strlen($body));
    return $response;

} catch (ValidationException $e) {
    $metrics->setStatus('validation_error', 422);
    throw $e;

} catch (\Exception $e) {
    $metrics->recordError($e, 500);
    throw $e;

} finally {
    $metrics->finish();  // Always records timing, memory, status codes
}
```

**Metrics recorded by HttpMetrics::finish():**

| Metric | Type | Description |
|--------|------|-------------|
| `http_request_duration_ms` | Timing | Request processing time |
| `http_request_memory_mb` | Gauge | Memory used during request |
| `http_request_total` | Counter | Total requests by status |
| `http_webhooks_received_by_provider` | Counter | Throughput by provider |
| `http_request_by_latency_bucket` | Counter | SLO tracking |
| `http_response_status_total` | Counter | HTTP status code distribution |

**Additional tracking methods:**

| Method | Metric | Description |
|--------|--------|-------------|
| `trackContentType($type)` | `http_request_by_content_type` | Content type distribution |
| `trackPayloadSize($bytes)` | `http_payload_size_bytes`, `http_payload_by_size_category` | Payload size analysis |
| `recordError($e, $code)` | `http_request_errors` | Error categorization |

---

### PublishMetrics

Simplifies async job/message publishing metrics with retry tracking.

```php
use AlexFN\NanoService\Clients\StatsDClient\PublishMetrics;
use AlexFN\NanoService\Clients\StatsDClient\StatsDClient;

$metrics = new PublishMetrics(new StatsDClient(), 'hook2event', $job->event_name);
$metrics->start();

try {
    // Publish message to RabbitMQ...
    $publisher->setMessage($message)->publish($event);
    $metrics->recordSuccess();

} catch (\Exception $e) {
    $metrics->recordFailure($job->attempts + 1);
    throw $e;

} finally {
    $metrics->finish();  // Always records timing, memory, latency buckets
}
```

**Metrics recorded by PublishMetrics::finish():**

| Metric | Type | Description |
|--------|------|-------------|
| `publish_job_duration_ms` | Timing | Publish operation time |
| `publish_job_memory_mb` | Gauge | Memory used during publish |
| `publish_job_processed` | Counter | Total jobs by status |
| `webhooks_published_by_provider` | Counter | Throughput by provider |
| `publish_job_by_latency_bucket` | Counter | SLO tracking |
| `publish_job_retry_attempts` | Gauge | Retry tracking (on failure) |

**Provider extraction:**

The helper automatically extracts provider from event name:
- `webhook.stripe` → provider: `stripe`
- `webhook.paypal` → provider: `paypal`
- Custom prefix via constructor: `new PublishMetrics($statsd, 'service', 'event.custom', 'event.')`

---

### MetricsBuckets

Utility class with static methods for consistent bucketing across all metrics.

```php
use AlexFN\NanoService\Clients\StatsDClient\MetricsBuckets;

// HTTP latency buckets (stricter thresholds for user-facing requests)
$bucket = MetricsBuckets::getHttpLatencyBucket(45.5);  // 'good_10_50ms'

// Publish latency buckets (relaxed thresholds for async operations)
$bucket = MetricsBuckets::getPublishLatencyBucket(250); // 'acceptable_100_500ms'

// Payload size categories
$category = MetricsBuckets::getPayloadSizeCategory(5120); // 'small_1_10kb'

// HTTP status class for SLI calculations
$class = MetricsBuckets::getStatusClass(201);  // '2xx'

// Content type normalization
$type = MetricsBuckets::normalizeContentType('application/json; charset=utf-8'); // 'json'

// Error categorization for root cause analysis
$reason = MetricsBuckets::categorizeError($exception);  // 'database_error', 'timeout', etc.

// Provider extraction from event name
$provider = MetricsBuckets::extractProvider('webhook.stripe');  // 'stripe'
```

**HTTP Latency Buckets:**

| Bucket | Threshold | Description |
|--------|-----------|-------------|
| `fast_lt_10ms` | < 10ms | Excellent performance |
| `good_10_50ms` | 10-50ms | Good performance |
| `acceptable_50_100ms` | 50-100ms | Acceptable for most use cases |
| `slow_100_500ms` | 100-500ms | Needs investigation |
| `very_slow_500ms_1s` | 500ms-1s | Performance issue |
| `critical_gt_1s` | > 1s | Critical - requires immediate attention |

**Publish Latency Buckets:**

| Bucket | Threshold | Description |
|--------|-----------|-------------|
| `fast_lt_50ms` | < 50ms | Excellent |
| `good_50_100ms` | 50-100ms | Good |
| `acceptable_100_500ms` | 100-500ms | Normal for async operations |
| `slow_500ms_1s` | 500ms-1s | Slow |
| `very_slow_1_5s` | 1-5s | Very slow |
| `critical_gt_5s` | > 5s | Critical |

**Payload Size Categories:**

| Category | Size Range | Description |
|----------|------------|-------------|
| `tiny_lt_1kb` | < 1 KB | Minimal payloads |
| `small_1_10kb` | 1-10 KB | Typical webhooks |
| `medium_10_100kb` | 10-100 KB | Large webhooks |
| `large_100kb_1mb` | 100 KB-1 MB | Very large payloads |
| `xlarge_1_10mb` | 1-10 MB | Oversized |
| `huge_gt_10mb` | > 10 MB | Potentially problematic |

**Error Categories:**

| Category | Detection Pattern |
|----------|-------------------|
| `database_error` | "database", "connection refused", "pdo" |
| `timeout` | "timeout", "timed out" |
| `disk_full` | "disk", "space", "no space left" |
| `out_of_memory` | "memory", "allowed memory" |
| `rabbitmq_error` | "rabbitmq", "amqp" |
| `redis_error` | "redis" |
| `unknown` | Default for uncategorized errors |

---

### Best Practices for Metrics Helpers

#### 1. Reuse StatsDClient Instance

**DO:** Create `StatsDClient` once and reuse it for all operations.

```php
// ✅ GOOD: Reuse StatsDClient in long-running workers
final class DispatcherCommand extends Command
{
    private StatsDClient $statsd;  // Reuse across all jobs

    protected function initialize(): void
    {
        $this->statsd = new StatsDClient();  // Create once
    }

    private function processJob(object $job): void
    {
        // Pass the shared instance
        $metrics = new PublishMetrics($this->statsd, 'myservice', $job->event_name);
        $metrics->start();
        try {
            // ... process job
            $metrics->recordSuccess();
        } catch (\Exception $e) {
            $metrics->recordFailure($job->attempts + 1);
            throw $e;
        } finally {
            $metrics->finish();
        }
    }

    private function updateBacklogMetrics(): void
    {
        // Reuse the same StatsDClient for gauges
        $this->statsd->gauge('db_backlog_jobs', $count, ['status' => 'pending']);
    }
}
```

**DON'T:** Create new `StatsDClient` for each operation.

```php
// ❌ BAD: Creates new client per job (wasteful)
private function processJob(object $job): void
{
    $metrics = new PublishMetrics(new StatsDClient(), 'myservice', $job->event_name);
    // ...
}

private function updateBacklogMetrics(): void
{
    $statsd = new StatsDClient();  // Another new client!
    // ...
}
```

**Why?** `StatsDClient` creates UDP socket connections. While lightweight, repeatedly creating new instances wastes resources and adds latency.

---

#### 2. HTTP Controllers: New Helper Per Request, Shared Client

For HTTP controllers, create helper per request but share the `StatsDClient`:

```php
// ✅ GOOD: Inject shared StatsDClient via DI container
final class HookJobController
{
    private StatsDClient $statsd;  // Injected via container

    public function __construct(StatsDClient $statsd)
    {
        $this->statsd = $statsd;
    }

    public function createPost(Request $request, Response $response): Response
    {
        // New HttpMetrics per request (tracks this specific request)
        // but uses shared StatsDClient
        $metrics = new HttpMetrics($this->statsd, 'hook2event', $service, 'POST');
        $metrics->start();

        try {
            // ... handle request
        } finally {
            $metrics->finish();
        }
    }
}
```

**Container configuration:**
```php
// container.php - Register StatsDClient as singleton
$container->set(StatsDClient::class, function () {
    return new StatsDClient();
});
```

---

#### 3. Worker Pattern: Initialize Once, Process Many

For background workers that process jobs in a loop:

```php
final class DispatcherCommand extends Command
{
    private StatsDClient $statsd;
    private NanoPublisher $publisher;

    protected function initialize(): void
    {
        // Initialize once at startup
        $this->statsd = new StatsDClient();
        $this->publisher = new NanoPublisher();
    }

    protected function execute(): int
    {
        while (true) {
            // Health check before processing
            if (!$this->isRabbitMQHealthy()) {
                $this->handleOutage();
                continue;
            }

            foreach ($this->fetchJobs() as $job) {
                $this->processJob($job);
            }
        }
    }

    private function handleOutage(): void
    {
        // Only recreate on outage - not on every iteration!
        $this->publisher = new NanoPublisher();
        sleep($this->outageSleepSeconds);
    }
}
```

---

#### 4. Always Use Try-Finally Pattern

**CRITICAL:** Always call `finish()` in a `finally` block to ensure metrics are recorded even on exceptions:

```php
// ✅ CORRECT: Metrics always recorded
$metrics = new PublishMetrics($this->statsd, 'service', $event);
$metrics->start();
try {
    $this->publisher->publish($event);
    $metrics->recordSuccess();
} catch (\Exception $e) {
    $metrics->recordFailure($attempts);
    throw $e;  // Re-throw after recording
} finally {
    $metrics->finish();  // ALWAYS executed
}

// ❌ WRONG: Metrics lost on exception
$metrics->start();
$this->publisher->publish($event);
$metrics->recordSuccess();
$metrics->finish();  // Never reached if publish() throws!
```

---

#### 5. Match Helper to Use Case

| Use Case | Helper | Why |
|----------|--------|-----|
| HTTP endpoints | `HttpMetrics` | Tracks status codes, content types, payload sizes |
| Job publishing | `PublishMetrics` | Tracks retries, provider, publish latency |
| Job consuming | Use raw `StatsDClient` with `start()`/`end()` | Consumer has its own tracking |
| Custom metrics | Raw `StatsDClient` methods | `increment()`, `gauge()`, `timing()` |

---

#### 6. Error Handling: Don't Break Business Logic

Metrics should never break your application:

```php
// ✅ GOOD: Metrics failure is silent
private function updateBacklogMetrics(): void
{
    try {
        $this->statsd->gauge('db_backlog_jobs', $count, ['status' => 'pending']);
    } catch (\Exception $e) {
        // Log but don't break the worker
        $this->logger->debug('Metrics update failed', ['error' => $e->getMessage()]);
    }
}

// ❌ BAD: Metrics failure crashes worker
private function updateBacklogMetrics(): void
{
    $this->statsd->gauge('db_backlog_jobs', $count, ['status' => 'pending']);
    // Exception propagates and kills the worker!
}
```

---

#### 7. Memory-Efficient Batch Processing

For batch operations, use shared helper:

```php
// ✅ GOOD: Single StatsDClient for batch
$statsd = $this->statsd;
foreach ($jobs as $job) {
    $metrics = new PublishMetrics($statsd, 'service', $job->event_name);
    $metrics->start();
    try {
        // process...
        $metrics->recordSuccess();
    } finally {
        $metrics->finish();
    }
}
```

---

## Metric Naming Convention

All nano-service metrics follow this pattern:

**Format:** `<namespace>.<metric_name>`

**Examples:**
- `myservice.rmq_publish_total`
- `myservice.rmq_connection_active`
- `myservice.event_processed_duration`

**Tag Format:** `key=value` pairs
- `service=myservice`
- `event=user.created`
- `env=production`
- `error_type=channel_error`

---

## Prometheus Integration

### Metric Names in Prometheus

StatsD metrics are converted to Prometheus format:

| StatsD Metric | Prometheus Metric |
|---------------|-------------------|
| `rmq_publish_total` | `rabbitmq_publish_total` |
| `rmq_publish_duration_ms` | `rabbitmq_publish_duration_milliseconds_bucket` |
| `rmq_payload_bytes` | `rabbitmq_payload_bytes_bucket` |
| `event_processed_duration` | `rabbitmq_event_processed_duration_milliseconds_bucket` |
| `rmq_connection_active` | `rabbitmq_connection_active` |

### Example Prometheus Queries

**Publish rate:**
```promql
rate(rabbitmq_publish_total{service="myservice"}[5m])
```

**Publish error rate:**
```promql
rate(rabbitmq_publish_error_total{service="myservice"}[5m])
```

**Publish latency p95:**
```promql
histogram_quantile(0.95,
  rate(rabbitmq_publish_duration_milliseconds_bucket{service="myservice"}[5m])
)
```

**Event processing rate:**
```promql
rate(rabbitmq_event_started_total{nano_service_name="myservice"}[5m])
```

**Active connections:**
```promql
rabbitmq_connection_active{service="myservice"}
```

**DLX rate:**
```promql
rate(rabbitmq_consumer_dlx_total{nano_service_name="myservice"}[5m])
```

---

## Performance Impact

### Overhead Estimates

| Configuration | CPU Overhead | Memory Overhead | Network |
|---------------|--------------|-----------------|---------|
| Disabled (default) | 0% | 0 MB | 0 bytes |
| Enabled (100% sampling) | 1-2% | 10-20 MB | ~50 KB/s |
| Enabled (10% sampling) | <0.5% | 10-20 MB | ~5 KB/s |

### Optimization Recommendations

**High-volume services (>1000 events/sec):**
```bash
STATSD_SAMPLE_OK=0.1      # 10% sampling for success metrics
STATSD_SAMPLE_PAYLOAD=0.1  # 10% sampling for payload sizes
```

**Medium-volume services (100-1000 events/sec):**
```bash
STATSD_SAMPLE_OK=1.0       # 100% sampling
STATSD_SAMPLE_PAYLOAD=0.1  # 10% sampling for payload sizes
```

**Low-volume services (<100 events/sec):**
```bash
STATSD_SAMPLE_OK=1.0       # 100% sampling (no need to reduce)
STATSD_SAMPLE_PAYLOAD=1.0  # 100% sampling
```

---

## Advanced Configuration

### Programmatic Configuration

Instead of environment variables, you can configure programmatically:

```php
use AlexFN\NanoService\Config\StatsDConfig;
use AlexFN\NanoService\Clients\StatsDClient\StatsDClient;

$config = new StatsDConfig([
    'enabled' => true,
    'host' => '10.192.0.15',
    'port' => 8125,
    'namespace' => 'myservice',
    'sampling' => [
        'ok_events' => 0.1,
        'error_events' => 1.0,
        'latency' => 1.0,
        'payload' => 0.1,
    ]
]);

$statsD = new StatsDClient($config);
```

### Custom Metrics

You can send custom metrics using StatsDClient directly:

```php
// In NanoPublisher or NanoConsumer
$this->statsD->increment('custom_metric', ['tag' => 'value']);
$this->statsD->timing('custom_duration_ms', 150, ['tag' => 'value']);
$this->statsD->gauge('custom_gauge', 42, ['tag' => 'value']);
$this->statsD->histogram('custom_histogram', 100, ['tag' => 'value']);
```

---

## Backwards Compatibility

### Existing Code Works Unchanged

**Before metrics (v5.x):**
```php
$consumer = new NanoConsumer();
$consumer->events('user.created')->consume($callback);
```

**After metrics (v6.x):**
```php
// Same code, metrics automatically collected!
$consumer = new NanoConsumer();
$consumer->events('user.created')->consume($callback);
```

### Migration from v5.x to v6.x

**No code changes required!**

1. Update dependency: `composer update yevhenlisovenko/nano-service`
2. Metrics are **disabled by default** (safe)
3. Enable when ready with `STATSD_ENABLED=true`

---

## Architecture

### Metrics Flow

```
┌─────────────────────┐
│   nano-service      │
│   (Publisher/       │
│    Consumer)        │
└──────────┬──────────┘
           │ UDP 8125
           ↓
┌─────────────────────┐
│  statsd-exporter    │
│  (DaemonSet)        │
│  - Receives UDP     │
│  - Exposes :9102    │
└──────────┬──────────┘
           │ HTTP scrape
           ↓
┌─────────────────────┐
│    Prometheus       │
│  - Stores metrics   │
│  - 14d retention    │
└──────────┬──────────┘
           │ Query
           ↓
┌─────────────────────┐
│     Grafana         │
│  - Dashboards       │
│  - Alerts           │
└─────────────────────┘
```

### Component Responsibilities

**nano-service:**
- Collect metrics during RabbitMQ operations
- Send StatsD UDP packets to node-local exporter
- Apply sampling configuration
- Categorize errors

**statsd-exporter:**
- Receive StatsD UDP packets
- Aggregate metrics
- Convert to Prometheus format
- Expose HTTP endpoint

**Prometheus:**
- Scrape statsd-exporter endpoints
- Store time-series data
- Provide query interface

**Grafana:**
- Visualize metrics
- Create dashboards
- Configure alerts

---

## Best Practices

### 1. Use Bounded Event Names

Avoid high-cardinality event names:

✅ **Good:**
```php
$publisher->publish('user.created');
$publisher->publish('invoice.paid');
$publisher->publish('order.shipped');
```

❌ **Bad (high cardinality):**
```php
$publisher->publish("user.{$userId}.created");  // Unique per user!
$publisher->publish("invoice.{$invoiceId}.paid");  // Millions of unique metrics!
```

### 2. Set Appropriate Sampling

**High-volume events (>1000/sec):**
```bash
STATSD_SAMPLE_OK=0.01  # 1% sampling
```

**Medium-volume events (100-1000/sec):**
```bash
STATSD_SAMPLE_OK=0.1   # 10% sampling
```

**Low-volume events (<100/sec):**
```bash
STATSD_SAMPLE_OK=1.0   # 100% sampling
```

### 3. Monitor Metrics System Health

Watch for:
- High packet drop rate in statsd-exporter
- Prometheus scrape failures
- High cardinality warnings in Prometheus

### 4. Tag Naming Convention

Use lowercase with underscores:
- ✅ `service`, `event_name`, `error_type`
- ❌ `Service`, `eventName`, `ErrorType`

### 5. Avoid High-Cardinality Tags

Never use as tags:
- User IDs
- Invoice IDs
- Request IDs
- Timestamps
- UUIDs

These should be in log messages, not metric tags.

---

## Example: Kubernetes Deployment

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: myservice
spec:
  template:
    spec:
      containers:
      - name: app
        image: myservice:latest
        env:
        # RabbitMQ config (existing)
        - name: AMQP_HOST
          value: "rabbitmq.internal"
        - name: AMQP_MICROSERVICE_NAME
          value: "myservice"
        - name: AMQP_PUBLISHER_ENABLED
          value: "true"

        # StatsD metrics (NEW)
        - name: STATSD_ENABLED
          value: "true"
        - name: STATSD_HOST
          valueFrom:
            fieldRef:
              fieldPath: status.hostIP  # Node IP
        - name: STATSD_PORT
          value: "8125"
        - name: STATSD_NAMESPACE
          value: "myservice"
        - name: STATSD_SAMPLE_OK
          value: "0.1"  # 10% sampling
        - name: APP_ENV
          value: "production"
```

---

## FAQ

### Q: Are metrics enabled by default?

**A:** No. Metrics are **disabled by default** (`STATSD_ENABLED=false`). You must explicitly enable them.

### Q: What happens if STATSD_ENABLED=false?

**A:** All metric calls become no-ops (early return). Zero overhead, zero network traffic.

### Q: What happens if StatsD server is unreachable?

**A:** UDP packets are sent but dropped silently. The application continues normally with no errors.

### Q: Do metrics affect application performance?

**A:** Minimal impact (<1% CPU with 10% sampling, <2% with 100% sampling). UDP is non-blocking.

### Q: Can I use this with existing statsd-exporter sidecar?

**A:** Yes! Just set `STATSD_HOST=127.0.0.1` to send to localhost sidecar instead of DaemonSet.

### Q: How do I disable metrics after enabling?

**A:** Set `STATSD_ENABLED=false` or remove the ENV variable entirely. No code changes needed.

### Q: What about backwards compatibility with v5.x consumers?

**A:** 100% compatible. Existing consumers work without any changes. New metrics are opt-in.

---

## Version History

### v6.5 (2026-01-27)
- ✨ Added `HttpMetrics` helper class for HTTP request metrics
- ✨ Added `PublishMetrics` helper class for job publishing metrics
- ✨ Added `MetricsBuckets` utility class for consistent bucketing
- ✨ HTTP latency buckets with SLO-focused thresholds
- ✨ Publish latency buckets for async operations
- ✨ Payload size categorization
- ✨ Error categorization for root cause analysis
- ✨ Content type normalization
- ✨ Provider extraction from event names
- ✅ 100% backwards compatible

### v6.0 (2026-01-19)
- ✨ Added StatsDConfig for centralized configuration
- ✨ Added PublishErrorType enum for error categorization
- ✨ Added publisher metrics (5 new metrics)
- ✨ Enhanced consumer metrics (3 new metrics)
- ✨ Added connection health metrics (6 new metrics)
- ✨ Added configurable sampling support
- ✨ Metrics disabled by default (opt-in)
- ✅ 100% backwards compatible with v5.x

### v5.x
- Basic consumer metrics (event_started_count, event_processed_duration)

---

---

## Related Documentation

- [Configuration Guide](CONFIGURATION.md)
- [Deployment Guide](DEPLOYMENT.md)
- [Troubleshooting Guide](TROUBLESHOOTING.md)
- [Changelog](CHANGELOG.md)
