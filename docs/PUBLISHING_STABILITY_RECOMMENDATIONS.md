# Publishing Architecture: Stability & Safety Recommendations

> **Purpose**: Critical analysis of event publishing for code-breaking points, potential problems, and stability optimizations
> **Source**: Based on [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md)
> **Focus**: Bug safety, crash prevention, data integrity for publishing flow (NOT code aesthetics)

**Last Updated**: 2026-01-30

---

## Table of Contents

1. [Critical Issues (HIGH PRIORITY)](#critical-issues-high-priority)
2. [High Priority Issues](#high-priority-issues)
3. [Medium Priority Issues](#medium-priority-issues)
4. [Low Priority Issues (Stability Improvements)](#low-priority-issues-stability-improvements)
5. [Performance Optimizations (Stability Related)](#performance-optimizations-stability-related)
6. [Edge Cases & Race Conditions](#edge-cases--race-conditions)
7. [Summary by Priority](#summary-by-priority)
8. [Testing Recommendations](#testing-recommendations)

---

## Critical Issues (HIGH PRIORITY)

### 🔴 CRITICAL-1: JSON Decode Without Error Handling

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:206-217](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L206-L217) - Message Data Management Pattern

**Problem**:
```php
// Read body
$bodyData = json_decode($this->getBody(), true);

// Modify data
$bodyData['section']['key'] = $value;

// Write back
$this->setBody(json_encode($bodyData));
```

**What Can Break**:
1. `json_decode()` returns `null` on malformed JSON (silently fails)
2. Setting keys on `null` causes fatal error: "Cannot use a scalar value as an array"
3. No validation that decode succeeded before accessing array keys
4. `json_encode()` can return `false` on encoding errors (e.g., invalid UTF-8)

**Impact**:
- **CRASH**: Fatal error in production when processing malformed messages
- **DATA LOSS**: Silently corrupted message bodies
- **UNPREDICTABLE**: Fails only on specific edge cases (UTF-8 issues, circular refs, etc.)

**Recommendation**:
```php
// Safe pattern
$bodyData = json_decode($this->getBody(), true, 512, JSON_THROW_ON_ERROR);
// OR with error checking:
$bodyData = json_decode($this->getBody(), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    throw new \RuntimeException('Invalid message body JSON: ' . json_last_error_msg());
}
```

**Affected Methods** (from architecture doc context):
- `addPayload()` - [NanoServiceMessage.php:105-110](../src/NanoServiceMessage.php#L105-L110)
- `addMeta()` - [NanoServiceMessage.php:231-236](../src/NanoServiceMessage.php#L231-L236)
- `setDebug()` - [NanoServiceMessage.php:276-281](../src/NanoServiceMessage.php#L276-L281)
- `getData()`, `addData()`, `setDataAttribute()` - All data manipulation methods

---

### 🔴 CRITICAL-2: PostgreSQL Connection Leak in publish()

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:445-458](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L445-L458) - PostgreSQL Connection

**Problem**:
```php
$pdo = new \PDO($dsn, $_ENV['DB_BOX_USER'], $_ENV['DB_BOX_PASS'], [
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
]);

$stmt = $pdo->prepare("INSERT INTO ...");
$stmt->execute([...]);

// No explicit close - relies on PHP garbage collection
```

**What Can Break**:
1. **Connection leak**: New PDO connection created for every `publish()` call
2. In long-running workers, connections accumulate until hitting PostgreSQL `max_connections`
3. Each connection holds server resources (memory, file descriptors)
4. No connection pooling like RabbitMQ has
5. No transaction management - connection may be left in uncommitted state on exception

**Impact**:
- **OUTAGE**: PostgreSQL refuses new connections when max_connections reached
- **DEGRADATION**: Slow connection creation on every publish (TCP handshake, auth, etc.)
- **RESOURCE EXHAUSTION**: Database server memory exhaustion

**Recommendation**:
```php
// Add static connection pooling similar to RabbitMQ
protected static ?\PDO $sharedPDOConnection = null;

private function getPDOConnection(): \PDO
{
    if (self::$sharedPDOConnection) {
        // Verify connection is still alive
        try {
            self::$sharedPDOConnection->query('SELECT 1');
            return self::$sharedPDOConnection;
        } catch (\PDOException $e) {
            self::$sharedPDOConnection = null;
        }
    }

    // Create new connection
    $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", ...);
    self::$sharedPDOConnection = new \PDO($dsn, $user, $pass, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_PERSISTENT => false, // Avoid PHP persistent connection issues
    ]);

    return self::$sharedPDOConnection;
}
```

**Additional Safety**:
- Add explicit `BEGIN/COMMIT/ROLLBACK` if needed for transactional safety
- Add connection health check similar to RabbitMQ's `isConnectionHealthy()`
- Consider using existing app's DB connection if available (avoid duplicate connections)

---

### 🔴 CRITICAL-3: Environment Variable Access Without Validation

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:382-393](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L382-L393) - Environment Variable Validation

**Problem**:
```php
$requiredVars = ['DB_BOX_HOST', 'DB_BOX_PORT', 'DB_BOX_NAME', 'DB_BOX_USER', 'DB_BOX_PASS', 'DB_BOX_SCHEMA'];
foreach ($requiredVars as $var) {
    if (!isset($_ENV[$var])) {
        throw new \RuntimeException("Missing required environment variable: {$var}");
    }
}
```

**What Can Break**:
1. Checks `$_ENV` but code uses `$_ENV` directly later - inconsistent with `getEnv()` priority
2. `getEnv()` has complex fallback chain but validation only checks `$_ENV`
3. Empty string values pass validation: `$_ENV['DB_BOX_HOST'] = ''` is "set" but invalid
4. Validation happens in `publish()` method - fails at runtime, not initialization
5. Race condition: env vars could change between validation and usage

**Impact**:
- **RUNTIME FAILURE**: Validation passes but connection fails with empty/wrong values
- **INCONSISTENT BEHAVIOR**: Works in some envs (where `getenv()` set) but fails where only `$_ENV` set
- **DELAYED FAILURE**: Error not discovered until first publish attempt

**Recommendation**:
```php
// Move validation to constructor for fail-fast
public function __construct(array $config = [])
{
    parent::__construct($config);
    $this->validateOutboxConfiguration();
    $this->statsD = new StatsDClient();
}

private function validateOutboxConfiguration(): void
{
    $requiredVars = ['DB_BOX_HOST', 'DB_BOX_PORT', 'DB_BOX_NAME', 'DB_BOX_USER', 'DB_BOX_PASS', 'DB_BOX_SCHEMA'];
    foreach ($requiredVars as $var) {
        // Use getEnv() for consistency
        $value = $this->getEnv('AMQP_' . $var); // or appropriate prefix
        if (empty($value)) {
            throw new \RuntimeException(
                "Missing or empty required environment variable: {$var}. " .
                "This is required for PostgreSQL outbox publishing."
            );
        }
    }

    // Validate specific formats
    if (!is_numeric($this->getEnv('AMQP_DB_BOX_PORT'))) {
        throw new \RuntimeException('DB_BOX_PORT must be numeric');
    }
}
```

**Additional Validation Needed**:
- Port number range (1-65535)
- Host format (not empty, valid hostname/IP)
- Schema name (PostgreSQL naming rules)

---

### 🔴 CRITICAL-4: Race Condition in Channel Pooling

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:1194-1219](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L1194-L1219) - getChannel() with Pooling

**Problem**:
```php
public function getChannel()
{
    // 1. Try shared channel first
    if (self::$sharedChannel && self::$sharedChannel->is_open()) {
        return self::$sharedChannel;
    }

    // 2. Create new channel if needed
    if (!$this->channel || !$this->channel->is_open()) {
        $this->channel = $this->getConnection()->channel();

        // 3. Store in shared pool (CRITICAL!)
        self::$sharedChannel = $this->channel;

        // 4. Track metrics
        // ...
    }

    return $this->channel;
}
```

**What Can Break**:
1. **Race condition**: Two instances call `getChannel()` simultaneously
2. Both see `self::$sharedChannel` is null/closed
3. Both create new channel
4. Second one overwrites first in `self::$sharedChannel`
5. First channel becomes orphaned (memory leak + resource leak)
6. In multi-threaded environments (parallel workers), channels can conflict

**Impact**:
- **CHANNEL LEAK**: Orphaned channels never closed, accumulate over time
- **METRIC CORRUPTION**: `rmq_channel_active` shows 1 but actually >1 channels exist
- **POTENTIAL CRASH**: If leaked channels hit RabbitMQ limits

**Recommendation**:
```php
// Add synchronization for shared channel creation
private static $channelCreationLock = false;

public function getChannel()
{
    // 1. Try shared channel first
    if (self::$sharedChannel && self::$sharedChannel->is_open()) {
        return self::$sharedChannel;
    }

    // 2. Prevent concurrent channel creation
    while (self::$channelCreationLock) {
        usleep(10000); // 10ms - wait for other instance to finish
    }

    // 3. Check again after waiting (another instance may have created it)
    if (self::$sharedChannel && self::$sharedChannel->is_open()) {
        return self::$sharedChannel;
    }

    // 4. Lock and create
    self::$channelCreationLock = true;
    try {
        if (!$this->channel || !$this->channel->is_open()) {
            $this->channel = $this->getConnection()->channel();
            self::$sharedChannel = $this->channel;

            $this->statsD->increment('rmq_channel_total', [...]);
            $this->statsD->gauge('rmq_channel_active', 1, [...]);
        }
        return $this->channel;
    } finally {
        self::$channelCreationLock = false;
    }
}
```

**Note**: PHP is not thread-safe by default, but this helps with concurrent requests in async environments (Swoole, ReactPHP, etc.)

---

### 🔴 CRITICAL-5: Channel Health Check Before Publish

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:673-677](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L673-L677) - Perform Publish

**Problem**:
```php
$exchange = $this->getNamespace($this->exchange);
$this->getChannel()->basic_publish($this->message, $exchange, $event);
```

**What Can Break**:
1. Channel may be closed between `getChannel()` and `basic_publish()`
2. `getChannel()` checks `is_open()` but doesn't verify channel is functional
3. RabbitMQ may have closed channel due to error in previous operation
4. No recovery mechanism if publish fails mid-operation

**Impact**:
- **PUBLISH FAILURE**: Throws exception instead of gracefully recovering
- **NO RETRY**: Error propagates to application without auto-recovery attempt

**Recommendation**:
```php
private function publishWithRetry(string $exchange, string $event, int $maxAttempts = 2): void
{
    $lastException = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $channel = $this->getChannel();

            // Verify channel is truly functional (not just is_open())
            if (!$channel->is_open()) {
                $this->resetChannel();
                $channel = $this->getChannel();
            }

            $channel->basic_publish($this->message, $exchange, $event);
            return; // Success

        } catch (AMQPChannelClosedException $e) {
            $lastException = $e;
            $this->resetChannel(); // Force recreation

            if ($attempt < $maxAttempts) {
                usleep(100000); // 100ms delay before retry
                continue;
            }
        }
    }

    throw $lastException; // All retries exhausted
}

private function resetChannel(): void
{
    self::$sharedChannel = null;
    $this->channel = null;
}
```

---

## High Priority Issues

### 🟠 HIGH-1: No Timeout on PDO Query Execution

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:464-479](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L464-L479) - Insert into Outbox Table

**Problem**:
```php
$stmt = $pdo->prepare("INSERT INTO {$_ENV['DB_BOX_SCHEMA']}.outbox (...)");
$stmt->execute([...]);
```

**What Can Break**:
1. No timeout configured on PDO connection
2. If database is slow/locked, `execute()` blocks indefinitely
3. Worker process hangs waiting for DB
4. In long-running workers, accumulates hung threads

**Impact**:
- **HANGING WORKERS**: Process stuck indefinitely on slow DB
- **CASCADING FAILURE**: All workers eventually hung, service stops processing
- **NO VISIBILITY**: No timeout error, just silent hang

**Recommendation**:
```php
// Add timeout in PDO connection
$pdo = new \PDO($dsn, $_ENV['DB_BOX_USER'], $_ENV['DB_BOX_PASS'], [
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    \PDO::ATTR_TIMEOUT => 5, // 5 second timeout
]);

// OR use prepared statement timeout (PostgreSQL specific)
$stmt = $pdo->prepare("INSERT INTO ...");
$pdo->setAttribute(\PDO::ATTR_TIMEOUT, 5);
$stmt->execute([...]);
```

**Also Consider**:
- Add statement timeout in PostgreSQL: `SET statement_timeout = '5s'`
- Emit metric for slow DB operations: `outbox_insert_duration_ms`

---

### 🟠 HIGH-2: Message Body Injection in setEvent()

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:401-403](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L401-L403) - Set Event Name

**Problem**:
```php
$this->message->setEvent($event);
```

**What Can Break**:
1. If `$event` is user-controlled or contains special characters, could break routing
2. RabbitMQ routing keys have restrictions (alphanumeric + dots)
3. No validation that `$event` is a valid routing key format
4. Could inject malicious routing patterns

**Impact**:
- **ROUTING FAILURE**: Events routed to wrong queues or dropped
- **SECURITY**: Potential for routing key injection attacks
- **DEBUG NIGHTMARE**: Events mysteriously not arriving at consumers

**Recommendation**:
```php
private function validateEventName(string $event): void
{
    // RabbitMQ routing key rules: alphanumeric, dots, hyphens, underscores
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $event)) {
        throw new \InvalidArgumentException(
            "Invalid event name: '{$event}'. " .
            "Must contain only alphanumeric characters, dots, hyphens, and underscores."
        );
    }

    // Prevent too long routing keys (RabbitMQ limit is 255 bytes)
    if (strlen($event) > 255) {
        throw new \InvalidArgumentException(
            "Event name too long: " . strlen($event) . " bytes (max 255)"
        );
    }

    // Prevent empty
    if (empty($event)) {
        throw new \InvalidArgumentException("Event name cannot be empty");
    }
}

// Call before setting
public function publish(string $event): void
{
    $this->validateEventName($event);
    $this->message->setEvent($event);
    // ...
}
```

---

### 🟠 HIGH-3: SQL Injection Risk in Schema Name

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:465-466](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L465-L466) - Schema in Query

**Problem**:
```php
$stmt = $pdo->prepare("
    INSERT INTO {$_ENV['DB_BOX_SCHEMA']}.outbox (...)
");
```

**What Can Break**:
1. Schema name interpolated directly into SQL (not parameterized)
2. If `DB_BOX_SCHEMA` is compromised: `public; DROP TABLE outbox; --`
3. PDO parameter binding doesn't work for table/schema names
4. Relies on environment variable trust

**Impact**:
- **SQL INJECTION**: Malicious schema name can inject arbitrary SQL
- **DATA LOSS**: Could drop tables, modify data, exfiltrate data
- **PRIVILEGE ESCALATION**: Could access other schemas

**Recommendation**:
```php
// Validate schema name format on initialization
private function validateSchemaName(string $schema): void
{
    // PostgreSQL identifier rules: alphanumeric + underscore, starts with letter
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $schema)) {
        throw new \RuntimeException(
            "Invalid DB_BOX_SCHEMA: '{$schema}'. " .
            "Must start with letter and contain only alphanumeric/underscore."
        );
    }

    // Prevent SQL keywords
    $forbidden = ['SELECT', 'DROP', 'INSERT', 'UPDATE', 'DELETE', 'CREATE'];
    if (in_array(strtoupper($schema), $forbidden)) {
        throw new \RuntimeException("DB_BOX_SCHEMA cannot be SQL keyword: {$schema}");
    }
}

// Store validated schema
private string $validatedSchema;

public function __construct(array $config = [])
{
    parent::__construct($config);

    $schema = $this->getEnv('AMQP_DB_BOX_SCHEMA');
    $this->validateSchemaName($schema);
    $this->validatedSchema = $schema;

    $this->statsD = new StatsDClient();
}

// Use validated version in queries
$stmt = $pdo->prepare("
    INSERT INTO {$this->validatedSchema}.outbox (...)
");
```

**Alternative**: Use PostgreSQL's `quote_identifier()` or schema configuration in search_path

---

### 🟠 HIGH-4: No Validation of Message Size Before Publish

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:656-666](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L656-L666) - Measure Payload Size

**Problem**:
```php
$payloadSize = strlen($this->message->getBody());
$this->statsD->histogram('rmq_payload_bytes', $payloadSize, $tags, ...);

// Then immediately publish - no size check
$this->getChannel()->basic_publish($this->message, $exchange, $event);
```

**What Can Break**:
1. RabbitMQ has max message size limit (default 128MB, often reduced to 16MB)
2. Oversized messages rejected with obscure error
3. Worker crashes or hangs on large message serialization
4. PostgreSQL JSONB has max size (~255MB but performance degrades much earlier)

**Impact**:
- **PUBLISH FAILURE**: Large messages silently rejected
- **PERFORMANCE DEGRADATION**: Huge messages slow down entire queue
- **OUTAGE**: RabbitMQ memory exhaustion from giant messages

**Recommendation**:
```php
// Add size limits
private const MAX_MESSAGE_SIZE_BYTES = 1048576; // 1MB - adjust based on your needs
private const WARN_MESSAGE_SIZE_BYTES = 524288; // 512KB - warning threshold

private function validateMessageSize(int $size): void
{
    if ($size > self::MAX_MESSAGE_SIZE_BYTES) {
        throw new \RuntimeException(
            "Message too large: {$size} bytes (max " . self::MAX_MESSAGE_SIZE_BYTES . ")"
        );
    }

    if ($size > self::WARN_MESSAGE_SIZE_BYTES) {
        // Log warning but allow
        error_log("WARNING: Large message detected: {$size} bytes");

        // Emit metric for monitoring
        $this->statsD->increment('rmq_large_message_warning_total', [
            'service' => $this->getEnv(self::MICROSERVICE_NAME),
        ]);
    }
}

// Apply in publishToRabbit()
$payloadSize = strlen($this->message->getBody());
$this->validateMessageSize($payloadSize);

$this->statsD->histogram('rmq_payload_bytes', $payloadSize, $tags, ...);
```

---

### 🟠 HIGH-5: Metrics Sampling Can Hide Critical Errors

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:647-649](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L647-L649) - Sample Rate

**Problem**:
```php
$sampleRate = $this->statsD->getSampleRate('ok_events');
$this->statsD->increment('rmq_publish_total', $tags, $sampleRate);
```

**What Can Break**:
1. If `ok_events` sample rate is 0.1 (10%), only 10% of successful publishes tracked
2. Error tracking uses 1.0 sample rate (good)
3. But success/failure ratio calculations will be wrong: `rmq_publish_total` under-counted
4. Can't accurately calculate error rate: `errors / total` where total is sampled

**Impact**:
- **MISLEADING METRICS**: Error rate appears higher than reality
- **MISSED ISSUES**: Low-frequency problems hidden by sampling
- **ALERT FATIGUE**: False positives from inaccurate ratios

**Recommendation**:
```php
// Track total attempts at 100% for accurate error rate calculation
$this->statsD->increment('rmq_publish_total', $tags, 1.0); // Always 100%

// Track detailed success metrics with sampling (optional, for volume reduction)
$sampleRate = $this->statsD->getSampleRate('ok_events');
$this->statsD->increment('rmq_publish_success_sampled', $tags, $sampleRate);

// Now error rate is accurate:
// rate(rmq_publish_error_total) / rate(rmq_publish_total)
```

**Alternative**: Document that `rmq_publish_total` is sampled and adjust PromQL queries:
```promql
# Adjust for sample rate
rate(rmq_publish_error_total[5m])
/
(rate(rmq_publish_total[5m]) / 0.1)  # Compensate for 10% sampling
```

---

## Medium Priority Issues

### 🟡 MEDIUM-1: Timestamp Milliseconds May Not Be Consistent

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:123](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L123) - created_at Timestamp

**Problem**:
```php
'created_at' => '2026-01-29 10:15:30.123',  // Timestamp with milliseconds
```

**What Can Break**:
1. PHP's `date()` doesn't include milliseconds by default
2. `microtime()` needed for millisecond precision
3. Inconsistent timestamp formats if not careful
4. Timezone issues if not explicitly UTC

**Impact**:
- **DEBUGGING**: Inaccurate timing for event sequencing
- **MONITORING**: Can't measure sub-second latencies accurately
- **TIMEZONE BUGS**: Events appear out of order across timezones

**Recommendation**:
```php
// Ensure consistent timestamp format with milliseconds and UTC
private function getCurrentTimestampWithMillis(): string
{
    // Get microtime as float
    $microtime = microtime(true);

    // Create DateTime from microtime
    $dt = \DateTime::createFromFormat('U.u', sprintf('%.6F', $microtime));
    if (!$dt) {
        // Fallback if createFromFormat fails
        $dt = new \DateTime();
    }

    // Force UTC
    $dt->setTimezone(new \DateTimeZone('UTC'));

    // Format with milliseconds: 2026-01-29 10:15:30.123
    return $dt->format('Y-m-d H:i:s.v');
}

// Use in dataStructure()
'created_at' => $this->getCurrentTimestampWithMillis(),
```

---

### 🟡 MEDIUM-2: No Partition Key Strategy Documented

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:477-478](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L477-L478) - Partition Key

**Problem**:
```php
$stmt->execute([
    $_ENV['AMQP_MICROSERVICE_NAME'],
    $event,
    $messageBody,
    null,  // partition_key (optional, for ordering)
]);
```

**What Can Break**:
1. Always passing `null` for partition_key
2. No way to ensure ordering of related events
3. pg2event dispatcher may process events out of order
4. For events that must be ordered (e.g., user.created → user.updated), no guarantee

**Impact**:
- **DATA INCONSISTENCY**: Events processed out of order cause invalid state
- **RACE CONDITIONS**: Newer events processed before older ones

**Recommendation**:
```php
// Add partition key support to publisher
public function setPartitionKey(?string $partitionKey): self
{
    $this->partitionKey = $partitionKey;
    return $this;
}

// Use in publish()
$stmt->execute([
    $_ENV['AMQP_MICROSERVICE_NAME'],
    $event,
    $messageBody,
    $this->partitionKey ?? null,
]);

// Document usage:
// For ordered events, use tenant/entity ID as partition key:
$publisher
    ->setMessage($message)
    ->setPartitionKey("tenant:{$tenantId}")
    ->publish('user.updated');

// pg2event dispatcher MUST process events with same partition_key in order
```

**Critical**: This requires pg2event dispatcher logic update to respect partition_key ordering

---

### 🟡 MEDIUM-3: Connection Heartbeat May Not Detect All Failures

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:1278-1299](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L1278-L1299) - Heartbeat Check

**Problem**:
```php
public function isConnectionHealthy(): bool
{
    try {
        $connection = $this->getConnection();

        if (!$connection->isConnected()) {
            $this->reset();
            return false;
        }

        $connection->checkHeartBeat();
        return true;
    } catch (\PhpAmqpLib\Exception\AMQPHeartbeatMissedException $e) {
        $this->reset();
        return false;
    }
}
```

**What Can Break**:
1. Only catches `AMQPHeartbeatMissedException`
2. Other connection exceptions (IO errors, socket errors) not caught
3. `checkHeartBeat()` may throw other exception types
4. Network partition may not trigger heartbeat failure immediately (up to 360s wait)

**Impact**:
- **UNHANDLED EXCEPTIONS**: Other exception types crash the worker
- **DELAYED DETECTION**: Network issues not detected for up to 6 minutes
- **SILENT FAILURE**: Connection appears healthy but is actually broken

**Recommendation**:
```php
public function isConnectionHealthy(): bool
{
    try {
        $connection = $this->getConnection();

        if (!$connection->isConnected()) {
            $this->reset();
            return false;
        }

        // Additional check: try to actually use the connection
        // This detects broken connections faster than heartbeat
        try {
            // Lightweight operation to verify connection works
            $channel = $connection->channel();
            $channel->close();
        } catch (\Exception $e) {
            // Connection exists but is broken
            $this->reset();
            return false;
        }

        $connection->checkHeartBeat();
        return true;

    } catch (\Exception $e) {
        // Catch ALL exceptions, not just heartbeat
        error_log("Connection health check failed: " . $e->getMessage());
        $this->reset();
        return false;
    }
}
```

---

### 🟡 MEDIUM-4: Exception Categorization May Miss New Error Types

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:824-863](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L824-L863) - categorizeException()

**Problem**:
```php
private function categorizeException(Exception $e): PublishErrorType
{
    $message = strtolower($e->getMessage());

    if (strpos($message, 'connection') !== false) {
        return PublishErrorType::CONNECTION_ERROR;
    }
    // ... other checks ...

    return PublishErrorType::UNKNOWN;
}
```

**What Can Break**:
1. String matching on error message is fragile
2. Error messages can change across library versions
3. Internationalized error messages won't match
4. New error types fall into UNKNOWN category
5. `strpos()` can match substrings incorrectly ("disconnection" → "connection")

**Impact**:
- **INCORRECT CATEGORIZATION**: Errors tagged with wrong type
- **METRICS POLLUTION**: UNKNOWN category grows over time
- **ALERT FAILURES**: Alerts based on specific error types miss issues

**Recommendation**:
```php
private function categorizeException(Exception $e): PublishErrorType
{
    $message = strtolower($e->getMessage());
    $exceptionClass = get_class($e);

    // Prioritize exception class over message parsing
    $classMapping = [
        'PhpAmqpLib\Exception\AMQPConnectionClosedException' => PublishErrorType::CONNECTION_ERROR,
        'PhpAmqpLib\Exception\AMQPIOException' => PublishErrorType::CONNECTION_ERROR,
        'PhpAmqpLib\Exception\AMQPChannelClosedException' => PublishErrorType::CHANNEL_ERROR,
        'PhpAmqpLib\Exception\AMQPTimeoutException' => PublishErrorType::TIMEOUT,
        'JsonException' => PublishErrorType::ENCODING_ERROR,
    ];

    if (isset($classMapping[$exceptionClass])) {
        return $classMapping[$exceptionClass];
    }

    // Fallback to message parsing with more precise patterns
    $patterns = [
        '/\b(connection|connect|socket|network)\b/' => PublishErrorType::CONNECTION_ERROR,
        '/\bchannel\b/' => PublishErrorType::CHANNEL_ERROR,
        '/\b(timeout|timed out)\b/' => PublishErrorType::TIMEOUT,
        '/\b(json|encode|serialize)\b/' => PublishErrorType::ENCODING_ERROR,
        '/\b(config|exchange|routing|permission)\b/' => PublishErrorType::CONFIG_ERROR,
    ];

    foreach ($patterns as $pattern => $type) {
        if (preg_match($pattern, $message)) {
            return $type;
        }
    }

    // Log UNKNOWN categorizations for future analysis
    error_log("UNKNOWN error type for exception {$exceptionClass}: {$message}");
    $this->statsD->increment('rmq_unknown_error_categorization', [
        'exception_class' => $exceptionClass,
    ]);

    return PublishErrorType::UNKNOWN;
}
```

---

### 🟡 MEDIUM-5: Timer Cleanup in finally May Not Execute

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:760-762](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L760-L762) - Finally Block

**Problem**:
```php
} finally {
    // Cleanup timer if not already ended
    $this->statsD->endTimer($timerKey);
}
```

**What Can Break**:
1. If `endTimer()` was already called in success path, calling again may return null
2. If `endTimer()` throws exception, it masks original exception
3. Timer may have already been cleaned up in error handler
4. No check if timer actually exists before ending

**Impact**:
- **EXCEPTION MASKING**: Finally block exceptions hide original error
- **METRIC CORRUPTION**: Double-ending timer may produce invalid metrics
- **MEMORY LEAK**: If endTimer fails, timer stays in memory

**Recommendation**:
```php
private bool $timerEnded = false;

// In success path:
if (!$this->timerEnded) {
    $duration = $this->statsD->endTimer($timerKey);
    $this->timerEnded = true;
}

// In error handler:
if (!$this->timerEnded) {
    $duration = $this->statsD->endTimer($timerKey);
    $this->timerEnded = true;
}

// In finally block:
try {
    if (!$this->timerEnded) {
        $this->statsD->endTimer($timerKey);
    }
} catch (\Exception $e) {
    // Never let finally block exception mask original exception
    error_log("Failed to end timer: " . $e->getMessage());
}
```

**OR** simplify by only ending timer once in finally block:
```php
} finally {
    try {
        $this->statsD->endTimer($timerKey); // Idempotent - safe to call multiple times
    } catch (\Exception $e) {
        error_log("Timer cleanup failed: " . $e->getMessage());
    }
}

// Remove endTimer calls from success/error paths
```

---

## Low Priority Issues (Stability Improvements)

### 🟢 LOW-1: Default Message Structure Not Validated

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:112-126](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L112-L126) - Message Structure

**Problem**:
```php
[
    'meta' => [],
    'status' => [
        'code' => 'unknown',
        'data' => [],
    ],
    'payload' => [],
    'system' => [
        'is_debug' => false,
        'consumer_error' => null,
        'created_at' => '2026-01-29 10:15:30.123',
    ]
]
```

**What Can Break**:
1. No validation that required keys exist after modifications
2. Consumers may expect specific structure but message could be malformed
3. No schema validation (JSON Schema, etc.)

**Impact**:
- **CONSUMER CRASHES**: Consumers accessing missing keys get null/undefined errors
- **DATA INCONSISTENCY**: Different message formats across events

**Recommendation**:
```php
public function validate(): void
{
    $data = $this->getData();

    $requiredKeys = ['meta', 'status', 'payload', 'system'];
    foreach ($requiredKeys as $key) {
        if (!isset($data[$key])) {
            throw new \RuntimeException("Message missing required key: {$key}");
        }
    }

    // Validate nested structure
    if (!isset($data['status']['code'])) {
        throw new \RuntimeException("Message status missing 'code'");
    }

    if (!isset($data['system']['created_at'])) {
        throw new \RuntimeException("Message system missing 'created_at'");
    }

    // Type validation
    if (!is_array($data['meta']) || !is_array($data['payload'])) {
        throw new \RuntimeException("Meta and payload must be arrays");
    }
}

// Call before publishing
public function publish(string $event): void
{
    $this->message->validate();
    // ... rest of publish logic
}
```

---

### 🟢 LOW-2: No Logging of Critical Operations

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:464-479](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L464-L479) - Insert to Outbox

**Problem**:
No logging mentioned for:
- Database connection failures
- Insert failures
- Slow queries
- Large message warnings

**Impact**:
- **DEBUGGING DIFFICULTY**: No audit trail of publishing operations
- **INCIDENT RESPONSE**: Can't trace what happened during outage

**Recommendation**:
```php
// Add structured logging
use Psr\Log\LoggerInterface;

private ?LoggerInterface $logger = null;

public function setLogger(LoggerInterface $logger): void
{
    $this->logger = $logger;
}

private function logPublish(string $event, int $messageSize, float $duration): void
{
    if ($this->logger) {
        $this->logger->info('Event published to outbox', [
            'event' => $event,
            'service' => $this->getEnv(self::MICROSERVICE_NAME),
            'message_size' => $messageSize,
            'duration_ms' => $duration * 1000,
        ]);
    }
}

private function logError(string $operation, \Exception $e): void
{
    if ($this->logger) {
        $this->logger->error("Publish operation failed: {$operation}", [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
```

---

### 🟢 LOW-3: Message ID Collision Possible

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:153](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L153) - UUID Generation

**Problem**:
```php
'message_id' => Uuid::uuid4(),  // Unique message identifier
```

**What Can Break**:
1. No verification what UUID library is used (ramsey/uuid vs other)
2. UUID v4 has collision probability (very low but non-zero)
3. No check for duplicate message_id in outbox table
4. If clock skews, timestamp-based UUIDs (v1) could collide

**Impact**:
- **MESSAGE DEDUPLICATION BREAKS**: If using message_id for dedup, collisions cause data loss
- **DEBUGGING**: Duplicate IDs make tracing impossible

**Recommendation**:
```php
// Use UUID v7 (time-ordered) for better properties
// Requires ramsey/uuid ^4.7
use Ramsey\Uuid\Uuid;

'message_id' => Uuid::uuid7()->toString(),

// OR add sequence number for guaranteed uniqueness
private static int $sequenceNumber = 0;

'message_id' => Uuid::uuid7()->toString() . '-' . (self::$sequenceNumber++),
```

---

### 🟢 LOW-4: No Circuit Breaker for Database Outages

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:1305-1353](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L1305-L1353) - Outage Circuit Breaker

**Problem**:
RabbitMQ has circuit breaker (`ensureConnectionOrSleep`) but PostgreSQL publish doesn't

**Impact**:
- **ERROR SPAM**: Every publish attempts DB connection during outage
- **RESOURCE EXHAUSTION**: Connection attempts pile up
- **NO BACKOFF**: Hammers database during recovery

**Recommendation**:
```php
// Add similar circuit breaker for database
private bool $dbOutageMode = false;
private ?float $lastDbFailureTime = null;
private const DB_BACKOFF_SECONDS = 30;

private function getPDOConnection(): \PDO
{
    // Check if we're in backoff period
    if ($this->dbOutageMode) {
        $timeSinceFailure = microtime(true) - $this->lastDbFailureTime;
        if ($timeSinceFailure < self::DB_BACKOFF_SECONDS) {
            throw new \RuntimeException(
                "Database in outage mode, retry in " .
                (self::DB_BACKOFF_SECONDS - $timeSinceFailure) . " seconds"
            );
        }
        // Backoff period expired, try again
        $this->dbOutageMode = false;
    }

    try {
        // ... connection logic ...
        return self::$sharedPDOConnection;
    } catch (\PDOException $e) {
        // Enter outage mode
        $this->dbOutageMode = true;
        $this->lastDbFailureTime = microtime(true);
        throw $e;
    }
}
```

---

### 🟢 LOW-5: Delayed Messages Not Supported in Outbox Pattern

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:332](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L332) - Delay Note

**Problem**:
> ⚠️ **Note**: Only works with direct RabbitMQ publishing (`publishToRabbit()`), not outbox pattern.

**What Can Break**:
1. Users call `delay()` but publish to outbox → delay silently ignored
2. No warning or error when delay set but not used
3. Creates expectation mismatch

**Impact**:
- **BEHAVIOR CONFUSION**: Delayed messages arrive immediately
- **LOGIC ERRORS**: Code relying on delay doesn't work as expected

**Recommendation**:
```php
// Option 1: Add delay support to outbox pattern
// Add 'scheduled_at' column to outbox table
$stmt = $pdo->prepare("
    INSERT INTO {$_ENV['DB_BOX_SCHEMA']}.outbox (
        producer_service, event_type, message_body, partition_key, scheduled_at
    ) VALUES (?, ?, ?::jsonb, ?, ?)
");

$scheduledAt = $this->delay
    ? (new \DateTime())->modify('+' . $this->delay . ' milliseconds')
    : null;

$stmt->execute([
    $_ENV['AMQP_MICROSERVICE_NAME'],
    $event,
    $messageBody,
    null,
    $scheduledAt ? $scheduledAt->format('Y-m-d H:i:s') : null,
]);

// pg2event dispatcher skips events where scheduled_at > NOW()

// Option 2: Throw error if delay set with outbox
public function publish(string $event): void
{
    if ($this->delay > 0) {
        throw new \RuntimeException(
            "Delayed messages not supported with outbox pattern. " .
            "Use publishToRabbit() for delayed messages or implement scheduled_at in outbox."
        );
    }
    // ... rest of publish
}
```

---

## Performance Optimizations (Stability Related)

### ⚡ PERF-1: Repeated JSON Encode/Decode in Message Mutations

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:206-217](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L206-L217) - Data Management Pattern

**Problem**:
Every mutation:
1. Decodes entire JSON body
2. Modifies one field
3. Encodes entire JSON body
4. Sets body

For 10 fields, this is 10 encode/decode cycles.

**Impact**:
- **CPU WASTE**: Unnecessary serialization overhead
- **LATENCY**: Each encode/decode adds ~0.1-1ms
- **MEMORY**: Multiple copies of message data

**Recommendation**:
```php
// Cache decoded body, encode only once at end
private ?array $cachedBody = null;
private bool $bodyModified = false;

private function getData(): array
{
    if ($this->cachedBody === null) {
        $this->cachedBody = json_decode($this->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
    return $this->cachedBody;
}

private function setData(string $key, $value): void
{
    $body = $this->getData();
    $body[$key] = $value;
    $this->cachedBody = $body;
    $this->bodyModified = true;
}

// Flush before publishing
public function prepareForPublish(): void
{
    if ($this->bodyModified) {
        $this->setBody(json_encode($this->cachedBody, JSON_THROW_ON_ERROR));
        $this->bodyModified = false;
    }
}

// Call in publish()
$this->message->prepareForPublish();
```

---

### ⚡ PERF-2: Environment Variable Lookups Not Cached

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:261-271](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L261-L271) - getEnv() Priority

**Problem**:
```php
protected function getEnv(string $param): ?string
{
    $configParam = strtolower(substr($param, strlen($this->prefix)));

    return $this->config[$configParam]
        ?? getenv($param, true)
        ?: getenv($param)
        ?: $_ENV[$param]
        ?? null;
}
```

Called repeatedly for same variables (MICROSERVICE_NAME, DB_BOX_HOST, etc.)

**Impact**:
- **CPU WASTE**: Multiple getenv() syscalls for same variable
- **LATENCY**: Each getenv() is slow (system call)

**Recommendation**:
```php
private array $envCache = [];

protected function getEnv(string $param): ?string
{
    if (isset($this->envCache[$param])) {
        return $this->envCache[$param];
    }

    $configParam = strtolower(substr($param, strlen($this->prefix)));

    $value = $this->config[$configParam]
        ?? getenv($param, true)
        ?: getenv($param)
        ?: $_ENV[$param]
        ?? null;

    $this->envCache[$param] = $value;
    return $value;
}

// Add method to clear cache if env changes (rare)
public function clearEnvCache(): void
{
    $this->envCache = [];
}
```

---

### ⚡ PERF-3: Metrics Timer Uses uniqid() Instead of Counter

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:643-644](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L643-L644) - Timer Key Generation

**Problem**:
```php
$timerKey = 'publish_' . $event . '_' . uniqid();
```

`uniqid()` generates random string - slow and unnecessary for timer key uniqueness

**Impact**:
- **CPU WASTE**: String concatenation + uniqid() overhead
- **MEMORY**: Long timer key strings

**Recommendation**:
```php
// Use incrementing counter instead
private static int $timerCounter = 0;

$timerKey = 'publish_' . (self::$timerCounter++);

// OR use object hash if need instance-specific:
$timerKey = 'publish_' . spl_object_id($this);
```

---

## Edge Cases & Race Conditions

### 🔶 EDGE-1: Concurrent Access to Static Shared Connection

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:1134-1140](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L1134-L1140) - Static Connection Pool

**Problem**:
```php
protected static ?AMQPStreamConnection $sharedConnection = null;
protected static $sharedChannel = null;
```

In async PHP environments (Swoole, ReactPHP, parallel), static variables are shared across fibers/coroutines

**Impact**:
- **RACE CONDITION**: Two fibers modify static connection simultaneously
- **CHANNEL CONFLICT**: Same channel used for different operations in parallel

**Recommendation**:
```php
// For async environments, use coroutine-local storage
// Swoole example:
use Swoole\Coroutine;

protected function getConnection(): AMQPStreamConnection
{
    if (Coroutine::getCid() > 0) {
        // In coroutine - use coroutine context
        $ctx = Coroutine::getContext();
        if (isset($ctx['amqp_connection']) && $ctx['amqp_connection']->isConnected()) {
            return $ctx['amqp_connection'];
        }

        $ctx['amqp_connection'] = $this->createConnection();
        return $ctx['amqp_connection'];
    }

    // Not in coroutine - use static (traditional PHP)
    // ... existing static connection logic
}
```

---

### 🔶 EDGE-2: Message Body Encoding Changes Between Writes

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:133-146](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L133-L146) - Constructor Flow

**Problem**:
Constructor accepts both `array` and `string`:
```php
public function __construct(array|string $data = [], ...)
{
    $body = is_array($data)
        ? json_encode(array_merge($this->dataStructure(), $data))
        : $data;
```

If passed a string, later modifications via `addPayload()` will decode/encode, potentially changing encoding

**Impact**:
- **ENCODING DRIFT**: JSON formatting changes (spacing, key order)
- **HASH MISMATCH**: If hashing message body for dedup, hashes won't match

**Recommendation**:
```php
// Normalize all inputs to array immediately
public function __construct(array|string $data = [], array $properties = [], array $config = [])
{
    // Normalize string input to array
    if (is_string($data)) {
        $data = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }

    // Merge with structure
    $bodyData = array_merge($this->dataStructure(), $data);

    // Encode consistently
    $body = json_encode($bodyData, JSON_THROW_ON_ERROR);

    // ... rest of constructor
}
```

---

### 🔶 EDGE-3: Heartbeat Check During Active Operation

**Reference**: [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md:1290-1292](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#L1290-L1292) - checkHeartBeat

**Problem**:
```php
$connection->checkHeartBeat();
```

`checkHeartBeat()` may send heartbeat frame. If called during active publish, could interfere.

**Impact**:
- **PROTOCOL ERROR**: Heartbeat frame inserted mid-operation
- **CONNECTION CORRUPTION**: RabbitMQ sees unexpected frame sequence

**Recommendation**:
```php
// Only check heartbeat when connection is idle, not during operations
public function isConnectionHealthy(): bool
{
    try {
        $connection = $this->getConnection();

        if (!$connection->isConnected()) {
            $this->reset();
            return false;
        }

        // Check heartbeat only if we're not in the middle of an operation
        // php-amqplib handles heartbeat internally during wait_for_basic_ack, etc.
        // Manual check only needed for long idle periods

        return true;

    } catch (\Exception $e) {
        $this->reset();
        return false;
    }
}

// OR let library handle heartbeats automatically (it does this already)
// Remove manual checkHeartBeat() call
```

---

## Summary by Priority

### 🔴 Critical (Fix Immediately)
1. **CRITICAL-1**: JSON decode without error handling → Fatal crashes
2. **CRITICAL-2**: PostgreSQL connection leak → Database exhaustion
3. **CRITICAL-3**: Environment variable validation inconsistency → Runtime failures
4. **CRITICAL-4**: Race condition in channel pooling → Channel leaks
5. **CRITICAL-5**: No channel health check before publish → Publish failures

### 🟠 High Priority (Fix Soon)
1. **HIGH-1**: No timeout on PDO query → Hanging workers
2. **HIGH-2**: Message body injection in setEvent() → Routing failures
3. **HIGH-3**: SQL injection risk in schema name → Security vulnerability
4. **HIGH-4**: No message size validation → RabbitMQ rejections
5. **HIGH-5**: Metrics sampling hides errors → Inaccurate monitoring

### 🟡 Medium Priority (Plan to Fix)
1. **MEDIUM-1**: Timestamp milliseconds inconsistency → Debugging issues
2. **MEDIUM-2**: No partition key strategy → Ordering problems
3. **MEDIUM-3**: Heartbeat doesn't detect all failures → Delayed error detection
4. **MEDIUM-4**: Exception categorization fragile → Metric pollution
5. **MEDIUM-5**: Timer cleanup may fail → Exception masking

### 🟢 Low Priority (Nice to Have)
1. **LOW-1**: Message structure validation → Consumer crashes
2. **LOW-2**: No logging of operations → Debugging difficulty
3. **LOW-3**: Message ID collision possible → Deduplication breaks
4. **LOW-4**: No circuit breaker for DB → Error spam
5. **LOW-5**: Delayed messages not supported in outbox → Confusion

### ⚡ Performance (Stability Related)
1. **PERF-1**: Repeated JSON encode/decode → CPU waste
2. **PERF-2**: Environment variable lookups not cached → Latency
3. **PERF-3**: Metrics timer uses uniqid() → Overhead

### 🔶 Edge Cases
1. **EDGE-1**: Concurrent static connection access → Async conflicts
2. **EDGE-2**: Message encoding drift → Hash mismatches
3. **EDGE-3**: Heartbeat during active operation → Protocol errors

---

## Testing Recommendations

Before deploying fixes, test these scenarios:

### Crash Testing
- [ ] Malformed JSON in message body
- [ ] Very large messages (>1MB, >10MB)
- [ ] Missing environment variables
- [ ] Invalid UTF-8 in payload
- [ ] Database connection timeout
- [ ] RabbitMQ connection drop mid-publish

### Load Testing
- [ ] 1000+ publishes in single worker (check connection pooling)
- [ ] Concurrent publishes from multiple instances
- [ ] Publishing during database outage (circuit breaker)
- [ ] Publishing during RabbitMQ outage (circuit breaker)

### Edge Case Testing
- [ ] Empty message body
- [ ] Circular reference in payload
- [ ] Special characters in event name
- [ ] Timezone changes during publish
- [ ] Clock skew between servers

---

**Next Steps**:
1. Review recommendations with team
2. Prioritize fixes based on service criticality
3. Implement high-priority fixes first
4. Add integration tests for edge cases
5. Monitor metrics after deployment

**Remember**:
- All changes must be backwards compatible (see [CLAUDE.md](../CLAUDE.md))
- No breaking changes to public API
- Add feature flags for risky changes
- Test with existing consumer code before deploying
