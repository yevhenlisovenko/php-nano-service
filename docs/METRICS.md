# nano-service Metrics Documentation

**Version:** 6.0+
**Last Updated:** 2026-01-19

---

## Overview

nano-service provides comprehensive StatsD metrics instrumentation for monitoring RabbitMQ operations, including:
- **Publisher metrics**: Publish rate, latency, errors, payload sizes
- **Consumer metrics**: Processing rate, latency, retries, DLX events, ACK failures
- **Connection health**: Connection and channel status, errors, lifecycle events

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

## Troubleshooting

### Metrics Not Appearing

**Check 1: Is STATSD_ENABLED set to true?**
```bash
# In your service
echo $STATSD_ENABLED  # Should output: true
```

**Check 2: Can service reach StatsD server?**
```bash
# Test UDP connectivity
echo "test_metric:1|c" | nc -u $STATSD_HOST $STATSD_PORT
```

**Check 3: Is statsd-exporter receiving metrics?**
```bash
# Check statsd-exporter logs
kubectl logs -n monitoring -l app=statsd-exporter --tail=50

# Check statsd-exporter metrics
curl http://<statsd-exporter-ip>:9102/metrics | grep statsd_exporter_packets_received
```

**Check 4: Is Prometheus scraping?**
```bash
# In Prometheus UI: Status > Targets
# Look for statsd-exporter targets, should be UP
```

### High Packet Loss

If you see `statsd_exporter_packets_dropped_total` increasing:

1. **Increase statsd-exporter resources:**
   ```yaml
   resources:
     limits:
       cpu: 1000m      # Increase from 500m
       memory: 1Gi     # Increase from 512Mi
   ```

2. **Reduce application sampling:**
   ```bash
   STATSD_SAMPLE_OK=0.01  # Reduce to 1%
   ```

3. **Check network latency:**
   ```bash
   # From service pod
   ping $STATSD_HOST
   ```

### Metrics Have Wrong Values

**Check namespace configuration:**
```bash
echo $STATSD_NAMESPACE  # Should match your service name
```

**Check for multiple services using same namespace:**
- Each service should have a unique `STATSD_NAMESPACE`
- Metrics from different services will be mixed if namespace is the same

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

## Support

For questions or issues:
- Check this documentation first
- Review [TROUBLESHOOTING.md](TROUBLESHOOTING.md)
- Contact your DevOps team for infrastructure issues
- Check Prometheus/Grafana for metric visibility

---

## Related Documentation

- [Configuration Guide](CONFIGURATION.md) - Detailed configuration reference
- [Troubleshooting Guide](TROUBLESHOOTING.md) - Common issues and solutions
- [CLAUDE.md](../CLAUDE.md) - Development guidelines
- DevOps Task: `2026-01-19_RABBIMQ_EVENT_METRICS` - Implementation details
