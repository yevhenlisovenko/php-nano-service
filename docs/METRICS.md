# Metrics

StatsD metrics for monitoring RabbitMQ operations. All metrics are opt-in (`STATSD_ENABLED=false` by default).

For env var setup, see [CONFIGURATION.md](CONFIGURATION.md).

## Metric Naming Convention

All metrics follow the format: `{STATSD_NAMESPACE}.{metric_name}`

`STATSD_NAMESPACE` is the **project name** (e.g. `ew`), not the service name. Service identity is in the `nano_service_name` default tag.

**Example:**
```bash
STATSD_NAMESPACE=ew            # Project name
```

**Result:** `ew.rmq_publish_total`, `ew.event_started_count`

**Grafana tip:** Type `ew.` in the metric selector to autocomplete all metrics for the "ew" project.

---

## Publisher Metrics

Collected automatically on every `NanoPublisher::publish()` call.

| Metric | Type | Tags | Description |
|--------|------|------|-------------|
| `rmq_publish_total` | Counter | `service`, `event_name`, `env` | Total publish attempts |
| `rmq_publish_success_total` | Counter | `service`, `event_name`, `env` | Successful publishes |
| `rmq_publish_error_total` | Counter | `service`, `event_name`, `env`, `error_type` | Failed publishes |
| `rmq_publish_duration_ms` | Histogram | `service`, `event_name`, `env` | Publish latency (ms) |
| `rmq_payload_bytes` | Histogram | `service`, `event_name`, `env` | Message payload size (bytes) |

**Error types** (bounded enum): `connection_error`, `channel_error`, `timeout`, `encoding_error`, `config_error`, `unknown`

---

## Consumer Metrics

Collected automatically on every `NanoConsumer::consume()` call.

**Default tags** (auto-added to all metrics via StatsD client): `nano_service_name`

| Metric | Type | Tags | Description |
|--------|------|------|-------------|
| `event_started_count` | Counter | `event_name`, `retry` | Event consumption started |
| `event_processed_duration` | Histogram | `event_name`, `retry`, `status` | Processing time (ms) |
| `event_processed_memory_bytes` | Gauge | `event_name`, `retry`, `status` | Peak memory during processing (bytes) |
| `rmq_consumer_payload_bytes` | Histogram | `event_name` | Consumed message size |
| `rmq_consumer_dlx_total` | Counter | `event_name`, `reason` | Dead-letter queue events |
| `rmq_consumer_ack_failed_total` | Counter | `event_name` | ACK failures |

**Retry tags**: `first`, `retry`, `last`
**Status tags**: `success`, `failed`

**Memory metric note:** Reports peak memory (bytes) per event using `memory_get_peak_usage(true)`. Peak is reset between events via `memory_reset_peak_usage()` (PHP 8.2+).

---

## Connection Health Metrics

Collected during connection/channel lifecycle.

| Metric | Type | Tags | Description |
|--------|------|------|-------------|
| `rmq_connection_total` | Counter | `service`, `status` | Connection open events |
| `rmq_connection_active` | Gauge | `service` | Active connections (0 or 1) |
| `rmq_connection_errors_total` | Counter | `service`, `error_type` | Connection failures |
| `rmq_channel_total` | Counter | `service`, `status` | Channel open events |
| `rmq_channel_active` | Gauge | `service` | Active channels (0 or 1) |
| `rmq_channel_errors_total` | Counter | `service`, `error_type` | Channel failures |

---

## Connection Lifecycle Metrics

Collected when `CONNECTION_MAX_JOBS` is enabled.

| Metric | Type | Tags | Description |
|--------|------|------|-------------|
| `rmq_consumer_connection_reinit_total` | Counter | `reason` | Connection reinit events |
| `rmq_consumer_connection_reinit_duration_ms` | Timing | — | Reinit duration (ms) |

**Reason tags**: `max_jobs`

---

## Helper Classes

For custom metrics in your service code.

### `HttpMetrics`

For HTTP request handling. Records: `http_request_duration_ms`, `http_request_memory_mb`, `http_request_total`, `http_webhooks_received_by_provider`, `http_request_by_latency_bucket`, `http_response_status_total`.

Additional: `trackContentType()`, `trackPayloadSize()`, `recordError()`.

### `PublishMetrics`

For async job publishing. Records: `publish_job_duration_ms`, `publish_job_memory_mb`, `publish_job_processed`, `webhooks_published_by_provider`, `publish_job_by_latency_bucket`, `publish_job_retry_attempts`.

Auto-extracts provider from event name (`webhook.stripe` → `stripe`).

### `MetricsBuckets`

Static utility for consistent bucketing:
- `getHttpLatencyBucket()` — `fast_lt_10ms` through `critical_gt_1s`
- `getPublishLatencyBucket()` — `fast_lt_50ms` through `critical_gt_5s`
- `getPayloadSizeCategory()` — `tiny_lt_1kb` through `huge_gt_10mb`
- `getStatusClass()` — `2xx`, `4xx`, `5xx`
- `categorizeError()` — `database_error`, `timeout`, `disk_full`, `out_of_memory`, `rabbitmq_error`, `unknown`

### Usage Pattern

All helpers follow the same pattern:
1. Create helper with shared `StatsDClient` instance
2. Call `start()` before operation
3. Call `recordSuccess()` or `recordFailure()` based on result
4. Call `finish()` in `finally` block (always records timing)

Create `StatsDClient` once per worker and reuse. Create helper per request/job.

---

## Tag Cardinality Rules

Safe tags (bounded): `service`, `event_name`, `error_type`, `retry`, `status`, `env`, `reason`, `nano_service_name`

**NEVER** use as tags: user_id, invoice_id, request_id, UUID, timestamp — these cause Prometheus cardinality explosion.

---

## Prometheus Queries

```promql
# Publish rate
rate(rmq_publish_total{service="myservice"}[5m])

# Error rate
rate(rmq_publish_error_total{service="myservice"}[5m])

# Publish latency P95
histogram_quantile(0.95, rate(rmq_publish_duration_ms_bucket{service="myservice"}[5m]))

# Event processing rate
rate(event_started_count_total{nano_service_name="myservice"}[5m])

# Active connections (should be 0 or 1)
rmq_connection_active{service="myservice"}

# DLX rate
rate(rmq_consumer_dlx_total{nano_service_name="myservice"}[5m])
```

---

## Performance Impact

| Configuration | CPU | Memory | Network |
|---------------|-----|--------|---------|
| Disabled (default) | 0% | 0 MB | 0 bytes |
| Enabled, 100% sampling | 1-2% | 10-20 MB | ~50 KB/s |
| Enabled, 10% sampling | <0.5% | 10-20 MB | ~5 KB/s |

---

## Architecture

```
nano-service → UDP 8125 → statsd-exporter (DaemonSet) → :9102 → Prometheus → Grafana
```

statsd-exporter runs as DaemonSet (node-local). Use `status.hostIP` for `STATSD_HOST`.
