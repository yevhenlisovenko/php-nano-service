# Configuration Guide

**Version:** 6.5+
**Last Updated:** 2026-01-27

---

## Quick Reference

### RabbitMQ (Required)

| Variable | Description | Example |
|----------|-------------|---------|
| `AMQP_HOST` | RabbitMQ host | `rabbitmq.internal` |
| `AMQP_PORT` | RabbitMQ port | `5672` |
| `AMQP_USER` | Username | `user` |
| `AMQP_PASS` | Password | `password` |
| `AMQP_VHOST` | Virtual host | `/` |
| `AMQP_PROJECT` | Project namespace | `myproject` |
| `AMQP_MICROSERVICE_NAME` | Service identifier | `myservice` |
| `AMQP_PUBLISHER_ENABLED` | Enable publishing | `true` (default: `false`) |

### StatsD Metrics (Optional)

| Variable | Description | Example |
|----------|-------------|---------|
| `STATSD_ENABLED` | Enable metrics | `true` (default: `false`) |
| `STATSD_HOST` | StatsD server host | `10.192.0.15` |
| `STATSD_PORT` | StatsD server port | `8125` |
| `STATSD_NAMESPACE` | Metric namespace | `myservice` |
| `STATSD_SAMPLE_OK` | Success sampling rate | `0.1` |
| `STATSD_SAMPLE_PAYLOAD` | Payload sampling rate | `0.1` |
| `APP_ENV` | Environment tag | `production` |

When `STATSD_ENABLED=true`, all StatsD variables are **required**. Missing variables cause a `RuntimeException` at startup.

### Connection Lifecycle Management (Optional)

| Variable | Description | Example |
|----------|-------------|---------|
| `CONNECTION_MAX_JOBS` | Max jobs before reconnect | `10000` (default: `0` = disabled) |

**Purpose:** Automatically reinitialize RabbitMQ and database connections after processing a specified number of messages. Useful for preventing stale connections in long-running workers (similar to Laravel Horizon's `maxJobs`).

**Behavior:**
- Default `0` means feature is **disabled** (current behavior preserved)
- When threshold is reached, both RabbitMQ and database connections are reinitialized
- Counter resets after reinitialization
- Opt-in feature for backwards compatibility

**Usage example:**
```bash
# Reconnect every 1000 messages
CONNECTION_MAX_JOBS=1000
```

---

## Configuration Methods

### 1. Environment Variables (Recommended)

```bash
# RabbitMQ
export AMQP_HOST="rabbitmq.internal"
export AMQP_PORT="5672"
export AMQP_USER="user"
export AMQP_PASS="password"
export AMQP_VHOST="/"
export AMQP_PROJECT="myproject"
export AMQP_MICROSERVICE_NAME="myservice"

# Metrics (optional)
export STATSD_ENABLED="true"
export STATSD_HOST="10.192.0.15"
export STATSD_PORT="8125"
export STATSD_NAMESPACE="myservice"
export STATSD_SAMPLE_OK="0.1"
```

### 2. Programmatic Configuration

```php
use AlexFN\NanoService\Config\StatsDConfig;

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
```

### 3. Kubernetes ConfigMap

```yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: myservice-env
data:
  AMQP_HOST: "rabbitmq.internal"
  AMQP_PORT: "5672"
  AMQP_VHOST: "/"
  AMQP_PROJECT: "myproject"
  AMQP_MICROSERVICE_NAME: "myservice"
  STATSD_ENABLED: "true"
  STATSD_PORT: "8125"
  STATSD_NAMESPACE: "myservice"
---
# STATSD_HOST via Downward API (in Deployment)
env:
- name: STATSD_HOST
  valueFrom:
    fieldRef:
      fieldPath: status.hostIP
```

---

## Environment-Specific Settings

| Environment | `STATSD_SAMPLE_OK` | `APP_ENV` |
|-------------|-------------------|-----------|
| Local | `1.0` | `local` |
| Staging | `1.0` | `staging` |
| Production (low traffic) | `1.0` | `production` |
| Production (high traffic) | `0.1` or `0.01` | `production` |

---

## Best Practices

### Connection Reuse

nano-service uses connection pooling internally. Create instances once and reuse:

```php
// Long-running worker
final class Worker
{
    private NanoPublisher $publisher;
    private StatsDClient $statsd;

    protected function initialize(): void
    {
        $this->publisher = new NanoPublisher();  // Create once
        $this->statsd = new StatsDClient();      // Create once
    }

    protected function process(Job $job): void
    {
        // Reuse instances
        $metrics = new PublishMetrics($this->statsd, 'service', $job->event);
        $this->publisher->setMessage($message)->publish($job->event);
    }
}
```

### Health Checks

```php
private function isRabbitMQHealthy(): bool
{
    try {
        $connection = $this->publisher->getConnection();
        if (!$connection->isConnected()) {
            return false;
        }
        $connection->checkHeartBeat();
        return true;
    } catch (\Exception) {
        return false;
    }
}
```

### Fail-Fast Validation

Configuration validates at startup. Missing required variables throw `RuntimeException`:

```php
// Fails immediately if STATSD_HOST is missing
$config = new StatsDConfig();

// To validate manually:
protected function initialize(): void
{
    $required = ['AMQP_HOST', 'AMQP_PORT', 'AMQP_USER', 'AMQP_PASS'];
    foreach ($required as $var) {
        if (!isset($_ENV[$var])) {
            throw new \RuntimeException("Missing: {$var}");
        }
    }
}
```

---

## Security

### Never hardcode secrets

```yaml
# Use Kubernetes Secrets
env:
- name: AMQP_PASS
  valueFrom:
    secretKeyRef:
      name: rabbitmq-credentials
      key: password
```

### StatsD uses UDP

- Fire-and-forget (non-blocking)
- No authentication (keep on internal network)
- No encryption (don't send sensitive data in metrics)

---

## Validation Checklist

Before deploying to production:

- [ ] `STATSD_ENABLED` explicitly set
- [ ] `STATSD_NAMESPACE` is unique per service
- [ ] `STATSD_SAMPLE_OK` appropriate for traffic volume
- [ ] No secrets in ConfigMaps
- [ ] statsd-exporter DaemonSet deployed
- [ ] Prometheus scraping statsd-exporter

---

## Troubleshooting

### Check Configuration

```bash
kubectl exec <pod> -- env | grep -E "(AMQP|STATSD)"
```

### Verify Connectivity

```bash
# RabbitMQ
kubectl exec <pod> -- nc -zv $AMQP_HOST $AMQP_PORT

# StatsD
kubectl exec <pod> -- sh -c 'echo "test:1|c" | nc -u $STATSD_HOST $STATSD_PORT'
```

See [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for more.

---

## References

- [Metrics Documentation](METRICS.md) - Complete metrics reference
- [Deployment Guide](DEPLOYMENT.md) - Kubernetes deployment
- [Troubleshooting Guide](TROUBLESHOOTING.md) - Common issues
