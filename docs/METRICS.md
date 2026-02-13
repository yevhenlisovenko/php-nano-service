# Metrics

StatsD metrics for monitoring RabbitMQ operations. All metrics are opt-in (`STATSD_ENABLED=false` by default).

For env var setup, see [CONFIGURATION.md](CONFIGURATION.md).

---

## How It Works

nano-service sends metrics via UDP to statsd-exporter (DaemonSet, node-local), which exposes them on `:9102` for Prometheus scraping. statsd-exporter converts `timing` metrics into Prometheus histograms automatically.

Use `status.hostIP` for `STATSD_HOST` in Kubernetes.

---

## Metric Naming

All metrics follow the format: `{STATSD_NAMESPACE}.{metric_name}`

`STATSD_NAMESPACE` is the **project name** (e.g. `ew`), not the service name. Service identity is in the `nano_service_name` default tag (from `AMQP_MICROSERVICE_NAME`).

**Default tags** (auto-added to every metric):
- `nano_service_name` — from `AMQP_MICROSERVICE_NAME`
- `env` — from `APP_ENV` (defaults to `unknown`)

**Grafana tip:** Type `ew.` in the metric selector to autocomplete all project metrics.

---

## Publisher Metrics

Collected automatically on every `NanoPublisher::publish()` call.

| Metric | Type | Tags | Why |
|--------|------|------|-----|
| `rmq_publish_total` | Counter | `event_name` | Track publish volume per event type |
| `rmq_publish_success_total` | Counter | `event_name` | Track success rate, detect failures |
| `rmq_publish_error_total` | Counter | `event_name`, `error_type` | Classify publish failures by root cause |
| `rmq_publish_duration_ms` | Timing | `event_name` | Detect slow publishes, track P95/P99 latency |
| `rmq_payload_bytes` | Timing | `event_name` | Capacity planning, detect oversized payloads |

**Error types** (bounded enum): `connection_error`, `channel_error`, `timeout`, `encoding_error`, `config_error`, `unknown`

---

## Consumer Metrics

Collected automatically on every `NanoConsumer::consume()` call.

| Metric | Type | Tags | Why |
|--------|------|------|-----|
| `event_started_count` | Counter | `event_name`, `retry` | Track consumption volume, detect retry storms |
| `event_processed_duration` | Timing | `event_name`, `retry`, `status` | Processing time P95/P99, detect slow handlers |
| `event_processed_memory_bytes` | Gauge | `event_name`, `retry`, `status` | Detect memory-heavy handlers, prevent OOM |
| `rmq_consumer_payload_bytes` | Timing | `event_name` | Consumed message size distribution |
| `rmq_consumer_dlx_total` | Counter | `event_name`, `reason` | Dead-letter events — handler failures after max retries |
| `rmq_consumer_ack_failed_total` | Counter | `event_name` | ACK failures — connection lost during processing |

**Retry tags**: `first`, `retry`, `last`
**Status tags**: `success`, `failed`

Memory metric uses `memory_get_peak_usage(true)` with peak reset between events via `memory_reset_peak_usage()` (PHP 8.2+).

---

## Connection Health Metrics

Collected during connection/channel lifecycle.

| Metric | Type | Tags | Why |
|--------|------|------|-----|
| `rmq_connection_total` | Counter | `status` | Track connection open events |
| `rmq_connection_active` | Gauge | — | Should be 0 or 1. Growing = connection leak |
| `rmq_connection_errors_total` | Counter | `error_type` | Classify connection failures |
| `rmq_channel_total` | Counter | `status` | Track channel open events |
| `rmq_channel_active` | Gauge | — | Should be 0 or 1. Growing = channel leak |
| `rmq_channel_errors_total` | Counter | `error_type` | Classify channel failures |

---

## Connection Lifecycle Metrics

Collected when `CONNECTION_MAX_JOBS` is enabled.

| Metric | Type | Tags | Why |
|--------|------|------|-----|
| `rmq_consumer_connection_reinit_total` | Counter | `reason` | Track periodic reconnections |
| `rmq_consumer_connection_reinit_duration_ms` | Timing | — | Detect slow reconnections |

**Reason tags**: `max_jobs`

---

## HttpMetrics Helper

Drop-in replacement for manual StatsD middleware. Preserves old metric names (`incoming_request`, `http_request`) for Grafana dashboard compatibility, adds richer observability.

Constructor: `new HttpMetrics($statsd, $route, $method)`
Methods: `start()`, `setStatusCode()`, `recordError()`, `finish()`

| Metric | Type | Tags | When |
|--------|------|------|------|
| `incoming_request` | Counter | `method`, `route` | On `start()` — every request |
| `http_request` | Timing | `code`, `route`, `method` | On `finish()` — request duration |
| `http_response_status_total` | Counter | `route`, `method`, `status_code`, `status_class` | On `finish()` — status class distribution |
| `http_request_by_latency_bucket` | Counter | `route`, `latency_bucket` | On `finish()` — SLO tracking |
| `http_request_errors` | Counter | `route`, `error_reason` | On `recordError()` — only when exception occurs |

See source: [HttpMetrics.php](../src/Clients/StatsDClient/HttpMetrics.php)

---

## PublishMetrics Helper

For async job/message publishing. Tracks duration, memory, retries, and latency distribution.

Constructor: `new PublishMetrics($statsd, $eventName)`
Methods: `start()`, `recordSuccess()`, `recordFailure()`, `finish()`

| Metric | Type | Tags | When |
|--------|------|------|------|
| `publish_job_duration_ms` | Timing | `event_name`, `status` | On `finish()` — job processing duration |
| `publish_job_memory_mb` | Gauge | `event_name`, `status` | On `finish()` — memory used during job |
| `publish_job_processed` | Counter | `event_name`, `status` | On `finish()` — every job |
| `publish_job_retry_attempts` | Gauge | `event_name` | On `finish()` — only when `recordFailure()` was called |
| `publish_job_by_latency_bucket` | Counter | `event_name`, `latency_bucket` | On `finish()` — SLO tracking |

See source: [PublishMetrics.php](../src/Clients/StatsDClient/PublishMetrics.php)

---

## MetricsBuckets Utility

Static utility for consistent bucketing across all helper classes.

| Method | Values | Purpose |
|--------|--------|---------|
| `getHttpLatencyBucket()` | `fast_lt_10ms` → `critical_gt_1s` | HTTP SLO tracking |
| `getPublishLatencyBucket()` | `fast_lt_50ms` → `critical_gt_5s` | Async job SLO tracking |
| `getPayloadSizeCategory()` | `tiny_lt_1kb` → `huge_gt_10mb` | Capacity planning |
| `getStatusClass()` | `2xx`, `3xx`, `4xx`, `5xx`, `unknown` | HTTP SLI tracking |
| `normalizeContentType()` | `json`, `form_urlencoded`, `multipart`, `xml`, `plain_text`, `other`, `none` | API type distribution |
| `categorizeError()` | `database_error`, `timeout`, `disk_full`, `out_of_memory`, `rabbitmq_error`, `unknown` | Root cause analysis |

See source: [MetricsBuckets.php](../src/Clients/StatsDClient/MetricsBuckets.php)

---

## Tag Cardinality Rules

Safe tags (bounded): `nano_service_name`, `env`, `event_name`, `error_type`, `retry`, `status`, `reason`, `route`, `method`, `status_class`, `latency_bucket`

**NEVER** use as tags: `user_id`, `invoice_id`, `request_id`, UUID, timestamp — these cause Prometheus cardinality explosion.

---

## Prometheus Queries

| Query | Purpose |
|-------|---------|
| `rate(rmq_publish_total{nano_service_name="myservice"}[5m])` | Publish rate |
| `rate(rmq_publish_error_total{nano_service_name="myservice"}[5m])` | Error rate |
| `histogram_quantile(0.95, rate(rmq_publish_duration_ms_bucket{nano_service_name="myservice"}[5m]))` | Publish latency P95 |
| `rate(event_started_count_total{nano_service_name="myservice"}[5m])` | Event processing rate |
| `rmq_connection_active{nano_service_name="myservice"}` | Active connections (should be 0 or 1) |
| `rate(rmq_consumer_dlx_total{nano_service_name="myservice"}[5m])` | DLX rate |

Add `env="production"` to filter by environment.

---

## Performance Impact

| Configuration | CPU | Memory | Network |
|---------------|-----|--------|---------|
| Disabled (default) | 0% | 0 MB | 0 bytes |
| Enabled | 1-2% | 10-20 MB | ~50 KB/s |

All metrics are sent at 100% via UDP (fire-and-forget). No sampling overhead.
