# nano-service Troubleshooting Guide

**Version:** 6.0+
**Last Updated:** 2026-01-19

---

## Metrics Issues

### Metrics Not Appearing in Prometheus

**Symptom:** No metrics visible in Prometheus/Grafana after enabling StatsD.

**Checklist:**

1. **Is STATSD_ENABLED=true?**
   ```bash
   kubectl exec <pod> -- env | grep STATSD_ENABLED
   # Should output: STATSD_ENABLED=true
   ```

2. **Can service reach StatsD server?**
   ```bash
   # Test UDP connectivity
   kubectl exec <pod> -- sh -c 'echo "test:1|c" | nc -u $STATSD_HOST $STATSD_PORT'

   # Check if packet was received
   kubectl logs -n monitoring -l app=statsd-exporter --tail=50 | grep test
   ```

3. **Is statsd-exporter running?**
   ```bash
   kubectl get pods -n monitoring -l app=statsd-exporter
   # Should show Running pods on all nodes
   ```

4. **Is Prometheus scraping statsd-exporter?**
   ```bash
   # In Prometheus UI: Status > Targets
   # Search for "statsd-exporter", should be UP

   # Or via query:
   up{job="monitoring/statsd-exporter"}
   # Should return 1 for each node
   ```

5. **Check metric naming:**
   ```bash
   # Query for ANY metrics from your service
   {__name__=~".*", service="myservice"}

   # Or check statsd-exporter directly
   kubectl exec -n monitoring <statsd-pod> -- wget -O- http://localhost:9102/metrics | grep myservice
   ```

---

### Metrics Showing But Wrong Values

**Issue:** Metrics appear but values look incorrect.

**Common Causes:**

1. **Wrong sampling rate interpretation:**
   - Sampled metrics show actual sampled count, not extrapolated
   - Use `rate()` in Prometheus queries to get true rate

   ```promql
   # Wrong (shows sampled count)
   rabbitmq_publish_total{service="myservice"}

   # Correct (shows true rate)
   rate(rabbitmq_publish_total{service="myservice"}[5m])
   ```

2. **Multiple services using same namespace:**
   - Check `STATSD_NAMESPACE` is unique per service
   - Metrics will be aggregated if namespace is shared

3. **Cardinality explosion:**
   - Check for high-cardinality tags (user_id, request_id, etc.)
   - Review event names for uniqueness

---

### High Packet Drop Rate

**Symptom:** `statsd_exporter_packets_dropped_total` increasing.

**Solutions:**

1. **Increase statsd-exporter resources:**
   ```yaml
   # In DaemonSet manifest
   resources:
     limits:
       cpu: 1000m      # Increase from 500m
       memory: 1Gi     # Increase from 512Mi
   ```

2. **Reduce application sampling:**
   ```bash
   STATSD_SAMPLE_OK=0.01  # Reduce from 0.1 to 0.01 (1%)
   ```

3. **Check network issues:**
   ```bash
   # From application pod
   ping $STATSD_HOST

   # Check packet loss
   kubectl exec <app-pod> -- sh -c 'for i in $(seq 1 100); do echo "test:1|c" | nc -u $STATSD_HOST $STATSD_PORT; done'
   ```

---

## RabbitMQ Connection Issues

### Connection Refused

**Symptom:** Service can't connect to RabbitMQ.

**Check:**

1. **RabbitMQ server is running:**
   ```bash
   kubectl get pods -n rabbitmq
   kubectl logs -n rabbitmq <rabbitmq-pod>
   ```

2. **Correct host/port:**
   ```bash
   # From service pod
   kubectl exec <pod> -- nc -zv $AMQP_HOST $AMQP_PORT
   ```

3. **Network policies:**
   ```bash
   # Check if network policies allow traffic
   kubectl get networkpolicies -n <namespace>
   ```

4. **Credentials:**
   ```bash
   # Verify credentials (DO NOT echo password in prod!)
   kubectl exec <pod> -- env | grep AMQP_USER
   ```

**Metrics to check:**
```promql
# Connection errors
rabbitmq_connection_errors_total{service="myservice"}

# No active connections (alert!)
rabbitmq_connection_active{service="myservice"} == 0
```

---

### Channel Exhaustion

**Symptom:** Error: "too many channels" or channel creation fails.

**This should NOT happen in v6.0+** (channel leak fixed Jan 16, 2026)

**If it still happens:**

1. **Check channel gauge:**
   ```promql
   rabbitmq_channel_active{service="myservice"}
   # Should be 0 or 1, not growing
   ```

2. **Check for channel errors:**
   ```promql
   rate(rabbitmq_channel_errors_total{service="myservice"}[5m])
   ```

3. **Verify you're on v6.0+:**
   ```bash
   # Check composer.json or package version
   composer show yevhenlisovenko/nano-service
   ```

4. **Contact DevOps:** Reference incident `2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2`

---

### Messages Going to DLX

**Symptom:** High `rabbitmq_consumer_dlx_total` rate.

**Causes:**

1. **Max retries exceeded:**
   ```php
   // Check retry configuration
   $consumer->tries(3);  // Default: 3 retries

   // Increase if needed
   $consumer->tries(5);
   ```

2. **Processing errors:**
   ```bash
   # Check application logs for exceptions
   kubectl logs <pod> | grep -i error
   ```

3. **DLX queue filling up:**
   ```bash
   # Check DLX queue depth in RabbitMQ Management UI
   # Queue name: <project>.<service>.failed
   ```

**Metrics to check:**
```promql
# DLX rate
rate(rabbitmq_consumer_dlx_total{nano_service_name="myservice"}[5m])

# By reason
sum by (reason) (rabbitmq_consumer_dlx_total{nano_service_name="myservice"})
```

---

### ACK Failures

**Symptom:** `rabbitmq_consumer_ack_failed_total` increasing.

**Causes:**

1. **Channel closed during processing:**
   - Long-running event handlers
   - RabbitMQ connection lost
   - Channel timeout

2. **Network issues:**
   - Unstable connection to RabbitMQ
   - High latency

**Solutions:**

1. **Reduce processing time:**
   ```php
   // Move heavy work outside message handler
   $consumer->consume(function ($message) {
       // Quick ACK, then process async
       dispatch(new ProcessMessageJob($message));
   });
   ```

2. **Increase heartbeat:**
   - Already set to 180s in nano-service
   - Contact DevOps if issues persist

**Metrics to check:**
```promql
# ACK failure rate
rate(rabbitmq_consumer_ack_failed_total{nano_service_name="myservice"}[5m])

# Channel errors around same time
rabbitmq_channel_errors_total{service="myservice"}
```

---

## Performance Issues

### High CPU Usage

**Symptom:** Service CPU usage increased after enabling metrics.

**Check:**

1. **Sampling configuration:**
   ```bash
   echo $STATSD_SAMPLE_OK
   # If 1.0 and high volume, reduce to 0.1 or 0.01
   ```

2. **Event volume:**
   ```promql
   rate(rabbitmq_publish_total{service="myservice"}[5m])
   # If >1000/sec, use aggressive sampling (0.01)
   ```

**Solution:**
```bash
# Reduce sampling for high-volume services
STATSD_SAMPLE_OK=0.01  # 1% sampling
STATSD_SAMPLE_PAYLOAD=0.01
```

---

### High Memory Usage

**Symptom:** Service memory usage increased.

**Expected:** +10-20 MB for StatsD client buffers

**If higher:**

1. **Check for metric accumulation:**
   ```bash
   # Disable metrics temporarily
   kubectl set env deployment <service> STATSD_ENABLED=false

   # Monitor memory usage
   kubectl top pod <pod>
   ```

2. **Check for memory leaks:**
   - Review application code
   - May not be related to nano-service metrics

---

## Common Errors

### "Class 'AlexFN\NanoService\Config\StatsDConfig' not found"

**Cause:** Old version of nano-service (< v6.0)

**Solution:**
```bash
composer update yevhenlisovenko/nano-service
```

### "Call to undefined method StatsDClient::increment()"

**Cause:** Using old StatsDClient with new code

**Solution:**
```bash
# Clear composer cache
composer clear-cache

# Update dependency
composer update yevhenlisovenko/nano-service

# Verify version
composer show yevhenlisovenko/nano-service
```

### Warning: "Undefined array key 'host' in StatsDClient"

**Cause:** StatsDClient constructed without config when metrics disabled

**Solution:** This is normal if `STATSD_ENABLED=false`. The warning is harmless.

To suppress:
```php
// StatsD client already handles this gracefully in v6.0+
// If you still see warnings, upgrade to v6.0+
```

---

## Debugging Tips

### Enable Debug Logging

```php
// Add logging in NanoPublisher/NanoConsumer
if ($this->statsD && $this->statsD->isEnabled()) {
    error_log("StatsD metrics enabled for service: " . $this->getEnv(self::MICROSERVICE_NAME));
}
```

### Test Metrics Locally

```bash
# 1. Run statsd-exporter locally
docker run -d -p 8125:8125/udp -p 9102:9102 \
  prom/statsd-exporter:v0.26.0

# 2. Configure service
export STATSD_ENABLED=true
export STATSD_HOST=127.0.0.1
export STATSD_PORT=8125
export STATSD_NAMESPACE=test

# 3. Run service
php consumer.php

# 4. Check metrics
curl http://localhost:9102/metrics | grep rabbitmq

# 5. Send test metric manually
echo "test.metric:1|c" | nc -u 127.0.0.1 8125
curl http://localhost:9102/metrics | grep test_metric
```

### Verify Metric Flow End-to-End

```bash
# 1. Check service is sending
kubectl exec <app-pod> -- sh -c 'echo "test:1|c" | nc -u $STATSD_HOST $STATSD_PORT'

# 2. Check statsd-exporter received
kubectl logs -n monitoring <statsd-pod> --tail=100

# 3. Check statsd-exporter metrics endpoint
kubectl exec -n monitoring <statsd-pod> -- wget -O- http://localhost:9102/metrics | grep test

# 4. Check Prometheus scraped
# In Prometheus UI query: {__name__=~".*test.*"}

# 5. Check Grafana can query
# In Grafana Explore: {__name__=~".*test.*"}
```

---

## Getting Help

### Before Asking for Help

1. ✅ Read [METRICS.md](METRICS.md)
2. ✅ Read [CONFIGURATION.md](CONFIGURATION.md)
3. ✅ Read this troubleshooting guide
4. ✅ Check Prometheus/Grafana for metrics
5. ✅ Check statsd-exporter logs
6. ✅ Check application logs

### What to Include in Bug Reports

```markdown
**Environment:**
- nano-service version: (run `composer show yevhenlisovenko/nano-service`)
- Kubernetes cluster: (live/e2e/infra)
- Service name: (value of AMQP_MICROSERVICE_NAME)

**Configuration:**
- STATSD_ENABLED:
- STATSD_HOST:
- STATSD_NAMESPACE:
- STATSD_SAMPLE_OK:

**Issue:**
- What metrics are affected:
- Expected behavior:
- Actual behavior:
- Error messages (if any):

**Prometheus Query:**
(Paste the query that shows the issue)

**Logs:**
(Paste relevant logs from service and statsd-exporter)
```

---

## Reference

- [METRICS.md](METRICS.md) - Complete metrics documentation
- [CONFIGURATION.md](CONFIGURATION.md) - Configuration guide
- [CLAUDE.md](../CLAUDE.md) - Development guidelines
- DevOps Task: `2026-01-19_RABBIMQ_EVENT_METRICS` - Implementation details
- Incident: `2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2` - Channel leak fix
