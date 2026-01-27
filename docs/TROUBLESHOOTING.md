# Troubleshooting Guide

**Version:** 6.5+
**Last Updated:** 2026-01-27

---

## Metrics Issues

### Metrics Not Appearing

**Checklist:**

1. **Check STATSD_ENABLED:**
   ```bash
   kubectl exec <pod> -- env | grep STATSD_ENABLED
   # Must be: true
   ```

2. **Check connectivity:**
   ```bash
   kubectl exec <pod> -- sh -c 'echo "test:1|c" | nc -u $STATSD_HOST $STATSD_PORT'
   kubectl logs -n monitoring -l app=statsd-exporter --tail=50 | grep test
   ```

3. **Check statsd-exporter:**
   ```bash
   kubectl get pods -n monitoring -l app=statsd-exporter
   ```

4. **Check Prometheus scraping:**
   ```promql
   up{job="monitoring/statsd-exporter"}
   ```

5. **Query any metrics:**
   ```promql
   {__name__=~".*", service="myservice"}
   ```

### Wrong Metric Values

**Common causes:**

1. **Sampling interpretation:**
   ```promql
   # Use rate() for true values
   rate(rabbitmq_publish_total{service="myservice"}[5m])
   ```

2. **Namespace collision:** Check `STATSD_NAMESPACE` is unique per service

3. **High cardinality:** Check for user_id, request_id in tags

### High Packet Drop Rate

If `statsd_exporter_packets_dropped_total` increasing:

1. **Increase exporter resources:**
   ```yaml
   resources:
     limits:
       cpu: 1000m
       memory: 1Gi
   ```

2. **Reduce sampling:**
   ```bash
   STATSD_SAMPLE_OK=0.01
   ```

3. **Check network latency:**
   ```bash
   kubectl exec <app-pod> -- ping $STATSD_HOST
   ```

---

## RabbitMQ Issues

### Connection Refused

1. **Check RabbitMQ server:**
   ```bash
   kubectl get pods -n rabbitmq
   ```

2. **Test connectivity:**
   ```bash
   kubectl exec <pod> -- nc -zv $AMQP_HOST $AMQP_PORT
   ```

3. **Check credentials:**
   ```bash
   kubectl exec <pod> -- env | grep AMQP_USER
   ```

**Metrics to check:**
```promql
rabbitmq_connection_errors_total{service="myservice"}
rabbitmq_connection_active{service="myservice"} == 0
```

### Channel Exhaustion

**Note:** Fixed in v6.0+ (Jan 2026). Shouldn't occur with current version.

**If still happening:**

1. **Check version:**
   ```bash
   composer show yevhenlisovenko/nano-service
   ```

2. **Check channel gauge:**
   ```promql
   rabbitmq_channel_active{service="myservice"}
   # Should be 0 or 1, not growing
   ```

3. **See:** [CHANGELOG.md](CHANGELOG.md) - Channel leak fix details

### Messages Going to DLX

High `rabbitmq_consumer_dlx_total`:

1. **Check retry config:**
   ```php
   $consumer->tries(5);  // Increase if needed
   ```

2. **Check logs for errors:**
   ```bash
   kubectl logs <pod> | grep -i error
   ```

3. **Check metrics:**
   ```promql
   sum by (reason) (rabbitmq_consumer_dlx_total{nano_service_name="myservice"})
   ```

### ACK Failures

Causes:
- Long-running event handlers
- Connection lost during processing
- Channel timeout

**Solutions:**

1. **Reduce processing time:**
   ```php
   $consumer->consume(function ($message) {
       dispatch(new ProcessJob($message));  // Quick ACK, process async
   });
   ```

2. **Check metrics:**
   ```promql
   rate(rabbitmq_consumer_ack_failed_total[5m])
   ```

---

## Performance Issues

### High CPU After Enabling Metrics

1. **Check sampling:**
   ```bash
   echo $STATSD_SAMPLE_OK
   ```

2. **Reduce for high-volume:**
   ```bash
   STATSD_SAMPLE_OK=0.01  # 1%
   ```

3. **Check event rate:**
   ```promql
   rate(rabbitmq_publish_total{service="myservice"}[5m])
   ```

### High Memory Usage

Expected: +10-20 MB for StatsD client

**If higher:**

1. **Test with metrics disabled:**
   ```bash
   kubectl set env deployment <service> STATSD_ENABLED=false
   kubectl top pod <pod>
   ```

2. **Review application code for leaks**

---

## Common Errors

### Class Not Found

```
Class 'AlexFN\NanoService\Config\StatsDConfig' not found
```

**Solution:** Update to v6.0+
```bash
composer update yevhenlisovenko/nano-service
```

### Undefined Method

```
Call to undefined method StatsDClient::increment()
```

**Solution:**
```bash
composer clear-cache
composer update yevhenlisovenko/nano-service
```

### Missing Environment Variables

```
RuntimeException: Missing required StatsD environment variables
```

**Solution:** Set all required variables when `STATSD_ENABLED=true`:
- `STATSD_HOST`
- `STATSD_PORT`
- `STATSD_NAMESPACE`
- `STATSD_SAMPLE_OK`
- `STATSD_SAMPLE_PAYLOAD`

---

## Debugging

### Test Locally

```bash
# Run statsd-exporter
docker run -d -p 8125:8125/udp -p 9102:9102 prom/statsd-exporter:v0.26.0

# Configure
export STATSD_ENABLED=true
export STATSD_HOST=127.0.0.1
export STATSD_PORT=8125
export STATSD_NAMESPACE=test

# Run and check
php consumer.php
curl http://localhost:9102/metrics | grep rabbitmq
```

### Verify End-to-End

```bash
# 1. Service sends
kubectl exec <app-pod> -- sh -c 'echo "test:1|c" | nc -u $STATSD_HOST $STATSD_PORT'

# 2. Exporter receives
kubectl logs -n monitoring <statsd-pod>

# 3. Prometheus scrapes
# Query: {__name__=~".*test.*"}

# 4. Grafana displays
# Explore: {__name__=~".*test.*"}
```

---

## Getting Help

### Before Asking

1. Read this guide
2. Read [METRICS.md](METRICS.md)
3. Read [CONFIGURATION.md](CONFIGURATION.md)
4. Check application logs
5. Check Prometheus/Grafana

### Bug Report Template

```markdown
**Environment:**
- nano-service version:
- Kubernetes cluster:
- Service name:

**Configuration:**
- STATSD_ENABLED:
- STATSD_HOST:
- STATSD_NAMESPACE:

**Issue:**
- Affected metrics:
- Expected behavior:
- Actual behavior:

**Prometheus Query:**
(paste query)

**Logs:**
(paste relevant logs)
```

---

## References

- [Metrics Documentation](METRICS.md)
- [Configuration Guide](CONFIGURATION.md)
- [Changelog](CHANGELOG.md)
