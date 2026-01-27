# Deployment Guide

**Version:** 6.5+
**Last Updated:** 2026-01-27

---

## Overview

This guide covers deploying nano-service applications to Kubernetes with StatsD metrics enabled.

---

## Quick Start

### 1. Update Dependencies

```bash
composer require yevhenlisovenko/nano-service:^6.5
```

### 2. Configure Environment

```yaml
# Kubernetes ConfigMap
env:
- name: STATSD_ENABLED
  value: "true"
- name: STATSD_HOST
  valueFrom:
    fieldRef:
      fieldPath: status.hostIP  # Node IP for DaemonSet
- name: STATSD_PORT
  value: "8125"
- name: STATSD_NAMESPACE
  value: "myservice"
- name: STATSD_SAMPLE_OK
  value: "0.1"  # 10% sampling
```

### 3. Deploy

```bash
kubectl apply -f deployment.yaml
```

---

## Prerequisites

1. **nano-service v6.0+** installed via Composer
2. **statsd-exporter DaemonSet** deployed (UDP 8125, exposes :9102)
3. **Prometheus** scraping statsd-exporter endpoints

---

## Kubernetes Templates

### ConfigMap Template (env.tmpl)

```yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: ${SERVICE_NAME}-${NAMESPACE}-${BRANCH_SLUG}-env
data:
  # RabbitMQ
  AMQP_PROJECT: "${AMQP_PROJECT}"
  AMQP_HOST: "${AMQP_HOST}"
  AMQP_PORT: "${AMQP_PORT}"
  AMQP_USER: "${AMQP_USER}"
  AMQP_PASS: "${AMQP_PASS}"
  AMQP_VHOST: "${AMQP_VHOST}"
  AMQP_MICROSERVICE_NAME: "${SERVICE_NAME}-${NAMESPACE}"
  AMQP_PUBLISHER_ENABLED: "${AMQP_PUBLISHER_ENABLED}"

  # StatsD Metrics
  STATSD_ENABLED: "${STATSD_ENABLED}"
  STATSD_PORT: "${STATSD_PORT}"
  STATSD_NAMESPACE: "myservice"  # Hard-code per service
  STATSD_SAMPLE_OK: "${STATSD_SAMPLE_OK}"
  STATSD_SAMPLE_PAYLOAD: "${STATSD_SAMPLE_PAYLOAD}"
  APP_ENV: "${APP_ENV}"
```

### Deployment Template (deployment.tmpl)

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: ${SERVICE_NAME}-${NAMESPACE}-${BRANCH_SLUG}
spec:
  replicas: ${REPLICAS}
  template:
    spec:
      containers:
      - name: ${SERVICE_NAME}
        image: ${DOCKER_DEPLOY_IMAGE_REF}:${VERSION}
        envFrom:
        - configMapRef:
            name: ${SERVICE_NAME}-${NAMESPACE}-${BRANCH_SLUG}-env
        env:
        # STATSD_HOST uses Downward API (can't be in ConfigMap)
        - name: STATSD_HOST
          valueFrom:
            fieldRef:
              fieldPath: status.hostIP
        command: ["php"]
        args: ["bin/console", "consumer"]
```

---

## GitLab CI Integration

### CI/CD Variables

```yaml
# E2E - Full sampling for testing
deploy:e2e:
  variables:
    STATSD_ENABLED: "true"
    STATSD_PORT: "8125"
    STATSD_SAMPLE_OK: "1.0"        # 100%
    STATSD_SAMPLE_PAYLOAD: "0.1"

# Production - Reduced sampling
deploy:live:
  variables:
    STATSD_ENABLED: "true"
    STATSD_PORT: "8125"
    STATSD_SAMPLE_OK: "0.1"        # 10%
    STATSD_SAMPLE_PAYLOAD: "0.1"
```

### Sampling Recommendations

| Environment | Traffic | `STATSD_SAMPLE_OK` |
|-------------|---------|-------------------|
| E2E/Staging | Any | `1.0` (100%) |
| Production | <100 events/sec | `1.0` (100%) |
| Production | 100-1000/sec | `0.1` (10%) |
| Production | >1000/sec | `0.01` (1%) |

---

## Verification

### 1. Check Environment Variables

```bash
kubectl exec <pod> -- env | grep STATSD
# Expected:
# STATSD_ENABLED=true
# STATSD_HOST=10.192.0.XX
# STATSD_PORT=8125
# STATSD_NAMESPACE=myservice
```

### 2. Test Metrics Flow

```bash
# Send test metric
kubectl exec <pod> -- sh -c 'echo "test:1|c" | nc -u $STATSD_HOST $STATSD_PORT'

# Check statsd-exporter
kubectl logs -n monitoring -l app=statsd-exporter --tail=50 | grep test
```

### 3. Query Prometheus

```promql
rabbitmq_publish_total{service="myservice"}
rabbitmq_connection_active{service="myservice"}
```

---

## Rollout Strategy

### Safe Deployment Process

1. **Deploy to E2E** with `STATSD_ENABLED=false`
2. **Enable metrics in E2E**: `STATSD_ENABLED=true`
3. **Monitor for 1 hour** - check Prometheus/Grafana
4. **Canary deploy to production** (1 pod)
5. **Monitor canary for 1 hour**
6. **Full production rollout**

### Rollback

```bash
kubectl rollout undo deployment/<service-name> -n <namespace>
```

---

## Best Practices

### 1. Hard-Code STATSD_NAMESPACE

```yaml
# In env.tmpl - hard-coded, not variable
STATSD_NAMESPACE: "myservice"
```

Each service must have a unique namespace.

### 2. Use status.hostIP for STATSD_HOST

```yaml
env:
- name: STATSD_HOST
  valueFrom:
    fieldRef:
      fieldPath: status.hostIP
```

Node IP is pod-specific and cannot be in ConfigMap.

### 3. Start Disabled

Deploy with `STATSD_ENABLED=false` first, then enable gradually.

---

## Troubleshooting

See [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for:
- Metrics not appearing
- High packet drop rate
- Connection issues

---

## References

- [Configuration Guide](CONFIGURATION.md)
- [Metrics Documentation](METRICS.md)
- [Troubleshooting Guide](TROUBLESHOOTING.md)
