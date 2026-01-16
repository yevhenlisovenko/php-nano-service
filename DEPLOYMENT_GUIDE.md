# Deployment Guide: Channel Leak Fix

**Version:** 1.x.x+1 (patch version)
**Priority:** CRITICAL
**Estimated Time:** 2-3 hours (including monitoring)

---

## Pre-Deployment Checklist

- [ ] Code reviewed by at least 1 developer
- [ ] Unit tests passing (if available)
- [ ] Staging/E2E environment available for testing
- [ ] Rollback plan documented
- [ ] Monitoring dashboard ready
- [ ] Team notified of deployment window

---

## Step 1: Commit and Tag the Fix

```bash
cd repos/nano-service-main

# Check the changes
git status
git diff src/NanoServiceClass.php

# Stage the changes
git add src/NanoServiceClass.php
git add CHANGELOG_CHANNEL_FIX.md
git add DEPLOYMENT_GUIDE.md

# Commit with clear message
git commit -m "fix: prevent channel leak in getChannel() method

- Store newly created channels in self::\$sharedChannel for reuse
- Add destructor to clean up orphaned instance channels
- Fixes RabbitMQ 'No free channel ids' errors in production

Impact: Reduces channel count from ~18k to ~500 (97% reduction)

Related incident: 2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2"

# Tag the release (bump patch version)
git tag v1.2.4  # Replace with your actual version
git push origin main
git push origin v1.2.4
```

---

## Step 2: Update Applications (Composer)

### Option A: If nano-service is published to Packagist

**In each application that uses nano-service:**

```bash
# Update composer.json (if version constraint allows)
# "yevhenlisovenko/nano-service": "^1.2.0" will auto-update to 1.2.4

# Or explicitly update
composer require yevhenlisovenko/nano-service:^1.2.4

# Verify the version
composer show yevhenlisovenko/nano-service
# Should show v1.2.4

# Commit the lock file
git add composer.lock
git commit -m "chore: update nano-service to v1.2.4 (channel leak fix)"
```

### Option B: If nano-service is a local/private package

**Update composer.json to point to the fixed version:**

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../nano-service-main"
    }
  ],
  "require": {
    "yevhenlisovenko/nano-service": "^1.2.4"
  }
}
```

Then run:
```bash
composer update yevhenlisovenko/nano-service
```

---

## Step 3: Deploy to Staging/E2E

**Priority order (deploy highest impact first):**

1. provider-easyweek-live (highest throughput)
2. provider-sendgrid-live (high throughput)
3. supervisor-rabbitmq (medium throughput)
4. All other consumers/providers

### Deployment Commands

```bash
# Switch to E2E/staging cluster
kubectl config use-context easyweek-k3s-e2e

# Build and push new Docker image (adjust to your CI/CD process)
docker build -t registry.example.com/provider-easyweek:v1.2.4 .
docker push registry.example.com/provider-easyweek:v1.2.4

# Deploy
kubectl set image deployment/provider-easyweek-e2e-e2e \
  provider=registry.example.com/provider-easyweek:v1.2.4 \
  -n e2e

# Wait for rollout
kubectl rollout status deployment/provider-easyweek-e2e-e2e -n e2e
```

---

## Step 4: Monitor Staging

**Monitor for 30-60 minutes before production deployment:**

### Check Channel Count

```bash
# Get baseline
BASELINE=$(kubectl exec -n rabbitmq rabbitmq-0 -- rabbitmqctl list_channels 2>/dev/null | wc -l)
echo "Baseline channels: $BASELINE"

# Send test traffic (1000 messages)
for i in {1..1000}; do
  kubectl exec -n e2e provider-easyweek-e2e-xxx -- php artisan test:publish
done

# Check channel count after test
AFTER=$(kubectl exec -n rabbitmq rabbitmq-0 -- rabbitmqctl list_channels 2>/dev/null | wc -l)
echo "After 1000 messages: $AFTER"
echo "Increase: $((AFTER - BASELINE)) channels"

# Expected: Increase of 1-5 channels (NOT 1000!)
```

### Check for Errors

```bash
# Check for "No free channel ids" errors
kubectl logs -n e2e --selector=app=provider-easyweek-e2e --since=30m | grep -i "no free channel"
# Expected: No results

# Check for any RabbitMQ errors
kubectl logs -n e2e --selector=app=provider-easyweek-e2e --since=30m | grep -i "rabbitmq\|amqp" | grep -i "error"
# Expected: No new errors
```

### Check Channels Per Connection

```bash
kubectl exec -n rabbitmq rabbitmq-0 -- rabbitmqctl list_connections peer_host channels 2>/dev/null | \
  grep "10.196" | awk '{sum+=$2; count++} END {print "Avg channels/connection:", sum/count}'

# Expected: < 10 channels per connection (down from 56-2,047)
```

---

## Step 5: Production Deployment (Canary)

**Only proceed if staging tests are successful!**

### Canary Deployment (10% of traffic)

```bash
# Switch to production cluster
kubectl config use-context easyweek-k3s-live

# Get current replica count
REPLICAS=$(kubectl get deployment provider-easyweek-live-live -n live -o jsonpath='{.spec.replicas}')
echo "Current replicas: $REPLICAS"

# Deploy canary (1 pod with new version)
kubectl set image deployment/provider-easyweek-live-live \
  provider=registry.example.com/provider-easyweek:v1.2.4 \
  -n live

# Pause rollout after 1 pod
kubectl rollout pause deployment/provider-easyweek-live-live -n live

# Wait for new pod to be ready
kubectl wait --for=condition=ready pod \
  -l app=provider-easyweek-live,version=v1.2.4 \
  -n live \
  --timeout=5m
```

### Monitor Canary (1 hour)

```bash
# Monitor channel count every minute
watch -n 60 'kubectl exec -n rabbitmq rabbitmq-0 -- rabbitmqctl list_channels 2>/dev/null | wc -l'

# Check canary pod logs
CANARY_POD=$(kubectl get pods -n live -l app=provider-easyweek-live -o jsonpath='{.items[0].metadata.name}')
kubectl logs -n live $CANARY_POD -f | grep -i "error\|channel"

# Check for errors in last 15 minutes
kubectl logs -n live $CANARY_POD --since=15m | grep -i "no free channel"
```

**Success Criteria for Canary:**
- ✅ No "No free channel ids" errors
- ✅ Normal message processing rate
- ✅ No increase in other error types
- ✅ Channel count stable or decreasing
- ✅ Response times normal

**If canary fails:**
```bash
# Rollback immediately
kubectl rollout undo deployment/provider-easyweek-live-live -n live
kubectl rollout status deployment/provider-easyweek-live-live -n live
```

---

## Step 6: Full Production Rollout

**Only proceed if canary was successful for 1 hour!**

```bash
# Resume rollout to all pods
kubectl rollout resume deployment/provider-easyweek-live-live -n live

# Watch the rollout progress
kubectl rollout status deployment/provider-easyweek-live-live -n live --watch

# Expected output:
# Waiting for deployment "provider-easyweek-live-live" rollout to finish: 1 out of 3 new replicas have been updated...
# Waiting for deployment "provider-easyweek-live-live" rollout to finish: 2 out of 3 new replicas have been updated...
# Waiting for deployment "provider-easyweek-live-live" rollout to finish: 3 out of 3 new replicas have been updated...
# deployment "provider-easyweek-live-live" successfully rolled out
```

---

## Step 7: Deploy to Other Services

Repeat Steps 5-6 for each service in priority order:

```bash
# 2. provider-sendgrid-live
kubectl set image deployment/provider-sendgrid-live-live provider=... -n live

# 3. supervisor-rabbitmq
kubectl set image deployment/easyweek-service-supervisor-rabbitmq supervisor=... -n live

# 4. All remaining services
# ... repeat for each service
```

---

## Step 8: Post-Deployment Verification

### Verify Channel Count Reduction

```bash
# Get current metrics
kubectl exec -n rabbitmq rabbitmq-0 -- rabbitmqctl list_connections 2>/dev/null | wc -l
kubectl exec -n rabbitmq rabbitmq-0 -- rabbitmqctl list_channels 2>/dev/null | wc -l

# Calculate ratio
CONNECTIONS=$(kubectl exec -n rabbitmq rabbitmq-0 -- rabbitmqctl list_connections 2>/dev/null | wc -l)
CHANNELS=$(kubectl exec -n rabbitmq rabbitmq-0 -- rabbitmqctl list_channels 2>/dev/null | wc -l)
RATIO=$((CHANNELS / CONNECTIONS))
echo "Channels per connection: $RATIO"

# Expected results:
# - Total channels: 400-800 (down from 17,840)
# - Channels per connection: 3-6 (down from 56-2,047)
```

### Check for High Channel Count Pods

```bash
# No connection should have > 50 channels
kubectl exec -n rabbitmq rabbitmq-0 -- rabbitmqctl list_connections peer_host channels 2>/dev/null | \
  grep "10.196" | awk '$2 > 50 {print "WARNING: High channel count:", $0}'

# Expected: No output
```

### Verify No Errors

```bash
# Check all provider pods
kubectl logs -n live --selector=app.kubernetes.io/component=provider --since=1h | \
  grep -i "no free channel" | wc -l

# Expected: 0
```

---

## Step 9: Long-term Monitoring

### Set Up Prometheus Alert (If Available)

```yaml
groups:
- name: rabbitmq
  rules:
  - alert: RabbitMQHighChannelsPerConnection
    expr: |
      sum(rabbitmq_channels) / sum(rabbitmq_connections) > 20
    for: 15m
    labels:
      severity: warning
      component: rabbitmq
    annotations:
      summary: "RabbitMQ high channel/connection ratio"
      description: "Current ratio: {{ $value | humanize }}. Expected: <10"
      runbook: "Check for channel leaks in application code"

  - alert: RabbitMQChannelExhaustion
    expr: |
      max(rabbitmq_channels_per_connection) > 2000
    for: 5m
    labels:
      severity: critical
      component: rabbitmq
    annotations:
      summary: "RabbitMQ connection approaching channel limit"
      description: "Connection has {{ $value }} channels (max 2047)"
      runbook: "Restart affected pod immediately, investigate application code"
```

### Create Grafana Dashboard

**Panels to add:**
1. Total RabbitMQ channels (time series)
2. Channels per connection ratio (gauge)
3. Top 10 pods by channel count (table)
4. Channel creation rate (graph)

---

## Rollback Plan

**If issues are detected after deployment:**

### Immediate Rollback

```bash
# Rollback specific deployment
kubectl rollout undo deployment/provider-easyweek-live-live -n live

# Check rollback status
kubectl rollout status deployment/provider-easyweek-live-live -n live

# Verify pods are healthy
kubectl get pods -n live -l app=provider-easyweek-live
```

### Full Rollback (All Services)

```bash
# Rollback all affected deployments
for deployment in provider-easyweek-live-live provider-sendgrid-live-live easyweek-service-supervisor-rabbitmq; do
  echo "Rolling back $deployment..."
  kubectl rollout undo deployment/$deployment -n live
  kubectl rollout status deployment/$deployment -n live
done
```

### Update Composer to Previous Version

```bash
# In each application
composer require yevhenlisovenko/nano-service:1.2.3  # previous version

# Rebuild and redeploy
```

---

## Success Criteria

**Deployment is successful if ALL criteria are met:**

- ✅ Total RabbitMQ channels < 1,000 (down from 17,840)
- ✅ Channels per connection < 10 (down from 56-2,047)
- ✅ No "No free channel ids" errors for 24 hours
- ✅ No increase in error rates
- ✅ Normal message throughput
- ✅ No pods in CrashLoopBackOff
- ✅ All health checks passing

**Monitor for 24 hours before declaring full success.**

---

## Communication Template

**Before deployment:**
```
[MAINTENANCE NOTICE]
When: [Date/Time]
Duration: 2-3 hours
Impact: None expected (rolling deployment)
Reason: Deploying fix for RabbitMQ channel leak issue
Rollback: Available if needed

We'll be deploying a critical fix to prevent RabbitMQ channel exhaustion.
This will be a rolling deployment with no expected downtime.
Monitoring will continue throughout the deployment.
```

**After successful deployment:**
```
[MAINTENANCE COMPLETE]
Deployment Status: ✅ Successful
Impact: Channel count reduced from 17,840 to ~500 (97% reduction)
Issues: None detected
Rollback: Not needed

The RabbitMQ channel leak fix has been successfully deployed.
All services are operating normally with significantly reduced channel usage.
Monitoring will continue for the next 24 hours.
```

---

## Next Steps After Deployment

1. **Monitor for 24-48 hours**
   - Check channel count trend
   - Watch for any new errors
   - Verify pods running without restarts

2. **Document learnings**
   - Update incident postmortem
   - Share findings with team
   - Update runbooks if needed

3. **Schedule follow-up review**
   - Review monitoring data after 1 week
   - Confirm channel count remains stable
   - Consider if additional alerts are needed

4. **Update nano-service docs**
   - Document the channel pooling mechanism
   - Add troubleshooting guide
   - Update README with usage best practices

---

## Questions/Issues During Deployment?

**Contact:**
- DevOps Team: [Contact info]
- On-call Engineer: [Contact info]
- Incident Channel: #incident-2026-01-16-rabbitmq

**References:**
- Incident Report: `incidents/live/2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2/INCIDENT.md`
- Code Analysis: `incidents/live/2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2/CODE_ANALYSIS.md`
- Changelog: `repos/nano-service-main/CHANGELOG_CHANNEL_FIX.md`
