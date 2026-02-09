# Troubleshooting

Common issues and solutions for nano-service v7.0+.

For environment variables, see [CONFIGURATION.md](CONFIGURATION.md). For metric names, see [METRICS.md](METRICS.md).

---

## RabbitMQ

### Connection Refused

**Cause:** RabbitMQ server unreachable or wrong credentials.

**Fix:** Verify `AMQP_HOST`, `AMQP_PORT`, `AMQP_USER`, `AMQP_PASS` — see [CONFIGURATION.md](CONFIGURATION.md).

**Check:** `rmq_connection_errors_total` and `rmq_connection_active` metrics.

### Channel Exhaustion

**Cause:** Fixed in v7.0+ via static shared connections. Should not occur.

**Check:** `rmq_channel_active` should be 0 or 1, never growing. If growing, verify you are on v7.0+.

### Messages Going to DLX

**Cause:** Consumer handler failing after max retries.

**Fix:** Check application logs for errors. Increase `tries()` if transient failures. Review `rmq_consumer_dlx_total` metric grouped by `reason` tag.

### ACK Failures

**Cause:** Long-running event handlers, connection lost during processing, or channel timeout.

**Fix:** Reduce processing time in consumer handlers. Dispatch heavy work to async jobs. Check `rmq_consumer_ack_failed_total` metric.

---

## PostgreSQL

### Connection Refused

**Cause:** Database unreachable or wrong credentials.

**Fix:** Verify all `DB_BOX_*` variables — see [CONFIGURATION.md](CONFIGURATION.md).

### Missing Environment Variables

**Error:** `RuntimeException: Missing required environment variable: DB_BOX_HOST`

**Fix:** All 6 `DB_BOX_*` variables are required: `DB_BOX_HOST`, `DB_BOX_PORT`, `DB_BOX_NAME`, `DB_BOX_USER`, `DB_BOX_PASS`, `DB_BOX_SCHEMA`.

### Outbox/Inbox Table Missing

**Cause:** Schema or tables not created in database.

**Fix:** Create outbox/inbox tables in the schema specified by `DB_BOX_SCHEMA`.

---

## Metrics

### Metrics Not Appearing

**Checklist:**
1. `STATSD_ENABLED` must be `true`
2. `STATSD_HOST` must resolve (use `status.hostIP` in Kubernetes)
3. statsd-exporter DaemonSet must be running on the node
4. Prometheus must be scraping statsd-exporter on port 9102

### Wrong Metric Values

**Common causes:**
- Sampling — use `rate()` in Prometheus for true values
- Namespace collision — `STATSD_NAMESPACE` must be unique per service
- High cardinality — never use user_id, request_id, UUID as tags

### High Packet Drop Rate

**Indicator:** `statsd_exporter_packets_dropped_total` increasing.

**Fix:** Reduce sampling rate (`STATSD_SAMPLE_OK=0.01`) or increase statsd-exporter resources.

### Missing StatsD Environment Variables

**Error:** `RuntimeException: Missing required StatsD environment variables`

**Fix:** When `STATSD_ENABLED=true`, all StatsD variables become required — see [CONFIGURATION.md](CONFIGURATION.md).

---

## Performance

### High CPU After Enabling Metrics

**Fix:** Reduce sampling for high-volume services. See sampling guidelines in [CONFIGURATION.md](CONFIGURATION.md).

### High Memory Usage

**Expected:** +10-20 MB when StatsD is enabled.

**Fix:** If higher, test with `STATSD_ENABLED=false` to isolate. Review application code for leaks.

---

## Debugging

### Local Testing

Run statsd-exporter locally via Docker (UDP 8125, HTTP 9102), set `STATSD_ENABLED=true` with `STATSD_HOST=127.0.0.1`, then check `curl http://localhost:9102/metrics`.

### End-to-End Verification

1. Send test metric from pod via UDP to `STATSD_HOST:STATSD_PORT`
2. Check statsd-exporter logs for receipt
3. Query Prometheus for the metric
4. Verify Grafana dashboard displays it

See [DEPLOYMENT.md](DEPLOYMENT.md) for verification commands.

---

## Getting Help

1. Read this guide and [CONFIGURATION.md](CONFIGURATION.md)
2. Check application logs and Grafana dashboards
3. Include in bug reports: nano-service version, service name, affected metrics, Prometheus query, and relevant logs
