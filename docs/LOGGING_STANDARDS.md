# Logging Standards for Reminder Platform

**Last Updated:** 2026-02-14
**Status:** Production Standard

---

## Overview

All services MUST use structured JSON logging with a **fixed schema** for observability in Loki/Grafana.

**All log sources (PHP, nginx, business, nano-service) MUST output JSON** so that every line in stdout is parseable by a single log pipeline.

**Note:** `ReminderCallback` (from `shared/callback`) is NOT a logger - it's a callback service for sending delivery status to core. The actual logger is `$this->logger` (PSR-3) provided by nano-service.

---

## Log Sources

A single container's stdout may contain logs from multiple sources. The `source` field identifies the origin.

| Source | Value | Origin |
|--------|-------|--------|
| **business** | `"source": "business"` | Handler code (send SMS, process webhook) |
| **consumer** | `"source": "consumer"` | ConsumerCommand lifecycle (start, stop, retry) |
| **nano-service** | `"source": "nano-service"` | NanoService library internals (RabbitMQ, DB) |
| **php** | `"source": "php"` | PHP runtime errors (configured via error handler) |
| **nginx** | `"source": "nginx"` | Nginx access/error logs (configured in nginx.conf) |

### Making All Sources JSON

**PHP errors** — configure JSON error handler:
```php
set_error_handler(function ($severity, $message, $file, $line) {
    throw new \ErrorException($message, 0, $severity, $file, $line);
});
```

**Nginx** — configure JSON log format:
```nginx
log_format json_combined escape=json
    '{"source":"nginx","time":"$time_iso8601",'
    '"method":"$request_method","uri":"$request_uri",'
    '"status":$status,"duration_ms":$request_time,'
    '"remote_addr":"$remote_addr","body_bytes_sent":$body_bytes_sent}';

access_log /dev/stdout json_combined;
error_log /dev/stderr warn;
```

Note: nginx has a fixed schema — no `extra`, every field is always present and predictable.

---

## Fixed Log Schema

**Every log line follows this structure:**

```
context = {
    source          — always (who produced this log)
    event_id        — when processing a message
    event           — when processing a message
    trace_id        — when processing a message
    error           — on failure
    error_class     — on failure
    duration_ms     — on timed operations
    reason          — on rejections/skips
    handler         — when a handler is involved
    extra           — ONLY variable domain-specific data (phone, email, status_code)
}
```

### What goes WHERE

| Where | What | Why |
|-------|------|-----|
| **root context** | `source`, `event_id`, `event`, `trace_id` | Always same structure, always filterable |
| **root context** | `error`, `error_class`, `duration_ms`, `reason`, `handler` | Standard fields, same meaning across all providers |
| **`extra`** | `to`, `from`, `status_code`, `email`, `chat_id`, `message_length` | **Varies per provider and per call** — SMS has phone, email has address, Telegram has chat_id |

### The Rule

> `extra` contains **only data that differs between calls of the same source type**.
> If a field is always present with the same meaning (error, duration, handler), it goes at root.

```php
✅ CORRECT — phone is variable, duration is standard:
$this->log('info', 'vonage_send_success', [
    'duration_ms' => $duration,
    'extra' => ['status_code' => $status],
]);

❌ WRONG — everything in extra:
$this->log('info', 'vonage_send_success', [
    'extra' => ['duration_ms' => $duration, 'status_code' => $status],
]);

❌ WRONG — domain data at root:
$this->log('info', 'vonage_send_success', [
    'duration_ms' => $duration,
    'status_code' => $status,  // This varies per provider
]);
```

---

## `$this->log()` Helper (BaseHandler)

**All handler logging uses one method:**

```php
// BaseHandler provides:
protected function log(string $level, string $event, array $context = []): void

// Call setMessage() once, then just log:
$this->setMessage($message);

// No extra needed (standard fields only)
$this->log('error', 'vonage_send_rejected', ['reason' => 'missing_credentials']);

// With extra (variable domain data)
$this->log('info', 'vonage_send_start', [
    'extra' => [
        'to' => $smsMessage->to,
        'from' => $smsMessage->from,
        'message_length' => strlen($smsMessage->message),
    ],
]);

// Standard + extra mixed
$this->log('info', 'vonage_send_success', [
    'duration_ms' => $duration,
    'extra' => ['status_code' => $status],
]);

// Error (no extra needed)
$this->log('error', 'vonage_send_exception', [
    'error' => $exception->getMessage(),
    'error_class' => get_class($exception),
]);
```

**What `log()` does:** merges `['source' => 'business', 'event_id' => ..., 'event' => ..., 'trace_id' => ...]` with your context.

---

## ConsumerCommand Logging

Two private helpers — flat `array_merge`, no nesting:

```php
private function consumerContext(array $fields = []): array
{
    return array_merge(['source' => 'consumer'], $fields);
}

private function messageContext(NanoServiceMessage $message, array $fields = []): array
{
    return array_merge([
        'source' => 'consumer',
        'event_id' => $message->getId(),
        'event' => $message->getEventName(),
        'trace_id' => $message->getTraceId(),
    ], $fields);
}
```

**Usage:**

```php
$this->logger->info('vonage_consumer_starting', $this->consumerContext([
    'events' => $events,
    'tries' => $tries,
    'backoff_seconds' => $backoff,
]));

$this->logger->info('vonage_consumer_message_received', $this->messageContext($message, [
    'handler' => $handlerName,
]));

$this->logger->info('vonage_consumer_message_processed', $this->messageContext($message, [
    'handler' => $handlerName,
    'duration_ms' => $duration,
]));

$this->logger->warning('vonage_consumer_retry', $this->messageContext($message, [
    'error' => $exception->getMessage(),
    'error_class' => get_class($exception),
]));

$this->logger->warning('vonage_consumer_stopped', $this->consumerContext([
    'reason' => 'consume_loop_exited',
]));
```

---

## Log Levels

| Level | When to Use | Examples |
|-------|-------------|----------|
| `info` | Normal operations, milestones | Handler started, message sent, webhook processed |
| `warning` | Recoverable issues, retries | Temporary API failure, rate limit, retry scheduled |
| `error` | Unrecoverable failures | Invalid credentials, permanent API error, database failure |
| `debug` | Development only | Never use in production handlers |

---

## Message Naming Convention

**Format:** `{provider}_{action}_{status}`

**Handler:** `vonage_send_start`, `vonage_send_success`, `vonage_send_failed`, `vonage_send_exception`, `vonage_send_rejected`

**Webhook:** `vonage_webhook_received`, `vonage_webhook_processed`, `vonage_webhook_duplicate`

**Consumer:** `vonage_consumer_starting`, `vonage_consumer_stopped`, `vonage_consumer_message_received`, `vonage_consumer_message_processed`, `vonage_consumer_retry`, `vonage_consumer_failed_permanently`

---

## External API Connection Protection (CRITICAL)

**All HTTP clients calling external APIs MUST have explicit timeouts.**

Without timeouts, a hanging API call blocks the consumer indefinitely, prevents RabbitMQ heartbeats, kills the connection, and causes message redelivery loops.

```php
❌ BAD:
$vonageClient = new Vonage\Client($credentials);

✅ GOOD:
$httpClient = new \GuzzleHttp\Client([
    'timeout' => 30,
    'connect_timeout' => 5,
]);
$vonageClient = new Vonage\Client($credentials, [], $httpClient);
```

| Timeout | Value | Rationale |
|---------|-------|-----------|
| `connect_timeout` | **5s** | TCP handshake should be fast |
| `timeout` | **30s** | Most APIs respond in 1-5s; 30s is generous |
| Maximum | **60s** | Must stay below RabbitMQ heartbeat (~120s) |

---

## What NOT to Log

**Raw Payload Dumps (credentials leak!):**
```php
❌ $this->logger->warning($exception->getMessage(), $message->getPayload());
✅ $this->log('warning', 'vonage_send_failed', ['error' => $exception->getMessage()]);
```

**Sensitive Data:** passwords, API keys, tokens

**Large Content:** full HTML bodies, full JSON responses — log length/summary instead

**Plain Strings:** `❌ $this->logger->info('Sending SMS to ' . $number);`

---

## JSON Output Examples

**Business log:**
```json
{"message":"vonage_send_success","context":{"source":"business","event_id":"019c5c6b-...","event":"provider.vonage.sms","trace_id":"abc-123","duration_ms":1245.67,"extra":{"status_code":0}},"level":200,"level_name":"INFO","channel":"provider-vonage","datetime":"2026-02-14T13:51:13.972183+00:00"}
```

**Consumer log:**
```json
{"message":"vonage_consumer_message_processed","context":{"source":"consumer","event_id":"019c5c6b-...","event":"provider.vonage.sms","trace_id":"abc-123","handler":"VonageSendSMS","duration_ms":1245.67},"level":200,"level_name":"INFO","channel":"provider-vonage","datetime":"2026-02-14T13:51:14.000000+00:00"}
```

**NanoService log:**
```json
{"message":"[NanoConsumer] Database error","context":{"source":"nano-service","message_id":"019c5c6b-...","error":"SQLSTATE[08006] Connection refused"},"level":400,"level_name":"ERROR","channel":"php-nano-service","datetime":"2026-02-14T13:51:13.977987+00:00"}
```

**Nginx log:**
```json
{"source":"nginx","time":"2026-02-14T13:51:13+00:00","method":"GET","uri":"/health","status":200,"duration_ms":0.001,"remote_addr":"10.0.0.1","body_bytes_sent":2}
```

### Filtering in Loki

```logql
{service="vonage"} | json | context_source="business"
{service="vonage"} | json | context_source="consumer"
{service="vonage"} | json | context_source="nano-service"
{service="vonage"} | json | source="nginx"
{namespace="live"} | json | context_trace_id="abc-123-def"
{service="vonage"} | json | context_extra_to="+491234567890"
```

---

## Migration Checklist

- [ ] BaseHandler: add `setMessage()` + `log()` helper
- [ ] Handler: use `$this->log()` — standard fields at root, domain data in `extra`
- [ ] ConsumerCommand: add `consumerContext()` + `messageContext()` helpers
- [ ] Replace `$message->getPayload()` dumps with specific fields
- [ ] Verify no credentials leak in log output
- [ ] Configure nginx JSON log format (if applicable)
- [ ] Test with `docker compose logs -f <provider> | jq '.'`

---

## Reference Implementations

- BaseHandler: `packages/providers/vonage/src/Contracts/BaseHandler.php`
- Handler: `packages/providers/vonage/src/Handlers/VonageSendSMS.php`
- Consumer: `packages/providers/vonage/src/Console/ConsumerCommand.php`

---

## Success Criteria

- ✅ Every log line is valid JSON with `source` field
- ✅ Handlers use `$this->log()` — one call per log
- ✅ Standard fields (`error`, `duration_ms`, `handler`, `reason`) at context root
- ✅ Only variable domain data (`to`, `from`, `status_code`) in `extra`
- ✅ Zero `getPayload()` dumps
- ✅ Queryable in Loki by `source`, `event_id`, `trace_id`, `extra.*`
