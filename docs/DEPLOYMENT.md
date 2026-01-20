# Deployment Guide: Integrating StatsD Metrics

**Version:** 6.0+
**Last Updated:** 2026-01-19

---

## Overview

This guide shows how to integrate nano-service v6.0 StatsD metrics into your Kubernetes deployments using templates and CI/CD pipelines.

---

## Prerequisites

1. **nano-service v6.0+** installed via Composer:
   ```json
   "yevhenlisovenko/nano-service": "^6.0"
   ```

2. **statsd-exporter DaemonSet** deployed to your cluster:
   - Listens on UDP 8125 (node-local with hostNetwork)
   - Exposes Prometheus metrics on TCP 9102
   - See your DevOps team for infrastructure setup

3. **Prometheus** scraping statsd-exporter endpoints

---

## Deployment Template Integration

### Step 1: Update ConfigMap Template (env.tmpl)

Add StatsD variables to your `deploy/env.tmpl` file:

```yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: ${SERVICE_NAME}-${NAMESPACE}-${BRANCH_SLUG}-env
data:
  # Existing AMQP configuration
  AMQP_PROJECT: "${AMQP_PROJECT}"
  AMQP_HOST: "${AMQP_HOST}"
  AMQP_PORT: "${AMQP_PORT}"
  AMQP_USER: "${AMQP_USER}"
  AMQP_PASS: "${AMQP_PASS}"
  AMQP_VHOST: "${AMQP_VHOST}"
  AMQP_MICROSERVICE_NAME: "${SERVICE_NAME}-${NAMESPACE}"
  AMQP_PUBLISHER_ENABLED: "${AMQP_PUBLISHER_ENABLED}"

  # Existing metrics namespace (if you have it)
  METRICS_NAMESPACE: "myservice"

  # ADD THESE: StatsD Metrics Configuration (v6.0+)
  STATSD_ENABLED: "${STATSD_ENABLED}"
  STATSD_PORT: "${STATSD_PORT}"
  STATSD_NAMESPACE: "myservice"  # Hard-code your service name
  STATSD_SAMPLE_OK: "${STATSD_SAMPLE_OK}"
  STATSD_SAMPLE_PAYLOAD: "${STATSD_SAMPLE_PAYLOAD}"

  # Other configuration
  APP_ENV: "${APP_ENV}"
  SENTRY_DSN: "${SENTRY_DSN}"
```

**Key points:**
- `STATSD_NAMESPACE` should be **hard-coded** to your service name
- Use same value as `METRICS_NAMESPACE` if you have it
- Other variables come from CI/CD pipeline

### Step 2: Update Deployment Template (deployment.tmpl)

Add `STATSD_HOST` environment variable using Kubernetes Downward API:

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: ${SERVICE_NAME}-${NAMESPACE}-${BRANCH_SLUG}
spec:
  template:
    spec:
      containers:
      - name: ${SERVICE_NAME}
        image: ${DOCKER_DEPLOY_IMAGE_REF}:${VERSION}

        # Existing envFrom (ConfigMap)
        envFrom:
        - configMapRef:
            name: ${SERVICE_NAME}-${NAMESPACE}-${BRANCH_SLUG}-env

        # ADD THIS: STATSD_HOST with node IP
        env:
        - name: STATSD_HOST
          valueFrom:
            fieldRef:
              fieldPath: status.hostIP  # Gets node IP dynamically

        command: ["php"]
        args: ["bin/console", "consumer"]
```

**Why `status.hostIP`?**
- Kubernetes Downward API provides the node IP where pod is running
- statsd-exporter DaemonSet runs on each node with `hostNetwork: true`
- Pod sends UDP to node IP → reaches DaemonSet on that node

---

## GitLab CI Integration

### Step 3: Add Variables to .gitlab-ci.yml

Add StatsD variables to your deployment jobs in `.gitlab-ci.yml`:

```yaml
#################################################################################
####### DEPLOY E2E ##############################################################
#################################################################################

myservice:deploy:e2e:
  stage: e2e
  image: awescodehub/kubectl:1.1.1
  variables:
    SERVICE_NAME: $SERVICE_NAME_MYSERVICE
    VERSION: "${CI_COMMIT_TAG}-myservice"
    KUBE_ACCESS: $GLOBAL_K3S_E2E_KUBE_CONFIG

    # ... existing AMQP, Redis, etc variables ...

    SENTRY_DSN: "https://..."

    # ADD THESE: StatsD Metrics (E2E - 100% sampling)
    STATSD_ENABLED: "true"
    STATSD_PORT: "8125"
    STATSD_SAMPLE_OK: "1.0"        # 100% in e2e/staging
    STATSD_SAMPLE_PAYLOAD: "0.1"   # 10% payload sampling

  <<: *deploy_script
  rules:
    - if: '$CI_COMMIT_TAG'
      when: on_success

#################################################################################
####### DEPLOY LIVE #############################################################
#################################################################################

myservice:deploy:live:
  stage: live
  image: awescodehub/kubectl:1.1.1
  variables:
    SERVICE_NAME: $SERVICE_NAME_MYSERVICE
    VERSION: "${CI_COMMIT_TAG}-myservice"
    KUBE_ACCESS: $GLOBAL_K3S_LIVE_KUBE_CONFIG

    # ... existing AMQP, Redis, etc variables ...

    SENTRY_DSN: "https://..."

    # ADD THESE: StatsD Metrics (LIVE - 10% sampling)
    STATSD_ENABLED: "true"
    STATSD_PORT: "8125"
    STATSD_SAMPLE_OK: "0.1"        # 10% in production
    STATSD_SAMPLE_PAYLOAD: "0.1"   # 10% payload sampling

  <<: *deploy_script
  rules:
    - if: '$CI_COMMIT_TAG'
      when: manual
```

**Sampling recommendations:**
- **E2E/Staging**: 100% (`STATSD_SAMPLE_OK=1.0`) - full visibility for testing
- **Production (low-traffic)**: 100% (`STATSD_SAMPLE_OK=1.0`) - if <100 events/sec
- **Production (medium-traffic)**: 10% (`STATSD_SAMPLE_OK=0.1`) - if 100-1000 events/sec
- **Production (high-traffic)**: 1% (`STATSD_SAMPLE_OK=0.01`) - if >1000 events/sec

---

## Complete Example: Provider Service

### File Structure

```
packages/providers/myservice/
├── deploy/
│   ├── env.tmpl              # ConfigMap template
│   ├── deployment.tmpl       # Deployment template
│   └── metrics-deployment.tmpl  # Metrics exporter (optional)
├── .gitlab-ci.yml            # CI/CD pipeline
├── Dockerfile
└── composer.json
```

### env.tmpl (Complete Example)

```yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: ${SERVICE_NAME}-${NAMESPACE}-${BRANCH_SLUG}-env
data:
  APP_NAME: "${SERVICE_NAME}_${NAMESPACE}_${BRANCH_SLUG}"
  APP_ENV: "${APP_ENV}"

  # RabbitMQ Configuration
  AMQP_PROJECT: "${AMQP_PROJECT}"
  AMQP_HOST: "${AMQP_HOST}"
  AMQP_PORT: "${AMQP_PORT}"
  AMQP_USER: "${AMQP_USER}"
  AMQP_PASS: "${AMQP_PASS}"
  AMQP_VHOST: "${AMQP_VHOST}"
  AMQP_MICROSERVICE_NAME: "${SERVICE_NAME}-${NAMESPACE}"
  AMQP_CONSUMER_HEARTBEAT_URL: "${AMQP_CONSUMER_HEARTBEAT_URL}"
  AMQP_PUBLISHER_ENABLED: "${AMQP_PUBLISHER_ENABLED}"
  AMQP_PRIVATE_KEY: "${AMQP_PRIVATE_KEY}"
  AMQP_PUBLIC_KEY: "${AMQP_PUBLIC_KEY}"

  # Redis Configuration
  REDIS_HOST: "${REDIS_HOST}"
  REDIS_PORT: "${REDIS_PORT}"
  REDIS_PASSWORD: "${REDIS_PASSWORD}"

  # Metrics namespace (optional, legacy)
  METRICS_NAMESPACE: "myservice"

  # StatsD Metrics Configuration (v6.0+)
  STATSD_ENABLED: "${STATSD_ENABLED}"
  STATSD_PORT: "${STATSD_PORT}"
  STATSD_NAMESPACE: "myservice"  # Hard-coded service name
  STATSD_SAMPLE_OK: "${STATSD_SAMPLE_OK}"
  STATSD_SAMPLE_PAYLOAD: "${STATSD_SAMPLE_PAYLOAD}"

  # Observability
  SENTRY_DSN: "${SENTRY_DSN}"
```

### deployment.tmpl (Complete Example)

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: ${SERVICE_NAME}-${NAMESPACE}-${BRANCH_SLUG}
  labels:
    app: ${SERVICE_NAME}-${NAMESPACE}-${BRANCH_SLUG}
spec:
  replicas: ${REPLICAS}
  selector:
    matchLabels:
      app: ${SERVICE_NAME}-${NAMESPACE}-${BRANCH_SLUG}
  strategy:
    type: RollingUpdate
    rollingUpdate:
      maxSurge: 1
      maxUnavailable: 33%
  template:
    metadata:
      labels:
        app: ${SERVICE_NAME}-${NAMESPACE}-${BRANCH_SLUG}
    spec:
      containers:
      - name: ${SERVICE_NAME}
        imagePullPolicy: IfNotPresent
        image: ${DOCKER_DEPLOY_IMAGE_REF}:${VERSION}

        # Load ConfigMap variables
        envFrom:
        - configMapRef:
            name: ${SERVICE_NAME}-${NAMESPACE}-${BRANCH_SLUG}-env

        # STATSD_HOST needs Downward API (can't be in ConfigMap)
        env:
        - name: STATSD_HOST
          valueFrom:
            fieldRef:
              fieldPath: status.hostIP

        command:
        - php
        args:
        - bin/console
        - consumer

        resources:
          limits:
            cpu: ${LIMIT_CPU}
            memory: ${LIMIT_MEMORY}
          requests:
            cpu: ${REQUESTED_CPU}
            memory: ${REQUESTED_MEMORY}

      imagePullSecrets:
      - name: gitlab-awescode-registry-proxy
```

### .gitlab-ci.yml (Complete Example)

```yaml
#################################################################################
####### VARIABLES ###############################################################
#################################################################################

variables:
  SERVICE_NAME: $SERVICE_NAME_MYSERVICE
  SERVICE_GIT_URL: "${SHARED_GITLAB_URL}/${CI_PROJECT_ROOT_NAMESPACE}/${CI_PROJECT_NAME}"
  CI_PROJECT_DIR: packages/providers/myservice

#################################################################################
####### DEPLOY SCRIPTS ###########################################################
#################################################################################

.deploy_script: &deploy_script
  script:
    # change to service directory
    - cd packages/providers/myservice

    # apply kube config
    - echo -n $KUBE_ACCESS | base64 -d > $HOME/.kube/config

    # create docker image pull secret if not exists
    - >-
      kubectl -n $BRANCH_SLUG get secret gitlab-awescode-registry-proxy ||
      kubectl -n $BRANCH_SLUG create secret docker-registry gitlab-awescode-registry-proxy
      --docker-server=$SHARED_DOCKER_PROXY_ADDR
      --docker-username=$SHARED_DOCKER_PROXY_USERNAME
      --docker-password=$SHARED_DOCKER_PROXY_PASSWORD

    # inserting env variables (envsubst replaces ${VAR} with values)
    - envsubst < ./deploy/env.tmpl > ./deploy/env.yaml
    - envsubst < ./deploy/deployment.tmpl > ./deploy/deployment.yaml

    # apply to kube
    - kubectl -n $BRANCH_SLUG apply -f ./deploy/env.yaml
    - kubectl -n $BRANCH_SLUG apply -f ./deploy/deployment.yaml

    # back config to empty
    - echo -n "" | base64 -d > $HOME/.kube/config

#################################################################################
####### BUILD ###################################################################
#################################################################################

build:
  stage: build
  image: docker:latest
  services:
    - docker:dind
  variables:
    VERSION: "${CI_COMMIT_TAG}-myservice"
  script:
    - docker login -u gitlab-ci-token -p "$CI_BUILD_TOKEN" "$CI_REGISTRY"
    - docker build -t "$CI_REGISTRY_IMAGE:$VERSION" -f packages/providers/myservice/Dockerfile .
    - docker push "$CI_REGISTRY_IMAGE:$VERSION"
    - docker system prune -a -f || true
  rules:
    - if: '$CI_COMMIT_TAG'
      changes:
        - "packages/providers/myservice/**/*"
        - "shared/**/*"

#################################################################################
####### DEPLOY E2E ##############################################################
#################################################################################

deploy:e2e:
  stage: e2e
  image: awescodehub/kubectl:1.1.1
  variables:
    SERVICE_NAME: $SERVICE_NAME_MYSERVICE
    VERSION: "${CI_COMMIT_TAG}-myservice"
    KUBE_ACCESS: $GLOBAL_K3S_E2E_KUBE_CONFIG
    LIMIT_CPU: '50m'
    LIMIT_MEMORY: '32M'
    REQUESTED_CPU: '50m'
    REQUESTED_MEMORY: '32M'
    REPLICAS: "2"
    KUBE_ENVIRONMENT: "dev"
    NAMESPACE: "e2e"
    BRANCH_SLUG: "e2e"
    APP_ENV: "development"

    # RabbitMQ
    AMQP_PROJECT: "easyweek-e2e"
    AMQP_HOST: "${GLOBAL_K3S_E2E_RABBIT_HOST}"
    AMQP_PORT: "${GLOBAL_K3S_E2E_RABBIT_PORT}"
    AMQP_USER: "${GLOBAL_K3S_E2E_RABBIT_USER}"
    AMQP_PASS: "${GLOBAL_K3S_E2E_RABBIT_PASSWORD}"
    AMQP_VHOST: "easyweek-e2e"
    AMQP_CONSUMER_HEARTBEAT_URL: ""
    AMQP_PUBLISHER_ENABLED: "true"
    AMQP_PRIVATE_KEY: "${GLOBAL_STAGE_AMQP_PRIVATE_KEY}"
    AMQP_PUBLIC_KEY: "${GLOBAL_STAGE_AMQP_PUBLIC_KEY}"

    # Redis
    REDIS_HOST: "${GLOBAL_K3S_E2E_REDIS_HOST}"
    REDIS_PORT: "${GLOBAL_K3S_E2E_REDIS_PORT}"
    REDIS_PASSWORD: "${GLOBAL_K3S_E2E_REDIS_PASSWORD}"

    # Observability
    SENTRY_DSN: "https://your-sentry-dsn-here"

    # StatsD Metrics (E2E - full sampling for testing)
    STATSD_ENABLED: "true"
    STATSD_PORT: "8125"
    STATSD_SAMPLE_OK: "1.0"        # 100% sampling in e2e
    STATSD_SAMPLE_PAYLOAD: "0.1"   # 10% payload sampling

  <<: *deploy_script
  rules:
    - if: '$CI_COMMIT_TAG'
      changes:
        - "packages/providers/myservice/**/*"
        - "shared/**/*"
      when: on_success

#################################################################################
####### DEPLOY LIVE #############################################################
#################################################################################

deploy:live:
  stage: live
  image: awescodehub/kubectl:1.1.1
  variables:
    SERVICE_NAME: $SERVICE_NAME_MYSERVICE
    VERSION: "${CI_COMMIT_TAG}-myservice"
    KUBE_ACCESS: $GLOBAL_K3S_LIVE_KUBE_CONFIG
    LIMIT_CPU: '100m'
    LIMIT_MEMORY: '100M'
    REQUESTED_CPU: '50m'
    REQUESTED_MEMORY: '50M'
    REPLICAS: "3"
    KUBE_ENVIRONMENT: "live"
    NAMESPACE: "live"
    BRANCH_SLUG: "live"
    APP_ENV: "production"

    # RabbitMQ
    AMQP_PROJECT: "easyweek-live"
    AMQP_HOST: "${GLOBAL_K3S_LIVE_RABBIT_HOST}"
    AMQP_PORT: "${GLOBAL_K3S_LIVE_RABBIT_PORT}"
    AMQP_USER: "${GLOBAL_K3S_LIVE_RABBIT_USER}"
    AMQP_PASS: "${GLOBAL_K3S_LIVE_RABBIT_PASSWORD}"
    AMQP_VHOST: "easyweek-live"
    AMQP_PUBLISHER_ENABLED: "true"
    AMQP_CONSUMER_HEARTBEAT_URL: "https://heartbeat.uptimerobot.com/..."
    AMQP_PRIVATE_KEY: "${GLOBAL_LIVE_AMQP_PRIVATE_KEY}"
    AMQP_PUBLIC_KEY: "${GLOBAL_LIVE_AMQP_PUBLIC_KEY}"

    # Redis
    REDIS_HOST: "${GLOBAL_K3S_LIVE_REDIS_HOST}"
    REDIS_PORT: "${GLOBAL_K3S_LIVE_REDIS_PORT}"
    REDIS_PASSWORD: "${GLOBAL_K3S_LIVE_REDIS_PASSWORD}"

    # Observability
    SENTRY_DSN: "https://your-sentry-dsn-here"

    # StatsD Metrics (LIVE - reduced sampling for production)
    STATSD_ENABLED: "true"
    STATSD_PORT: "8125"
    STATSD_SAMPLE_OK: "0.1"        # 10% sampling in production
    STATSD_SAMPLE_PAYLOAD: "0.1"   # 10% payload sampling

  <<: *deploy_script
  rules:
    - if: '$CI_COMMIT_TAG'
      changes:
        - "packages/providers/myservice/**/*"
        - "shared/**/*"
      when: manual
```

---

## How It Works: envsubst Flow

```
1. CI/CD Pipeline Triggered
   ↓
2. GitLab CI reads variables from .gitlab-ci.yml
   STATSD_ENABLED=true
   STATSD_PORT=8125
   STATSD_SAMPLE_OK=0.1
   etc.
   ↓
3. envsubst replaces ${STATSD_ENABLED} in env.tmpl
   STATSD_ENABLED: "${STATSD_ENABLED}"
   becomes:
   STATSD_ENABLED: "true"
   ↓
4. Generated env.yaml applied to Kubernetes
   ↓
5. ConfigMap created with actual values
   ↓
6. Pod starts with ENV variables from ConfigMap
   + STATSD_HOST from status.hostIP (Downward API)
   ↓
7. nano-service reads ENV and enables metrics
   ↓
8. Metrics sent to statsd-exporter DaemonSet
```

---

## Environment Variables Reference

### Required in CI/CD Variables

| Variable | E2E Value | Live Value | Description |
|----------|-----------|------------|-------------|
| `STATSD_ENABLED` | `"true"` | `"true"` | Enable metrics |
| `STATSD_PORT` | `"8125"` | `"8125"` | StatsD UDP port |
| `STATSD_SAMPLE_OK` | `"1.0"` | `"0.1"` | Success sampling rate |
| `STATSD_SAMPLE_PAYLOAD` | `"0.1"` | `"0.1"` | Payload sampling rate |

### Hard-Coded in Templates

| Variable | Value | Location |
|----------|-------|----------|
| `STATSD_NAMESPACE` | `"myservice"` | env.tmpl (hard-coded) |
| `STATSD_HOST` | `status.hostIP` | deployment.tmpl (Downward API) |

---

## Deployment Workflow

### Step-by-Step

1. **Update templates** (env.tmpl, deployment.tmpl)
2. **Update .gitlab-ci.yml** with StatsD variables
3. **Commit changes** to git
4. **Create release tag** (`git tag v1.0.0 && git push --tags`)
5. **CI/CD pipeline runs:**
   - Builds Docker image
   - Generates manifests with `envsubst`
   - Applies to Kubernetes
6. **Pods restart** with new ENV variables
7. **Metrics flow:** nano-service → statsd-exporter → Prometheus → Grafana

---

## Verification After Deployment

### 1. Check Environment Variables

```bash
# Get pod name
kubectl get pods -n live -l app=myservice-live-live

# Check ENV vars
kubectl exec -n live <pod-name> -- env | grep STATSD

# Expected output:
# STATSD_ENABLED=true
# STATSD_HOST=10.192.0.XX  (node IP)
# STATSD_PORT=8125
# STATSD_NAMESPACE=myservice
# STATSD_SAMPLE_OK=0.1
# STATSD_SAMPLE_PAYLOAD=0.1
```

### 2. Check Metrics in Prometheus

```bash
# In Prometheus UI or Grafana, query:
rabbitmq_publish_total{service="myservice"}
rabbitmq_connection_active{service="myservice"}
rabbitmq_event_started_total{nano_service_name="myservice-live"}
```

### 3. Check Application Logs

```bash
# Check for errors
kubectl logs -n live <pod-name> --tail=50 | grep -i statsd

# Should see no errors related to StatsD
```

---

## Troubleshooting

### Metrics Not Appearing

**Check 1: ENV variables set correctly?**
```bash
kubectl exec -n live <pod> -- env | grep STATSD
```

**Check 2: Can reach statsd-exporter?**
```bash
# Test UDP from pod
kubectl exec -n live <pod> -- sh -c 'echo "test:1|c" | nc -u $STATSD_HOST $STATSD_PORT'

# Check if received
NODE=$(kubectl get pod -n live <pod> -o jsonpath='{.spec.nodeName}')
EXPORTER_POD=$(kubectl get pod -n monitoring -l app=statsd-exporter -o json | jq -r ".items[] | select(.spec.nodeName==\"$NODE\") | .metadata.name")
kubectl logs -n monitoring $EXPORTER_POD --tail=50 | grep test
```

**Check 3: Is statsd-exporter running?**
```bash
kubectl get pods -n monitoring -l app=statsd-exporter
```

---

## Migration from Sidecar Pattern

If you currently have statsd-exporter as a sidecar:

### Old Pattern (Sidecar)

```yaml
spec:
  containers:
  - name: app
    env:
    - name: STATSD_HOST
      value: "127.0.0.1"  # Localhost sidecar

  - name: statsd-exporter  # Sidecar container
    image: prom/statsd-exporter:v0.22.8
```

### New Pattern (DaemonSet)

```yaml
spec:
  containers:
  - name: app
    env:
    - name: STATSD_HOST
      valueFrom:
        fieldRef:
          fieldPath: status.hostIP  # Node IP

  # No statsd-exporter sidecar needed!
```

**Benefits:**
- ✅ Fewer containers per pod
- ✅ Lower resource usage
- ✅ Centralized management
- ✅ Easier to update mapping rules

---

## Example: Real-World reminder_platform

See the reminder_platform repository for complete examples:

**Provider service:**
- `packages/providers/alphasms/deploy/env.tmpl`
- `packages/providers/alphasms/deploy/deployment.tmpl`
- `packages/providers/alphasms/.gitlab-ci.yml`

**Router service:**
- `packages/router/deploy/env.tmpl`
- `packages/router/deploy/deployment.tmpl`
- `packages/router/.gitlab-ci.yml`

**Core service:**
- `packages/core/deploy/env.tmpl`
- `packages/core/deploy/consumer/deployment-sender.tmpl`
- `packages/core/.gitlab-ci.yml`

---

## Best Practices

### 1. Hard-Code STATSD_NAMESPACE in Templates

✅ **Good:**
```yaml
STATSD_NAMESPACE: "myservice"  # Hard-coded in template
```

❌ **Bad:**
```yaml
STATSD_NAMESPACE: "${STATSD_NAMESPACE}"  # Dynamic (might be wrong)
```

**Why?** Each service should have a unique, known namespace for metrics.

### 2. Use Different Sampling for E2E vs Live

✅ **Good:**
```yaml
# E2E
STATSD_SAMPLE_OK: "1.0"  # 100% - full visibility for testing

# Live
STATSD_SAMPLE_OK: "0.1"  # 10% - reduced overhead in production
```

### 3. STATSD_HOST Must Use fieldRef

✅ **Good:**
```yaml
env:
- name: STATSD_HOST
  valueFrom:
    fieldRef:
      fieldPath: status.hostIP
```

❌ **Bad:**
```yaml
STATSD_HOST: "${STATSD_HOST}"  # In ConfigMap - won't work with DaemonSet
```

**Why?** Node IP is dynamic and pod-specific, can't be in ConfigMap.

### 4. Disable by Default for Safe Rollout

Start with `STATSD_ENABLED=false` in CI/CD variables, enable gradually:

```yaml
# Week 1: Deploy with metrics disabled
STATSD_ENABLED: "false"

# Week 2: Enable for e2e
# (e2e job: STATSD_ENABLED: "true")

# Week 3: Enable for live
# (live job: STATSD_ENABLED: "true")
```

---

## Reference

- [METRICS.md](METRICS.md) - Complete metrics documentation
- [CONFIGURATION.md](CONFIGURATION.md) - Configuration reference
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md) - Common issues
- DevOps Task: `2026-01-19_RABBIMQ_EVENT_METRICS` - Implementation details

---

## Support

For deployment issues:
- Check this guide first
- Review [TROUBLESHOOTING.md](TROUBLESHOOTING.md)
- Contact your DevOps team for infrastructure
- Check GitLab CI/CD pipeline logs
