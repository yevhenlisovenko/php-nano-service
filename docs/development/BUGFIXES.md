# Bug Fixes and Known Issues

Critical bug fixes in nano-service.

---

## Summary

| Date | Bug | Severity | File |
|------|-----|----------|------|
| 2026-05-14 | Consumer stuck `consumers=0` for 2+h after broker restart | CRITICAL | `NanoConsumer.php`, `NanoServiceClass.php` |
| 2026-01-22 | PHP 8.4 nullable parameter deprecation | LOW | `NanoConsumer.php` |
| 2026-01-20 | Duplicate `$statsD` property visibility | CRITICAL | `NanoConsumer.php` |
| 2026-01-20 | StatsDConfig validation with array config | MEDIUM | `StatsDConfig.php` |
| 2026-01-20 | PHP 8.4 float-to-int deprecation | LOW | `NanoServiceMessage.php` |
| 2026-01-16 | Channel leak in getChannel() | CRITICAL | `NanoServiceClass.php` |

---

## Critical: Consumer stuck after RabbitMQ restart

**Date:** 2026-05-14
**Released in:** v8.0.0
**Status:** FIXED

### Problem

On e2e, after `rabbitmq-0` restarted, long-running consumer pods stayed `Running 1/1` with `consumers=0` in `rabbitmqctl list_queues` for **2+ hours**. Backlog grew silently until manual `kubectl delete pod`. Symptom looked exactly like a half-open TCP socket waiting on kernel `TCP_KEEPIDLE` (default 7200s).

### Root cause

The inner consuming loop called:

```php
$this->getChannel()->wait(null, false, 0);
```

In `php-amqplib v3.7.4`:
- `$timeout = 0` propagates to `AbstractConnection::wait_channel($timeout=0)` as "block forever, no AMQP-level timeout".
- `checkHeartBeat()` is invoked inside `wait_channel` **only on the `AMQPTimeoutException` path**. With `$timeout=0` that path is never taken — heartbeat detection never runs.
- `read_write_timeout=10` (the old default) is a stream-socket option (`stream_set_timeout`). When `fread()` returns due to socket timeout, php-amqplib retries the read without consulting heartbeat state.
- Result: receive-side heartbeat (`AMQPHeartbeatMissedException` thrown when `now - lastActivity > 2*heartbeat + 1`) **does not fire** while `wait()` is blocked.
- The only thing that eventually wakes a dead half-open socket is kernel TCP keepalive — `TCP_KEEPIDLE` default = 7200s = **2 hours**. Exact match for the observed timeline.

The ticket also flagged the heartbeat=180 / read_write_timeout=10 mismatch. That contributed (detection window would have been ~361s anyway), but the missed `checkHeartBeat()` call is what allowed the 2-hour stall.

### Fix (v8.0.0)

1. **Inner loop uses a finite `wait()` timeout** (default = `heartbeat / 2`, configurable via `AMQP_CONSUMER_INNER_WAIT_TIMEOUT_SECONDS`).
2. **`AMQPTimeoutException` is caught locally** in the inner loop and treated as "no message in this window — probe connection health":
   ```php
   try {
       $this->getChannel()->wait(null, false, $innerWaitTimeoutSec);
   } catch (\PhpAmqpLib\Exception\AMQPTimeoutException) {
       if (!$this->isConnectionHealthy()) {
           throw new \RuntimeException('AMQP connection unhealthy, exiting for k8s restart');
       }
   }
   ```
3. **All other AMQP exceptions re-throw out of `consume()`** instead of being routed to `handleRabbitMQError()` — process exits non-zero, k8s `restartPolicy: Always` restarts the pod.
4. **Heartbeat defaults**: `30s` (was hardcoded `180`), `read_write_timeout` `60s` (was `10`). All configurable via env: `AMQP_HEARTBEAT_SECONDS`, `AMQP_READ_WRITE_TIMEOUT_SECONDS`, `AMQP_CONNECTION_TIMEOUT_SECONDS`.
5. **`outageMode` / `setOutageCallbacks` retained on `NanoServiceClass`** for `NanoPublisher` — publishers run in HTTP-FPM workers where `exit(1)` would 500 user requests.

### Verification

- Unit tests: 9 new tests in `NanoConsumerTest.php` cover inner-loop behaviour (`testInnerLoopThrowsWhenHealthCheckFailsAfterWaitTimeout`, `testInnerLoopStaysOnHealthyTimeout`, `testHeartbeatMissedPropagatesAsCrash`, etc.). 7 new tests in `NanoServiceClassTest.php` cover env-driven defaults and ratio clamping.
- Manual repro: `tests/docker/test-broker-restart.sh` runs 3 adversarial scenarios (graceful broker restart, hard kill -9, network partition). Each asserts `RestartCount` increments and `consumers=1` returns within 60s.

### Prevention

- **Never call `wait(null, false, 0)`** on `AMQPChannel` in long-running consumers. Always pass a finite timeout.
- Read library source when "the obvious mechanism should have worked but didn't" — heartbeat detection's actual call site (only on the `AMQPTimeoutException` path) wasn't obvious from the docs.
- Adversarial tests for any "should auto-recover from X" guarantee — `test-broker-restart.sh` would have caught this in CI.

**Incident details:** `incidents/2026-05-14_NANO_SERVICE_CONSUMER_RECONNECT_INVESTIGATION/`

---

## Critical: Duplicate Property Visibility

**Date:** 2026-01-20
**Status:** FIXED

### Problem

```php
// Parent
protected ?StatsDClient $statsD = null;

// Child - WRONG
private StatsDClient $statsD;  // Fatal error!
```

### Error

```
Fatal error: Access level to NanoConsumer::$statsD must be protected
```

### Fix

Remove duplicate property declaration from child class.

### Prevention

- Check parent before adding properties
- Run PHPStan level 8
- Test with multiple PHP versions

---

## Critical: Channel Leak

**Date:** 2026-01-16
**Status:** FIXED

See [CHANGELOG.md](../CHANGELOG.md) for full details.

### Problem

`getChannel()` created new channels without storing in shared pool.

### Impact

- 17,840 channels (97% reduction after fix)
- "No free channel ids" errors

### Fix

```php
if (! $this->channel || !$this->channel->is_open()) {
    $this->channel = $this->getConnection()->channel();
    self::$sharedChannel = $this->channel;  // Store for reuse
}
```

---

## Medium: StatsDConfig Array Validation

**Date:** 2026-01-20
**Status:** FIXED

### Problem

Environment validation ran even when full config provided via array.

### Fix

Skip env validation when all required config provided programmatically.

---

## Low: PHP 8.4 Deprecations

**Date:** 2026-01-20
**Status:** FIXED

### float-to-int in date()

```php
// Before
date('Y-m-d H:i:s', $mic);

// After
date('Y-m-d H:i:s', (int)$mic);
```

### Nullable parameter

```php
// Before
function consume(?callable $callback)

// After
function consume(?callable $callback = null)
```

---

## References

- [PHP Visibility](https://www.php.net/manual/en/language.oop5.visibility.php)
- [CODE_REVIEW.md](CODE_REVIEW.md)
