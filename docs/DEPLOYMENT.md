# Deployment

Kubernetes deployment templates for nano-service applications.

For environment variable reference, see [CONFIGURATION.md](CONFIGURATION.md).

---

## Prerequisites

1. nano-service v7.0+ installed via Composer
2. statsd-exporter DaemonSet deployed (UDP 8125, exposes :9102)
3. Prometheus scraping statsd-exporter endpoints
4. PostgreSQL database with outbox/inbox schema created

---

## ConfigMap Template (env.tmpl)

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

  # PostgreSQL
  DB_BOX_HOST: "${DB_BOX_HOST}"
  DB_BOX_PORT: "${DB_BOX_PORT}"
  DB_BOX_NAME: "${DB_BOX_NAME}"
  DB_BOX_USER: "${DB_BOX_USER}"
  DB_BOX_PASS: "${DB_BOX_PASS}"
  DB_BOX_SCHEMA: "${DB_BOX_SCHEMA}"

  # StatsD Metrics
  STATSD_ENABLED: "${STATSD_ENABLED}"
  STATSD_PORT: "${STATSD_PORT}"
  STATSD_NAMESPACE: "myservice"  # Hard-code per service
  STATSD_SAMPLE_OK: "${STATSD_SAMPLE_OK}"
  STATSD_SAMPLE_PAYLOAD: "${STATSD_SAMPLE_PAYLOAD}"
  APP_ENV: "${APP_ENV}"
```

---

## Deployment Template (deployment.tmpl)

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

## GitLab CI Variables

```yaml
# E2E - Full sampling
deploy:e2e:
  variables:
    STATSD_ENABLED: "true"
    STATSD_PORT: "8125"
    STATSD_SAMPLE_OK: "1.0"
    STATSD_SAMPLE_PAYLOAD: "0.1"

# Production - Reduced sampling
deploy:live:
  variables:
    STATSD_ENABLED: "true"
    STATSD_PORT: "8125"
    STATSD_SAMPLE_OK: "0.1"
    STATSD_SAMPLE_PAYLOAD: "0.1"
```

---

## Rollout Strategy

1. Deploy to E2E with `STATSD_ENABLED=false`
2. Enable metrics in E2E: `STATSD_ENABLED=true`
3. Monitor for 1 hour — check Prometheus/Grafana
4. Canary deploy to production (1 pod)
5. Monitor canary for 1 hour
6. Full production rollout

### Rollback

```bash
kubectl rollout undo deployment/<service-name> -n <namespace>
```

---

## Verification

```bash
# Check all env vars
kubectl exec <pod> -- env | grep -E "(STATSD|DB_BOX|AMQP)"

# Test StatsD connectivity
kubectl exec <pod> -- sh -c 'echo "test:1|c" | nc -u $STATSD_HOST $STATSD_PORT'

# Query Prometheus
# rabbitmq_publish_total{service="myservice"}
# rabbitmq_connection_active{service="myservice"}
```

See [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for common issues.
