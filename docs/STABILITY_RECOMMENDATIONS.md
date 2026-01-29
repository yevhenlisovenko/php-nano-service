# Stability & Safety Recommendations for nano-service

> **Purpose**: Critical analysis of potential breaking points, safety issues, and stability improvements
> **Scope**: Based on [ARCHITECTURE_DEEP_DIVE.md](ARCHITECTURE_DEEP_DIVE.md) analysis
> **Focus**: Stability, bug safety, error prevention (NOT code aesthetics)

**Last Updated**: 2026-01-29

---

## Table of Contents

1. [Connection & Channel Management](#1-connection--channel-management)
2. [Message Processing Safety](#2-message-processing-safety)
3. [Retry Logic & Backoff](#3-retry-logic--backoff)
4. [Metrics & Observability](#4-metrics--observability)
5. [Resource Leak Prevention](#5-resource-leak-prevention)
6. [Error Handling & Recovery](#6-error-handling--recovery)
7. [Race Conditions & Concurrency](#7-race-conditions--concurrency)
8. [Configuration & Defaults](#8-configuration--defaults)
9. [Dead Letter Queue Issues](#9-dead-letter-queue-issues)
10. [Critical Priority Summary](#10-critical-priority-summary)

---

## 1. Connection & Channel Management

### Related Section
[ARCHITECTURE_DEEP_DIVE.md - Section 2.4 (Lines 733-979)](ARCHITECTURE_DEEP_DIVE.md#L733-L979)

### 🔴[-NOT CRITICAL] CRITICAL: Connection Leak After Channel Errors

**Location**: [NanoConsumer.php:266-270](../src/NanoConsumer.php#L266-L270)

**Problem**:
```php
public function shutdown(): void
{
    $this->getChannel()->close();
    $this->getConnection()->close();
}
```

**Breaking Point**:
- If `$this->getChannel()->close()` throws an exception, `$this->getConnection()->close()` is NEVER called
- Leaves orphaned connections consuming resources
- After multiple crashes, accumulates connection leaks

**Impact**:
- Connection exhaustion under high failure rate
- Memory leaks in long-running workers
- RabbitMQ connection limits reached

**Recommendation**:
```php
public function shutdown(): void
{
    try {
        $this->getChannel()->close();
    } catch (Throwable $e) {
        // Log but don't prevent connection cleanup
        error_log("Channel close failed: " . $e->getMessage());
    }

    try {
        $this->getConnection()->close();
    } catch (Throwable $e) {
        error_log("Connection close failed: " . $e->getMessage());
    }
}
```

---

### 🟡[-] MEDIUM: No Connection Health Check Before Usage

**Location**: [NanoServiceClass.php - getConnection/getChannel methods](../src/NanoServiceClass.php)

**Problem**:
- Static `$sharedConnection` and `$sharedChannel` may be stale
- No validation that connection is still alive before reuse
- Broken connections cause immediate failure instead of reconnection

**Breaking Point**:
```php
// Worker idle for 5 minutes
// RabbitMQ closes connection due to heartbeat timeout
// Next message arrives
// getConnection() returns stale connection
// basic_publish() throws exception → message goes to retry
```

**Impact**:
- False failures trigger unnecessary retries
- Increased DLX traffic for transient connection issues
- Poor performance during connection recovery

**Recommendation**:
```php
protected static function getConnection(): AMQPStreamConnection
{
    if (self::$sharedConnection && self::$sharedConnection->isConnected()) {
        // Check if connection is actually responsive
        try {
            self::$sharedConnection->checkHeartBeat();
        } catch (Throwable $e) {
            // Connection stale, close and recreate
            try {
                self::$sharedConnection->close();
            } catch (Throwable $closeException) {}
            self::$sharedConnection = null;
        }
    }

    if (!self::$sharedConnection) {
        // Create new connection...
    }

    return self::$sharedConnection;
}
```

---

### 🟡 MEDIUM: Channel Not Recreated After AMQPChannelException

**Location**: Related to [Section 2.2 - Line 128](ARCHITECTURE_DEEP_DIVE.md#L710-L730)

**Problem**:
- `AMQPChannelException` closes the channel but code doesn't recreate it
- Subsequent operations fail with "Channel closed" errors
- Worker must restart to recover

**Breaking Point**:
```php
// Message processing causes AMQPChannelException (e.g., precondition failed)
// Channel is now closed
// Next message arrives
// consumeCallback() tries to use closed channel → crash
```

**Impact**:
- Worker crashes require external restart (systemd, supervisor)
- Downtime during recovery
- Lost messages if worker restarts before ACK

**Recommendation**:
```php
// In consumeCallback, wrap all channel operations
try {
    $this->getChannel()->basic_publish(...);
} catch (AMQPChannelException $e) {
    // Channel closed, recreate it
    self::$sharedChannel = null;
    $this->getChannel(); // Recreates channel

    // Retry operation once
    $this->getChannel()->basic_publish(...);
}
```

---

### 🔴 CRITICAL: Shutdown Not Called on Fatal Errors

**Location**: [Section 2.3 - Line 677-703](ARCHITECTURE_DEEP_DIVE.md#L677-L703)

**Problem**:
- `register_shutdown_function()` is registered but may not be called for all termination scenarios
- SIGKILL (kill -9) bypasses shutdown handlers
- Out-of-memory errors may prevent shutdown execution
- Fatal errors in PHP 8+ might not trigger registered shutdown

**Breaking Point**:
```php
// Worker receives SIGKILL or OOM
// shutdown() never called
// Connection never closed
// RabbitMQ maintains connection as "alive"
// Messages remain in "unacknowledged" state
// After connection timeout, messages redelivered (duplicate processing)
```

**Impact**:
- Duplicate message processing
- Connections accumulate on RabbitMQ (relates to 2026-01-16 incident)
- Un-ACKed messages block queue if QoS prefetch causes backlog

**Recommendation**:
```php
// Add signal handlers for graceful shutdown
declare(ticks = 1);

pcntl_signal(SIGTERM, [$this, 'handleSignal']);
pcntl_signal(SIGINT, [$this, 'handleSignal']);

public function handleSignal(int $signal): void
{
    echo "Received signal $signal, shutting down gracefully...\n";
    $this->shutdown();
    exit(0);
}
```

Additionally, document that supervisors should:
- Use SIGTERM (not SIGKILL) for worker shutdown
- Set generous shutdown timeout (30s+)
- Monitor for zombie connections

---

## 2. Message Processing Safety

### Related Section
[ARCHITECTURE_DEEP_DIVE.md - Section 3.2 (Lines 1560-2245)](ARCHITECTURE_DEEP_DIVE.md#L1560-L2245)

### 🔴 CRITICAL: Double ACK Possibility

**Location**: [Section 3.2 - Phase 6 (Lines 1812-1885)](ARCHITECTURE_DEEP_DIVE.md#L1812-L1885)

**Problem**:
```php
// Line 199: callback completes
call_user_func($callback, $newMessage);

// Line 203: ACK #1
$message->ack();

// Line 210: Success metrics
$this->statsD->end(SUCCESS);

// BUT in catch block...
// Line 229: ACK #2 (on retry)
$message->ack();

// Line 252: ACK #3 (on DLX)
$message->ack();
```

**Breaking Point**:
- If callback throws exception AFTER ACK (line 203) completes
- Or if `statsD->end()` throws exception
- Catch block calls `$message->ack()` again → double ACK error

**Impact**:
- "AMQPProtocolChannelException: Unknown delivery tag" error
- Channel closes
- Worker crashes
- Messages stuck in unacknowledged state

**Current Code Safety**:
Looking at lines 202-210, ACK is inside try-catch so double ACK shouldn't happen normally. However:

**Edge Case**:
```php
try {
    call_user_func($callback, $newMessage);
    try {
        $message->ack(); // SUCCESS
    } catch (Throwable $e) {
        // Track ACK failure
        throw $e; // Re-throws, goes to outer catch
    }
    // If we reach here, ACK succeeded
    $this->statsD->end(SUCCESS);
} catch (Throwable $exception) {
    // If ACK succeeded but statsD->end() threw, we're here
    // Now retryCount < tries check happens
    // Line 229: $message->ack() called AGAIN
}
```

**Recommendation**:
```php
// Add flag to track ACK status
private bool $messageAcked = false;

// In consumeCallback
$this->messageAcked = false;

try {
    call_user_func($callback, $newMessage);
    try {
        $message->ack();
        $this->messageAcked = true; // Mark as ACKed
    } catch (Throwable $e) {
        $this->statsD->increment('rmq_consumer_ack_failed_total', $tags);
        throw $e;
    }
    $this->statsD->end(SUCCESS, $eventRetryStatusTag);
} catch (Throwable $exception) {
    // Only ACK if not already ACKed
    if ($retryCount < $this->tries) {
        // Retry logic
        if (!$this->messageAcked) {
            $message->ack();
        }
    } else {
        // DLX logic
        if (!$this->messageAcked) {
            $message->ack();
        }
    }
}
```

---

### 🟡 MEDIUM: No Timeout on User Callback

**Location**: [Section 3.2 - Line 199 (Line 1838-1856)](ARCHITECTURE_DEEP_DIVE.md#L1838-L1856)

**Problem**:
```php
call_user_func($callback, $newMessage);
```

**Breaking Point**:
- User callback hangs indefinitely (infinite loop, deadlock)
- Worker appears alive but not processing messages
- QoS prefetch=1 means queue blocked (no other worker can take messages)
- No timeout mechanism to kill hung callbacks

**Impact**:
- Queue backlog grows
- No error metrics (callback never returns)
- Worker restart required
- Lost visibility into hang location

**Recommendation**:
```php
// Add configurable timeout
private int $messageTimeoutSeconds = 300; // 5 minutes default

// In consumeCallback
$startTime = time();
$timeoutReached = false;

// Use pcntl_alarm for timeout (Unix only)
if (function_exists('pcntl_alarm')) {
    pcntl_signal(SIGALRM, function() use (&$timeoutReached) {
        $timeoutReached = true;
        throw new MessageTimeoutException("Message processing exceeded timeout");
    });
    pcntl_alarm($this->messageTimeoutSeconds);
}

try {
    call_user_func($callback, $newMessage);
    pcntl_alarm(0); // Cancel alarm
} catch (MessageTimeoutException $e) {
    // Log timeout
    Log::error("Message processing timeout", [
        'event' => $newMessage->getEventName(),
        'timeout_seconds' => $this->messageTimeoutSeconds
    ]);
    throw $e; // Trigger retry
}
```

**Alternative**: Use separate worker process per message with timeout

---

### 🟠 HIGH: Exception in Callback Can Lose Stack Trace

**Location**: [Section 3.2 - Lines 217-221 (catch callback)](ARCHITECTURE_DEEP_DIVE.md#L1979-L2008)

**Problem**:
```php
try {
    if (is_callable($this->catchCallback)) {
        call_user_func($this->catchCallback, $exception, $newMessage);
    }
} catch (Throwable $e) {}
```

**Breaking Point**:
- User-defined `catchCallback` is called with original exception
- If `catchCallback` throws, it's silently ignored
- No logging of catchCallback failures
- Original exception passed to catchCallback, but if catchCallback modifies state and throws, state is lost

**Impact**:
- Lost debugging information from catchCallback errors
- Silent failures in error handling code
- Difficult to diagnose issues in custom error handlers

**Recommendation**:
```php
try {
    if (is_callable($this->catchCallback)) {
        call_user_func($this->catchCallback, $exception, $newMessage);
    }
} catch (Throwable $catchCallbackException) {
    // Log catchCallback failure but don't prevent retry
    error_log(sprintf(
        "catchCallback failed: %s\nOriginal exception: %s",
        $catchCallbackException->getMessage(),
        $exception->getMessage()
    ));

    // Track metric for catchCallback failures
    $this->statsD->increment('rmq_consumer_catch_callback_failed', $tags);
}
```

---

### 🟡 MEDIUM: Message Body Not Validated Before Processing

**Location**: [Section 3.2 - Phase 1 (Lines 1563-1586)](ARCHITECTURE_DEEP_DIVE.md#L1563-L1586)

**Problem**:
```php
$newMessage = new NanoServiceMessage($message->getBody(), $message->get_properties());
```

**Breaking Point**:
- Malformed JSON in message body
- Invalid UTF-8 encoding
- Message body exceeds expected size
- NanoServiceMessage constructor doesn't validate input

**Impact**:
- Fatal errors in message parsing
- Worker crashes before retry logic engaged
- Corrupted messages cause repeated crashes
- No way to reject invalid messages early

**Recommendation**:
```php
// Before wrapping message, validate basic structure
try {
    $body = $message->getBody();

    // Validate size
    if (strlen($body) > 10 * 1024 * 1024) { // 10MB limit
        throw new MessageTooLargeException("Message exceeds size limit");
    }

    // Validate JSON structure (if applicable)
    if (!empty($body)) {
        json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    $newMessage = new NanoServiceMessage($body, $message->get_properties());
} catch (JsonException | MessageTooLargeException $e) {
    // Invalid message - send directly to DLX, don't retry
    Log::error("Invalid message format", [
        'error' => $e->getMessage(),
        'body_length' => strlen($body)
    ]);

    // Publish to DLX immediately
    $this->getChannel()->basic_publish($message, '', $this->queue . self::FAILED_POSTFIX);
    $message->ack();
    return; // Don't process
}
```

---

## 3. Retry Logic & Backoff

### Related Section
[ARCHITECTURE_DEEP_DIVE.md - Section 3.3 (Lines 2248-2471)](ARCHITECTURE_DEEP_DIVE.md#L2248-L2471)

### 🟠 HIGH: Backoff Array Index Out of Bounds Prevented But Confusing

**Location**: [Section 3.3 - Lines 2254-2266](ARCHITECTURE_DEEP_DIVE.md#L2254-L2266)

**Problem**:
```php
private function getBackoff(int $retryCount): int
{
    if (is_array($this->backoff)) {
        $count = $retryCount - 1;
        $lastIndex = count($this->backoff) - 1;
        $index = min($count, $lastIndex); // Uses last value if $count exceeds array

        return $this->backoff[$index] * 1000;
    }

    return $this->backoff * 1000;
}
```

**Issue**:
- Configuration: `backoff([1, 5, 60])->tries(10)`
- Attempts 4-10 all use 60 seconds delay
- Not obvious to users that backoff array doesn't need to match tries count
- Can cause unexpected behavior if user assumes 1:1 mapping

**Impact**:
- Confusion in configuration
- Unintended retry delays
- Difficult to reason about retry behavior

**Recommendation**:
```php
// Add validation in tries() or backoff() methods
public function backoff(array|int $backoff): self
{
    $this->backoff = $backoff;

    // Validate if tries already set
    if (is_array($backoff) && $this->tries > 0) {
        if (count($backoff) < $this->tries) {
            trigger_error(
                sprintf(
                    "Backoff array has %d values but tries is %d. " .
                    "Retries beyond array length will use last value (%d seconds).",
                    count($backoff),
                    $this->tries,
                    end($backoff)
                ),
                E_USER_WARNING
            );
        }
    }

    return $this;
}
```

---

### 🟡 MEDIUM: No Maximum Backoff Cap

**Location**: [Section 3.3 - Lines 2254-2266](ARCHITECTURE_DEEP_DIVE.md#L2254-L2266)

**Problem**:
- User can configure: `backoff([1, 10, 100, 1000, 10000])`
- 10,000 seconds = 2.7 hours delay
- Message sits in exchange for hours
- No upper limit enforced

**Breaking Point**:
```php
// Configuration
->backoff([1, 3600, 86400]) // 1s, 1hr, 24hrs
->tries(3)

// First retry: 1 second (reasonable)
// Second retry: 1 hour (questionable)
// Third retry: 24 hours later (message stale by then)
```

**Impact**:
- Stale message processing (data may no longer be relevant)
- Increased memory usage in delayed exchange
- Delayed visibility into permanent failures
- Messages in limbo for extended periods

**Recommendation**:
```php
private const MAX_BACKOFF_SECONDS = 3600; // 1 hour max

private function getBackoff(int $retryCount): int
{
    if (is_array($this->backoff)) {
        $count = $retryCount - 1;
        $lastIndex = count($this->backoff) - 1;
        $index = min($count, $lastIndex);

        $backoffSeconds = $this->backoff[$index];
    } else {
        $backoffSeconds = $this->backoff;
    }

    // Cap backoff at maximum
    if ($backoffSeconds > self::MAX_BACKOFF_SECONDS) {
        trigger_error(
            "Backoff of {$backoffSeconds}s exceeds maximum " . self::MAX_BACKOFF_SECONDS . "s, capping",
            E_USER_WARNING
        );
        $backoffSeconds = self::MAX_BACKOFF_SECONDS;
    }

    return $backoffSeconds * 1000;
}
```

---

### 🔴 CRITICAL: Retry Count Persists Across Queue Redeliveries

**Location**: [Section 3.2 - Phase 4 (Lines 1666-1718)](ARCHITECTURE_DEEP_DIVE.md#L1666-L1718)

**Problem**:
```php
$retryCount = $newMessage->getRetryCount() + 1;
```

**Breaking Point**:
```
1. Message arrives (retryCount = 1)
2. Worker crashes BEFORE ACK (hard crash, no exception handling)
3. RabbitMQ redelivers message (x-retry-count = 1 still in headers)
4. New worker receives message
5. getRetryCount() returns 1 (from headers)
6. retryCount = 1 + 1 = 2
7. Even though this is FIRST attempt by new worker
```

**Impact**:
- Worker crashes consume retry budget
- Message may go to DLX after fewer logical attempts
- False retry exhaustion

**Recommendation**:
```php
// Differentiate between application retries and redelivery retries
public function getRetryCount(): int
{
    if ($this->has('application_headers')) {
        $headers = $this->get('application_headers')->getNativeData();
        $appRetries = isset($headers['x-retry-count']) ? (int)$headers['x-retry-count'] : 0;

        // Check RabbitMQ redelivered flag
        $redelivered = $this->isRedelivered();

        // If redelivered but app retry count is 0, this is a redelivery not a retry
        if ($redelivered && $appRetries === 0) {
            // Don't increment retry count for redeliveries
            return 0;
        }

        return $appRetries;
    }
    return 0;
}
```

---

### 🟡 MEDIUM: Delayed Exchange Availability Not Checked

**Location**: [Section 2.4 - Lines 838-876](ARCHITECTURE_DEEP_DIVE.md#L838-L876)

**Problem**:
```php
$this->createExchange($this->queue, 'x-delayed-message', new AMQPTable([
    'x-delayed-type' => 'topic',
]));
```

**Breaking Point**:
- RabbitMQ server doesn't have `rabbitmq_delayed_message_exchange` plugin installed
- Exchange creation fails silently
- Retry publishes fail with "exchange not found"
- Messages go to DLX immediately instead of retrying

**Impact**:
- Retry mechanism completely broken without plugin
- Difficult to diagnose (no clear error message)
- All failures go directly to DLX
- Silent degradation

**Recommendation**:
```php
// In init() method, verify plugin availability
try {
    $this->createExchange($this->queue, 'x-delayed-message', new AMQPTable([
        'x-delayed-type' => 'topic',
    ]));
} catch (AMQPProtocolChannelException $e) {
    if (strpos($e->getMessage(), 'x-delayed-message') !== false) {
        throw new \RuntimeException(
            "RabbitMQ delayed message plugin not installed. " .
            "Install with: rabbitmq-plugins enable rabbitmq_delayed_message_exchange",
            0,
            $e
        );
    }
    throw $e;
}
```

---

## 4. Metrics & Observability

### Related Section
[ARCHITECTURE_DEEP_DIVE.md - Section 3.4 (Lines 2471-2637)](ARCHITECTURE_DEEP_DIVE.md#L2471-L2637)

### 🟠 HIGH: Metrics Failures Can Break Message Processing

**Location**: [Section 3.2 - Phase 5 (Lines 1721-1809)](ARCHITECTURE_DEEP_DIVE.md#L1721-L1809)

**Problem**:
```php
$this->statsD->histogram(
    'rmq_consumer_payload_bytes',
    $payloadSize,
    $tags,
    $this->statsD->getSampleRate('payload')
);

$this->statsD->start($tags, $eventRetryStatusTag);
```

**Breaking Point**:
- StatsD client throws exception (network error, invalid metric name, etc.)
- Exception propagates up
- Message processing fails
- Message goes to retry even though callback was never called

**Impact**:
- Metrics failures cause business logic failures
- Metrics become critical dependency (should be fire-and-forget)
- Degraded service when metrics system is down
- Violates principle: "metrics should never impact core functionality"

**Current Protection**:
Need to verify if StatsD client already wraps calls in try-catch. Check [StatsDClient.php](../src/Clients/StatsDClient/StatsDClient.php)

**Recommendation**:
```php
// Wrap ALL statsD calls in try-catch
try {
    $this->statsD->histogram('rmq_consumer_payload_bytes', $payloadSize, $tags, $sampleRate);
} catch (Throwable $e) {
    // Log but don't throw
    error_log("StatsD metric failed: " . $e->getMessage());
}

// Better: Create safe wrapper method
private function safeMetric(callable $metricCall): void
{
    if (!$this->statsD || !$this->statsD->isEnabled()) {
        return;
    }

    try {
        $metricCall();
    } catch (Throwable $e) {
        error_log("Metric collection failed: " . $e->getMessage());
    }
}

// Usage
$this->safeMetric(fn() => $this->statsD->histogram('rmq_consumer_payload_bytes', $payloadSize, $tags));
```

---

### 🟡 MEDIUM: No Circuit Breaker on StatsD

**Location**: [Section 3.4](ARCHITECTURE_DEEP_DIVE.md#L2471-L2637)

**Problem**:
- Every message processing calls multiple StatsD methods
- If StatsD endpoint is down, each call waits for timeout
- Multiplied by messages per second, causes significant slowdown
- No circuit breaker to fast-fail when StatsD is unreachable

**Breaking Point**:
```
1. StatsD server crashes
2. Each metric call waits 1 second for timeout
3. 4 metric calls per message = 4 seconds overhead
4. Message throughput drops 80%
5. Queue backlog grows
6. System degradation due to metrics system failure
```

**Impact**:
- Severe performance degradation when metrics system down
- Cascading failures
- Message processing latency increase

**Recommendation**:
```php
// In StatsDClient
private bool $circuitOpen = false;
private int $consecutiveFailures = 0;
private ?int $circuitOpenedAt = null;
private const CIRCUIT_THRESHOLD = 3;
private const CIRCUIT_TIMEOUT = 60; // seconds

private function shouldSkipMetric(): bool
{
    if (!$this->circuitOpen) {
        return false;
    }

    // Check if circuit should close
    if (time() - $this->circuitOpenedAt > self::CIRCUIT_TIMEOUT) {
        $this->circuitOpen = false;
        $this->consecutiveFailures = 0;
        return false;
    }

    return true;
}

public function increment(string $metric, array $tags): void
{
    if ($this->shouldSkipMetric()) {
        return; // Fast-fail
    }

    try {
        // Send metric
        $this->consecutiveFailures = 0; // Success
    } catch (Throwable $e) {
        $this->consecutiveFailures++;
        if ($this->consecutiveFailures >= self::CIRCUIT_THRESHOLD) {
            $this->circuitOpen = true;
            $this->circuitOpenedAt = time();
            error_log("StatsD circuit breaker opened");
        }
        throw $e;
    }
}
```

---

### 🟡 MEDIUM: High-Cardinality Tags Not Enforced

**Location**: [CLAUDE.md - Section 7](../CLAUDE.md#L45-L75)

**Problem**:
- Guidelines warn against high-cardinality tags (user_id, invoice_id, etc.)
- No runtime enforcement
- User can accidentally add high-cardinality tags
- Causes Prometheus cardinality explosion

**Breaking Point**:
```php
// User code in callback
$tags = [
    'service' => 'myservice',
    'event' => 'invoice.created',
    'invoice_id' => $payload['invoice_id'], // HIGH CARDINALITY!
];
$this->statsD->increment('custom_metric', $tags);
```

**Impact**:
- Prometheus memory exhaustion
- Slow query performance
- Metrics system crash
- Production incident

**Recommendation**:
```php
// In StatsDClient
private array $allowedTagKeys = [
    'nano_service_name',
    'event_name',
    'error_type',
    'retry',
    'exit_status',
    'reason'
];

private function validateTags(array $tags): array
{
    foreach ($tags as $key => $value) {
        // Check allowed keys
        if (!in_array($key, $this->allowedTagKeys)) {
            trigger_error(
                "Tag key '$key' not in allowed list. May cause cardinality explosion.",
                E_USER_WARNING
            );
        }

        // Check for UUIDs, large numbers, etc. (high-cardinality indicators)
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            throw new \InvalidArgumentException(
                "Tag value '$value' appears to be a UUID (high-cardinality). Use bounded sets only."
            );
        }

        if (is_numeric($value) && $value > 1000) {
            trigger_error(
                "Tag value '$value' is a large number (potential high-cardinality)",
                E_USER_WARNING
            );
        }
    }

    return $tags;
}
```

---

## 5. Resource Leak Prevention

### Related Section
[ARCHITECTURE_DEEP_DIVE.md - Section 2.3 Line 677-703](ARCHITECTURE_DEEP_DIVE.md#L677-L703) and [2026-01-16 Incident](../incidents/2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2)

### 🔴 CRITICAL: Memory Leak from Unacknowledged Messages

**Location**: [Section 2.3 - Line 125 (QoS prefetch)](ARCHITECTURE_DEEP_DIVE.md#L615-L645)

**Problem**:
```php
$this->getChannel()->basic_qos(0, 1, 0);
```

**Breaking Point**:
- Worker processes message
- Exception thrown BEFORE ACK
- Worker crashes or hangs
- Message remains unacknowledged
- With QoS prefetch=1, only 1 message can be unacked
- But: If worker creates multiple consumers on same channel, each can have 1 unacked message
- Memory accumulates in RabbitMQ

**Impact**:
- RabbitMQ memory growth from unacked messages
- Queue depth monitoring becomes inaccurate
- Messages stuck in "unacked" state visible in management UI
- After worker restart, all unacked messages redelivered at once (thundering herd)

**Recommendation**:
```php
// Add periodic ACK check/cleanup
private array $unackedTags = [];
private int $lastAckCheckTime = 0;

public function trackUnackedMessage(string $deliveryTag): void
{
    $this->unackedTags[$deliveryTag] = time();

    // Periodic cleanup check
    if (time() - $this->lastAckCheckTime > 60) {
        $this->checkStaleUnackedMessages();
        $this->lastAckCheckTime = time();
    }
}

private function checkStaleUnackedMessages(): void
{
    $staleThreshold = 300; // 5 minutes

    foreach ($this->unackedTags as $tag => $timestamp) {
        if (time() - $timestamp > $staleThreshold) {
            error_log("Stale unacked message detected: $tag");
            // Consider: force-close connection to trigger redelivery?
        }
    }
}
```

---

### 🟠 HIGH: Channel Exhaustion Risk Still Exists

**Location**: Incident [2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2](../incidents/2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2)

**Problem**:
- Fix implemented: static `$sharedConnection` and `$sharedChannel`
- But: If PHP-FPM or worker pool runs multiple PHP processes
- Each process has its own static variables
- 100 workers = 100 connections + 100 channels minimum

**Breaking Point**:
```
Configuration:
- PHP-FPM with 50 workers per server
- 10 servers
- Total: 500 PHP workers

Each worker:
- 1 connection
- 1 channel per queue (consumer + publisher)
- 2 channels per worker minimum

Total: 500 connections, 1000 channels

RabbitMQ default limits:
- Max connections: varies by config
- Max channels per connection: 65535 (high)
- But 1000 channels across many connections still significant
```

**Impact**:
- Channel exhaustion at scale
- Connection exhaustion
- RabbitMQ memory usage from connection overhead
- Risk of hitting configured limits

**Recommendation**:
```php
// Add monitoring
public function getConnectionStats(): array
{
    return [
        'connection_id' => spl_object_id(self::$sharedConnection),
        'channel_id' => spl_object_id(self::$sharedChannel),
        'connection_status' => self::$sharedConnection ?
            (self::$sharedConnection->isConnected() ? 'connected' : 'disconnected') : 'null',
        'process_id' => getmypid()
    ];
}

// Expose as metric
$this->statsD->gauge('rmq_connections_active', 1, [
    'process_id' => getmypid(),
    'connection_id' => spl_object_id(self::$sharedConnection)
]);
```

Document in operations guide:
- Calculate expected connections: (workers_per_server * servers)
- Configure RabbitMQ limits appropriately
- Monitor connection count per node
- Set alerts on connection growth

---

### 🟡 MEDIUM: No Limit on Retry Message Size in Delayed Exchange

**Location**: [Section 3.2 Phase 8A - Line 228](ARCHITECTURE_DEEP_DIVE.md#L2053-L2070)

**Problem**:
```php
$this->getChannel()->basic_publish($newMessage, $this->queue, $key);
```

**Breaking Point**:
- Large message (e.g., 5MB JSON payload)
- Processing fails
- Message republished to delayed exchange with full payload
- Happens 3 times (retries)
- 5MB * 3 = 15MB memory per message
- 1000 messages failing simultaneously = 15GB

**Impact**:
- Delayed exchange memory exhaustion
- RabbitMQ disk space exhaustion (if persistent)
- Slow message routing
- Risk of RabbitMQ node crash

**Recommendation**:
```php
// Check message size before retry
private const MAX_RETRY_MESSAGE_SIZE = 1024 * 1024; // 1MB

// In retry logic (line 228)
if (strlen($newMessage->getBody()) > self::MAX_RETRY_MESSAGE_SIZE) {
    // Large message - send to DLX immediately, don't retry
    Log::warning("Message too large to retry, sending to DLX", [
        'size' => strlen($newMessage->getBody()),
        'event' => $newMessage->getEventName()
    ]);

    // Send to DLX
    $this->getChannel()->basic_publish($newMessage, '', $this->queue . self::FAILED_POSTFIX);
    $message->ack();
    $this->statsD->end(EventExitStatusTag::FAILED, $eventRetryStatusTag);
    return;
}

// Normal retry for small messages
$this->getChannel()->basic_publish($newMessage, $this->queue, $key);
```

---

## 6. Error Handling & Recovery

### Related Section
[ARCHITECTURE_DEEP_DIVE.md - Section 3.5 (Lines 2640-3007)](ARCHITECTURE_DEEP_DIVE.md#L2640-L3007)

### 🔴 CRITICAL: No Handling for Poisoned Messages

**Location**: [Section 3.5 - Transient vs Permanent Failures](ARCHITECTURE_DEEP_DIVE.md#L2642-L2716)

**Problem**:
- Message with malformed data causes fatal error (not exception)
- Example: `json_decode()` on huge string causes memory exhaustion
- Worker crashes immediately
- Message redelivered
- Crashes again (infinite crash loop)

**Breaking Point**:
```php
// User callback
$consumer->consume(function($message) {
    $data = json_decode($message->getBody()); // No error handling
    $data->user->name; // Fatal: Trying to get property of non-object
    // Worker crashes, message redelivered, repeat
});
```

**Impact**:
- Worker cannot process ANY messages (poisoned message at front of queue)
- Queue blocked
- Requires manual intervention to remove poisoned message
- Service downtime

**Recommendation**:
```php
// Add fatal error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Convert fatal errors to exceptions
    if (in_array($errno, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
    return false; // Let normal error handler handle
});

// In consumeCallback, catch ErrorException
try {
    call_user_func($callback, $newMessage);
} catch (\ErrorException $e) {
    // Fatal error converted to exception
    // Check if this is likely a poisoned message (same message causing repeated errors)

    // Send directly to DLX if retry count suggests repeated failure
    if ($retryCount >= 2) {
        Log::critical("Suspected poisoned message", [
            'event' => $newMessage->getEventName(),
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);

        // Skip retry, go straight to DLX
        $this->getChannel()->basic_publish($newMessage, '', $this->queue . self::FAILED_POSTFIX);
        $message->ack();
        return;
    }

    // First occurrence, allow retry
    throw $e;
}
```

---

### 🟠 HIGH: DLX Publish Can Fail Silently

**Location**: [Section 3.2 Phase 8B - Line 251](ARCHITECTURE_DEEP_DIVE.md#L2192-L2212)

**Problem**:
```php
$this->getChannel()->basic_publish($newMessage, '', $this->queue . self::FAILED_POSTFIX);
$message->ack();
```

**Breaking Point**:
- DLX queue doesn't exist (misconfiguration, manual deletion)
- `basic_publish` to non-existent queue fails
- Exception thrown
- Message ACKed already? No - ACK is on next line
- But exception propagates up, message NOT ACKed
- Message redelivered, retries exhausted again, infinite loop

**Current Code**: ACK is AFTER publish, so if publish fails, message not ACKed (good)

**But New Breaking Point**:
- If DLX publish succeeds but ACK fails
- Message in DLX queue AND still in main queue (duplicate)

**Impact**:
- Duplicate messages in DLX
- Lost messages if DLX queue missing
- Difficult to detect silent failures

**Recommendation**:
```php
// Verify DLX queue exists before publishing
try {
    // Attempt publish to DLX
    $this->getChannel()->basic_publish($newMessage, '', $this->queue . self::FAILED_POSTFIX);
} catch (AMQPChannelException $e) {
    // DLX queue might not exist
    Log::critical("Failed to publish to DLX queue", [
        'dlx_queue' => $this->queue . self::FAILED_POSTFIX,
        'error' => $e->getMessage(),
        'event' => $newMessage->getEventName()
    ]);

    // Track metric
    $this->statsD->increment('rmq_consumer_dlx_publish_failed', $tags);

    // Options:
    // 1. ACK anyway (message lost but doesn't block queue)
    // 2. NACK without requeue (send to RabbitMQ's built-in DLX if configured)
    // 3. Don't ACK (message redelivered, infinite loop)

    // Recommended: ACK and alert
    $message->ack();

    // Alert operations immediately
    // ... alerting logic ...

    return;
}

// Only ACK if DLX publish succeeded
try {
    $message->ack();
} catch (Throwable $e) {
    // ACK failed but message in DLX
    Log::error("ACK failed after DLX publish - potential duplicate in DLX", [
        'error' => $e->getMessage()
    ]);
}
```

---

### 🟡 MEDIUM: No Validation That DLX Queue Was Created

**Location**: [Section 2.4 - Line 90](ARCHITECTURE_DEEP_DIVE.md#L880-L899)

**Problem**:
```php
$this->createQueue($dlx);
```

**Breaking Point**:
- RabbitMQ permissions issue
- Queue creation fails silently
- Code continues
- Later, when message goes to DLX, publish fails

**Impact**:
- DLX mechanism broken without obvious error
- Failed messages lost
- Discovered only when first message needs DLX

**Recommendation**:
```php
// In createQueue method, verify creation
public function createQueue(string $name, AMQPTable $arguments = null): void
{
    $channel = $this->getChannel();

    try {
        $channel->queue_declare(
            $name,
            false,  // passive
            true,   // durable
            false,  // exclusive
            false,  // auto_delete
            false,  // nowait
            $arguments
        );
    } catch (AMQPProtocolChannelException $e) {
        // Queue creation failed
        throw new \RuntimeException(
            "Failed to create queue '$name': " . $e->getMessage() .
            ". Check RabbitMQ permissions for user.",
            0,
            $e
        );
    }

    // Verify queue exists with passive declare
    try {
        $channel->queue_declare($name, true); // passive=true
    } catch (AMQPProtocolChannelException $e) {
        throw new \RuntimeException(
            "Queue '$name' created but verification failed",
            0,
            $e
        );
    }
}
```

---

## 7. Race Conditions & Concurrency

### Related Section
[ARCHITECTURE_DEEP_DIVE.md - Section 1.7 (Lines 491-503)](ARCHITECTURE_DEEP_DIVE.md#L491-L503) and [Section 2.3 (Lines 615-645)](ARCHITECTURE_DEEP_DIVE.md#L615-L645)

### 🟠 HIGH: Shared Static Variables Not Thread-Safe

**Location**: [NanoServiceClass.php - static $sharedConnection and $sharedChannel](../src/NanoServiceClass.php)

**Problem**:
```php
protected static ?AMQPStreamConnection $sharedConnection = null;
protected static ?AMQPChannel $sharedChannel = null;
```

**Breaking Point**:
- PHP-FPM or pthreads environment (rare but possible)
- Two requests access static variable simultaneously
- Race condition: both see null, both create connection
- Connection pooling broken

**Impact**:
- Multiple connections created per process
- Defeats connection pooling
- Channel exhaustion risk returns

**Current Status**:
PHP traditionally single-threaded per request, so this is LOW RISK for most deployments.

**But**: With PHP 8.1+ fibers, or extensions like Swoole/RoadRunner/ReactPHP, concurrency is possible.

**Recommendation**:
```php
// Add mutex for connection creation
protected static ?AMQPStreamConnection $sharedConnection = null;
protected static ?AMQPChannel $sharedChannel = null;
private static ?SemaphoreResource $connectionMutex = null;

protected static function getConnection(): AMQPStreamConnection
{
    // Initialize mutex
    if (self::$connectionMutex === null && function_exists('sem_get')) {
        self::$connectionMutex = sem_get(ftok(__FILE__, 'c'));
    }

    // Acquire lock
    if (self::$connectionMutex) {
        sem_acquire(self::$connectionMutex);
    }

    try {
        if (self::$sharedConnection && self::$sharedConnection->isConnected()) {
            return self::$sharedConnection;
        }

        // Create connection...
        self::$sharedConnection = new AMQPStreamConnection(...);

        return self::$sharedConnection;
    } finally {
        // Release lock
        if (self::$connectionMutex) {
            sem_release(self::$connectionMutex);
        }
    }
}
```

---

### 🟡 MEDIUM: Race Condition in Message Retry

**Location**: [Section 3.2 Phase 8A - Lines 223-229](ARCHITECTURE_DEEP_DIVE.md#L2011-L2094)

**Problem**:
```php
$headers = new AMQPTable([
    'x-delay' => $this->getBackoff($retryCount),
    'x-retry-count' => $retryCount
]);
$newMessage->set('application_headers', $headers);
$this->getChannel()->basic_publish($newMessage, $this->queue, $key);
$message->ack();
```

**Breaking Point** (theoretical, very low probability):
```
1. Worker A processes message (attempt 1)
2. Worker A fails, publishes retry with x-retry-count=1
3. Worker A crashes BEFORE ACK
4. Message redelivered to Worker B
5. Worker B also fails, publishes retry with x-retry-count=1 (reads from original message)
6. Now TWO retry messages in delayed exchange, both with x-retry-count=1
7. After delay, both delivered
8. Message processed twice
```

**Impact**:
- Duplicate message processing
- Retry count inconsistency
- Rare but possible in high-crash scenarios

**Recommendation**:
```php
// Add unique message ID to prevent duplicates
$messageId = $newMessage->get('message_id') ?? uniqid('msg_', true);
$newMessage->set('message_id', $messageId);

// Track processed message IDs in Redis/Memcached
if ($this->cache->has("processed_$messageId")) {
    // Already processed or in progress
    $message->ack(); // Duplicate, skip
    return;
}

// Mark as processing
$this->cache->set("processed_$messageId", time(), 600); // 10 min TTL

// Process message...

// After successful processing, update to completed
$this->cache->set("processed_$messageId", 'completed', 3600); // 1 hour TTL
```

---

## 8. Configuration & Defaults

### Related Section
[ARCHITECTURE_DEEP_DIVE.md - Section 1.4 (Lines 213-503)](ARCHITECTURE_DEEP_DIVE.md#L213-L503)

### 🟡 MEDIUM: No Validation of Environment Variables

**Location**: [NanoServiceClass.php - getEnv() method](../src/NanoServiceClass.php)

**Problem**:
- Environment variables loaded without validation
- Missing variables return empty string or null
- Code continues with invalid configuration
- Errors surface later as cryptic RabbitMQ errors

**Breaking Point**:
```php
$queue = $this->getEnv(self::MICROSERVICE_NAME); // Returns ""
$queue = $this->getNamespace($queue); // "easyweek."
// Creates queue with name "easyweek." (invalid)
```

**Impact**:
- Invalid queue names
- Cryptic error messages
- Difficult debugging
- Runtime failures instead of startup failures

**Recommendation**:
```php
protected function getEnv(string $key, bool $required = false): string
{
    $value = getenv($key);

    if ($required && empty($value)) {
        throw new \RuntimeException(
            "Required environment variable '$key' not set. " .
            "Check your .env file or environment configuration."
        );
    }

    return $value ?: '';
}

// In init() or constructor, validate required variables
private function validateConfiguration(): void
{
    $required = [
        'AMQP_HOST',
        'AMQP_PORT',
        'AMQP_USER',
        'AMQP_PASSWORD',
        'AMQP_MICROSERVICE_NAME',
        'PROJECT_NAME'
    ];

    $missing = [];
    foreach ($required as $var) {
        if (empty(getenv($var))) {
            $missing[] = $var;
        }
    }

    if (!empty($missing)) {
        throw new \RuntimeException(
            "Missing required environment variables: " . implode(', ', $missing)
        );
    }

    // Validate values
    $port = (int)getenv('AMQP_PORT');
    if ($port < 1 || $port > 65535) {
        throw new \InvalidArgumentException("AMQP_PORT must be between 1-65535, got: $port");
    }
}
```

---

### 🟡 MEDIUM: No Defaults for Tries and Backoff

**Location**: [NanoConsumer.php - tries() and backoff() methods](../src/NanoConsumer.php)

**Problem**:
- If user doesn't call `tries()` or `backoff()`, what are defaults?
- Code may fail with "divide by zero" or "array access" errors
- No documented default behavior

**Recommendation**:
```php
// In NanoConsumer constructor or class properties
protected int $tries = 3; // Default
protected array|int $backoff = [1, 5, 60]; // Default

// Document in class docblock
/**
 * NanoConsumer
 *
 * Default configuration:
 * - tries: 3
 * - backoff: [1, 5, 60] seconds
 * - prefetch: 1
 *
 * Override with:
 * ->tries(5)
 * ->backoff([2, 10, 120])
 */
```

---

## 9. Dead Letter Queue Issues

### Related Section
[ARCHITECTURE_DEEP_DIVE.md - Section 2.4 (Lines 733-979)](ARCHITECTURE_DEEP_DIVE.md#L733-L979)

### 🟠 HIGH: No Monitoring of DLX Queue Depth

**Location**: [Section 1.4 - Decision 4 (Lines 283-310)](ARCHITECTURE_DEEP_DIVE.md#L283-L310)

**Problem**:
- Messages go to `.failed` queue
- No automatic monitoring or alerting
- Queue can grow indefinitely
- No visibility into failures until manual check

**Impact**:
- Silent failure accumulation
- Delayed incident response
- Disk space exhaustion on RabbitMQ
- Lost messages if queue purged

**Recommendation**:
```php
// Add periodic DLX queue monitoring
private int $lastDLXCheckTime = 0;

private function checkDLXQueueDepth(): void
{
    if (time() - $this->lastDLXCheckTime < 60) {
        return; // Check once per minute
    }

    $this->lastDLXCheckTime = time();

    try {
        $dlxQueueName = $this->queue . self::FAILED_POSTFIX;
        [$queueName, $messageCount, $consumerCount] =
            $this->getChannel()->queue_declare($dlxQueueName, true); // passive

        // Emit metric
        $this->statsD->gauge('rmq_dlx_queue_depth', $messageCount, [
            'queue' => $dlxQueueName
        ]);

        // Alert if threshold exceeded
        if ($messageCount > 1000) {
            error_log("DLX queue depth high: $messageCount messages in $dlxQueueName");
        }
    } catch (Throwable $e) {
        error_log("Failed to check DLX queue depth: " . $e->getMessage());
    }
}

// Call from consumeCallback
$this->checkDLXQueueDepth();
```

---

### 🟡 MEDIUM: No TTL on DLX Messages

**Location**: [Section 2.4 - Line 90](ARCHITECTURE_DEEP_DIVE.md#L880-L899)

**Problem**:
- Messages in `.failed` queue persist forever
- No automatic cleanup
- Old messages become irrelevant (data stale)
- Queue grows indefinitely

**Impact**:
- Disk space exhaustion
- Difficult to find recent failures among old ones
- Performance degradation as queue grows

**Recommendation**:
```php
// When creating DLX queue, add TTL
$dlxArguments = new AMQPTable([
    'x-message-ttl' => 7 * 24 * 60 * 60 * 1000, // 7 days in milliseconds
    'x-max-length' => 100000, // Prevent unbounded growth
    'x-overflow' => 'drop-head' // Drop oldest when limit reached
]);

$this->createQueue($dlx, $dlxArguments);
```

Document in operations guide:
- DLX messages expire after 7 days
- Review and reprocess within 7 days
- Archived messages can be restored from RabbitMQ backups if needed

---

### 🟡 MEDIUM: No Way to Reprocess DLX Messages

**Location**: [Section 2.4.7 - Complete Architecture](ARCHITECTURE_DEEP_DIVE.md#L940-L978)

**Problem**:
- Messages in `.failed` queue are dead-end
- No built-in mechanism to reprocess after bug fix
- Manual intervention required (shovel plugin, custom script)

**Recommendation**:
```php
// Add reprocessDLX() method
public function reprocessDLXMessages(int $maxMessages = 100): void
{
    $dlxQueueName = $this->queue . self::FAILED_POSTFIX;

    echo "Reprocessing up to $maxMessages messages from $dlxQueueName\n";

    $processed = 0;
    while ($processed < $maxMessages) {
        // Get message from DLX
        $message = $this->getChannel()->basic_get($dlxQueueName);

        if (!$message) {
            break; // No more messages
        }

        // Remove retry count (start fresh)
        $newMessage = new AMQPMessage(
            $message->getBody(),
            $message->get_properties()
        );
        $newMessage->set('application_headers', new AMQPTable([]));

        // Republish to main queue
        $routingKey = $message->get('type');
        $this->getChannel()->basic_publish(
            $newMessage,
            $this->getNamespace($this->exchange), // Main exchange
            $routingKey
        );

        // ACK from DLX
        $message->ack();

        $processed++;
        echo "Reprocessed message $processed\n";
    }

    echo "Reprocessed $processed messages\n";
}
```

---

## 10. Critical Priority Summary

### 🔴 CRITICAL (Fix Immediately)

1. **Connection Leak in Shutdown** ([Section 1](#1-connection--channel-management))
   - Impact: Connection exhaustion, memory leaks
   - Fix: Wrap both channel and connection close in try-catch

2. **Double ACK Possibility** ([Section 2](#2-message-processing-safety))
   - Impact: Channel closure, worker crash
   - Fix: Add boolean flag to track ACK status

3. **Shutdown Not Called on Fatal Errors** ([Section 1](#1-connection--channel-management))
   - Impact: Zombie connections, duplicate processing
   - Fix: Add signal handlers for SIGTERM/SIGINT

4. **No Handling for Poisoned Messages** ([Section 6](#6-error-handling--recovery))
   - Impact: Queue blocked, service outage
   - Fix: Add fatal error handler, detect repeated failures

5. **Retry Count Persists Across Redeliveries** ([Section 3](#3-retry-logic--backoff))
   - Impact: False retry exhaustion
   - Fix: Differentiate redelivery from retry

---

### 🟠 HIGH (Fix Soon)

6. **No Connection Health Check** ([Section 1](#1-connection--channel-management))
   - Impact: False failures, unnecessary retries
   - Fix: Check heartbeat before reusing connection

7. **No Timeout on User Callback** ([Section 2](#2-message-processing-safety))
   - Impact: Hung workers, queue blockage
   - Fix: Add configurable timeout with pcntl_alarm

8. **Metrics Failures Break Processing** ([Section 4](#4-metrics--observability))
   - Impact: Business logic fails due to metrics
   - Fix: Wrap all metrics calls in try-catch

9. **DLX Publish Can Fail Silently** ([Section 6](#6-error-handling--recovery))
   - Impact: Lost messages, duplicates
   - Fix: Verify DLX queue exists, handle failures

10. **No Monitoring of DLX Queue** ([Section 9](#9-dead-letter-queue-issues))
    - Impact: Silent failure accumulation
    - Fix: Emit metrics for DLX queue depth

---

### 🟡 MEDIUM (Plan to Fix)

11. Channel not recreated after AMQPChannelException
12. Message body not validated before processing
13. No maximum backoff cap
14. Backoff array configuration confusing
15. No circuit breaker on StatsD
16. High-cardinality tags not enforced
17. Memory leak from unacknowledged messages
18. Channel exhaustion risk at scale
19. No limit on retry message size
20. No validation of environment variables
21. No defaults for tries and backoff
22. Delayed exchange availability not checked
23. Exception in catch callback loses context
24. No TTL on DLX messages
25. No way to reprocess DLX messages

---

## Implementation Priority

### Phase 1 (Week 1) - Critical Safety
- Connection leak fix
- Double ACK prevention
- Signal handlers
- Poisoned message handling

### Phase 2 (Week 2) - Stability
- Connection health checks
- Callback timeout
- Metrics error handling
- DLX monitoring

### Phase 3 (Week 3-4) - Robustness
- Environment validation
- Configuration defaults
- Backoff improvements
- Circuit breakers

### Phase 4 (Future) - Operations
- DLX reprocessing tools
- Enhanced monitoring
- Documentation updates
- Testing improvements

---

**End of Recommendations**

*Review this document alongside ARCHITECTURE_DEEP_DIVE.md to understand context*
*Each recommendation links to specific sections for reference*
