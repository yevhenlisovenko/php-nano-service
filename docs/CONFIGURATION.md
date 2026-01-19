# nano-service Configuration Guide

**Version:** 6.0+
**Last Updated:** 2026-01-19

---

## Configuration Methods

nano-service supports three configuration methods:

1. **Environment Variables** (recommended for production)
2. **Programmatic Configuration** (for advanced use cases)
3. **Hybrid** (mix of environment and programmatic)

---

## Environment Variables

### RabbitMQ Configuration (Required)

| Variable | Description | Example | Required |
|----------|-------------|---------|----------|
| `AMQP_HOST` | RabbitMQ server host | `rabbitmq.internal` | ✅ Yes |
| `AMQP_PORT` | RabbitMQ server port | `5672` | ✅ Yes |
| `AMQP_USER` | RabbitMQ username | `user` | ✅ Yes |
| `AMQP_PASS` | RabbitMQ password | `password` | ✅ Yes |
| `AMQP_VHOST` | RabbitMQ virtual host | `/` | ✅ Yes |
| `AMQP_PROJECT` | Project/tenant namespace | `myproject` | ✅ Yes |
| `AMQP_MICROSERVICE_NAME` | Service identifier | `myservice` | ✅ Yes |
| `AMQP_PUBLISHER_ENABLED` | Enable publishing | `true` | ❌ No (default: false) |
| `AMQP_PRIVATE_KEY` | RSA private key (base64) | `LS0t...` | ❌ No |
| `AMQP_PUBLIC_KEY` | RSA public key (base64) | `LS0t...` | ❌ No |

### StatsD Metrics Configuration (Optional)

| Variable | Description | Example | Default |
|----------|-------------|---------|---------|
| `STATSD_ENABLED` | Enable metrics collection | `true` | `false` |
| `STATSD_HOST` | StatsD server host | `10.192.0.15` | `127.0.0.1` |
| `STATSD_PORT` | StatsD server port | `8125` | `9125` |
| `STATSD_NAMESPACE` | Metrics namespace | `myservice` | `nano` |
| `STATSD_SAMPLE_OK` | Sampling for success metrics | `0.1` | `1.0` (100%) |
| `STATSD_SAMPLE_PAYLOAD` | Sampling for payload metrics | `0.1` | `0.1` (10%) |
| `APP_ENV` | Environment identifier | `production` | `production` |

---

## Configuration Examples

### Minimal Configuration (Consumer)

```bash
# RabbitMQ connection
export AMQP_HOST="rabbitmq.internal"
export AMQP_PORT="5672"
export AMQP_USER="user"
export AMQP_PASS="password"
export AMQP_VHOST="/"
export AMQP_PROJECT="myproject"
export AMQP_MICROSERVICE_NAME="myservice"

# Run consumer
php consumer.php
```

### Full Configuration (Publisher + Metrics)

```bash
# RabbitMQ connection
export AMQP_HOST="rabbitmq.internal"
export AMQP_PORT="5672"
export AMQP_USER="user"
export AMQP_PASS="password"
export AMQP_VHOST="/"
export AMQP_PROJECT="myproject"
export AMQP_MICROSERVICE_NAME="myservice"
export AMQP_PUBLISHER_ENABLED="true"

# StatsD metrics
export STATSD_ENABLED="true"
export STATSD_HOST="10.192.0.15"
export STATSD_PORT="8125"
export STATSD_NAMESPACE="myservice"
export STATSD_SAMPLE_OK="0.1"
export APP_ENV="production"

# Run publisher
php publisher.php
```

### Kubernetes ConfigMap Example

```yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: myservice-env
data:
  # RabbitMQ
  AMQP_HOST: "rabbitmq.internal"
  AMQP_PORT: "5672"
  AMQP_VHOST: "/"
  AMQP_PROJECT: "myproject"
  AMQP_MICROSERVICE_NAME: "myservice"
  AMQP_PUBLISHER_ENABLED: "true"

  # StatsD metrics
  STATSD_ENABLED: "true"
  STATSD_PORT: "8125"
  STATSD_NAMESPACE: "myservice"
  STATSD_SAMPLE_OK: "0.1"
  APP_ENV: "production"

---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: myservice
spec:
  template:
    spec:
      containers:
      - name: app
        envFrom:
        - configMapRef:
            name: myservice-env
        env:
        # Node IP for DaemonSet
        - name: STATSD_HOST
          valueFrom:
            fieldRef:
              fieldPath: status.hostIP
        # Secrets
        - name: AMQP_USER
          valueFrom:
            secretKeyRef:
              name: rabbitmq-credentials
              key: username
        - name: AMQP_PASS
          valueFrom:
            secretKeyRef:
              name: rabbitmq-credentials
              key: password
```

---

## Programmatic Configuration

### Using StatsDConfig Class

```php
use AlexFN\NanoService\Config\StatsDConfig;
use AlexFN\NanoService\NanoPublisher;

// Create custom configuration
$statsdConfig = new StatsDConfig([
    'enabled' => true,
    'host' => '10.192.0.15',
    'port' => 8125,
    'namespace' => 'myservice',
    'sampling' => [
        'ok_events' => 0.1,      // 10% sampling for success
        'error_events' => 1.0,   // 100% for errors (always)
        'latency' => 1.0,        // 100% for latency (accuracy)
        'payload' => 0.1,        // 10% for payload sizes
    ]
]);

// Pass to NanoPublisher
$publisher = new NanoPublisher([
    'statsd' => $statsdConfig
]);
```

### Legacy Array Configuration

```php
use AlexFN\NanoService\Clients\StatsDClient\StatsDClient;

// Old style (still works)
$statsD = new StatsDClient([
    'host' => '127.0.0.1',
    'port' => 8125,
    'namespace' => 'myservice',
]);

// Automatically wrapped in StatsDConfig
```

---

## Environment-Specific Configuration

### Local Development

```bash
# Use localhost statsd-exporter (docker)
export STATSD_ENABLED="true"
export STATSD_HOST="127.0.0.1"
export STATSD_PORT="8125"
export STATSD_NAMESPACE="dev"
export STATSD_SAMPLE_OK="1.0"  # 100% for testing
export APP_ENV="local"
```

### Staging/E2E

```bash
export STATSD_ENABLED="true"
export STATSD_HOST="<node-ip>"  # From status.hostIP
export STATSD_PORT="8125"
export STATSD_NAMESPACE="myservice"
export STATSD_SAMPLE_OK="1.0"  # 100% in staging
export APP_ENV="staging"
```

### Production

```bash
export STATSD_ENABLED="true"
export STATSD_HOST="<node-ip>"  # From status.hostIP
export STATSD_PORT="8125"
export STATSD_NAMESPACE="myservice"
export STATSD_SAMPLE_OK="0.1"  # 10% sampling
export APP_ENV="production"
```

---

## Configuration Validation

### Check Configuration at Runtime

```php
use AlexFN\NanoService\Config\StatsDConfig;

$config = new StatsDConfig();

echo "Enabled: " . ($config->isEnabled() ? 'Yes' : 'No') . "\n";
echo "Host: " . $config->getHost() . "\n";
echo "Port: " . $config->getPort() . "\n";
echo "Namespace: " . $config->getNamespace() . "\n";
echo "Sample Rate (ok): " . $config->getSampleRate('ok_events') . "\n";
```

### Verify ENV Variables in Container

```bash
# In Kubernetes pod
kubectl exec -it <pod-name> -- env | grep STATSD

# Should show:
# STATSD_ENABLED=true
# STATSD_HOST=10.192.0.15
# STATSD_PORT=8125
# STATSD_NAMESPACE=myservice
```

---

## Security Considerations

### Secrets Management

**Never set these in plain ENV variables:**
- ❌ `AMQP_PASS` - Use Kubernetes Secrets
- ❌ `AMQP_PRIVATE_KEY` - Use Kubernetes Secrets
- ❌ `AMQP_PUBLIC_KEY` - Use Kubernetes Secrets

**Good practice:**
```yaml
env:
- name: AMQP_PASS
  valueFrom:
    secretKeyRef:
      name: rabbitmq-credentials
      key: password
```

### Network Security

StatsD uses **UDP** which is:
- ✅ Fire-and-forget (non-blocking)
- ✅ No authentication required (local network only)
- ⚠️ No encryption (don't send sensitive data in metric names/tags)

**Best practices:**
- Use node-local StatsD receiver (DaemonSet with hostNetwork)
- Don't send PII or sensitive data in metric tags
- Keep StatsD receiver in internal network

---

## Configuration Checklist

### Before Deploying to Production

- [ ] `STATSD_ENABLED` explicitly set (true or false)
- [ ] `STATSD_HOST` points to correct target (node IP for DaemonSet)
- [ ] `STATSD_NAMESPACE` is unique per service
- [ ] `STATSD_SAMPLE_OK` set appropriately for traffic volume
- [ ] `APP_ENV` matches actual environment
- [ ] No secrets in ENV variables (use Kubernetes Secrets)
- [ ] statsd-exporter DaemonSet deployed and healthy
- [ ] Prometheus scraping statsd-exporter
- [ ] Test metrics appear in Prometheus/Grafana

---

## Advanced Topics

### Dynamic Configuration

Configuration is loaded once at construction. To change configuration:

```php
// Option 1: Restart process with new ENV vars

// Option 2: Create new instance
$publisher = new NanoPublisher();  // Uses new ENV
```

### Per-Environment Sampling

Use different sampling rates per environment:

```php
$sampleRate = match (getenv('APP_ENV')) {
    'production' => 0.01,  // 1% in prod
    'staging' => 0.1,      // 10% in staging
    'local' => 1.0,        // 100% in local
    default => 1.0,
};

$config = new StatsDConfig([
    'sampling' => [
        'ok_events' => $sampleRate,
    ]
]);
```

---

## Troubleshooting Configuration Issues

### Issue: Metrics not appearing

```bash
# Check if enabled
echo $STATSD_ENABLED  # Should be 'true'

# Check connection
nc -vzu $STATSD_HOST $STATSD_PORT

# Check namespace
echo $STATSD_NAMESPACE  # Should be unique
```

### Issue: Wrong metrics namespace

```bash
# Check configured namespace
kubectl exec <pod> -- env | grep STATSD_NAMESPACE

# Should match your service name
# If wrong, update ConfigMap or ENV
```

### Issue: Metrics from multiple services mixed

**Problem:** Multiple services using same `STATSD_NAMESPACE`

**Solution:** Ensure each service has unique namespace
```bash
# Service A
STATSD_NAMESPACE=service-a

# Service B
STATSD_NAMESPACE=service-b
```

---

See [METRICS.md](METRICS.md) for full metrics documentation.
See [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for common issues.
