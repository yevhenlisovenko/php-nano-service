# Refactoring Recommendations: Publishing Logic

> **Audience**: Developers planning code quality improvements for nano-service publishing
> **Purpose**: Document opportunities to improve code readability, maintainability, and reduce duplication
> **Scope**: Publishing architecture only (based on [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md))

**Last Updated**: 2026-01-30

---

## Important Notes

⚠️ **These are FUTURE recommendations** - no immediate action required
⚠️ **All changes must maintain backwards compatibility** (see [CLAUDE.md](../CLAUDE.md))
⚠️ **Test thoroughly before implementing any changes**

---

## Table of Contents

1. [Message Construction Refactoring](#1-message-construction-refactoring)
2. [Publisher Method Duplication](#2-publisher-method-duplication)
3. [Validation & Configuration](#3-validation--configuration)
4. [Metrics Collection Separation](#4-metrics-collection-separation)
5. [Error Handling Improvements](#5-error-handling-improvements)
6. [Connection Management](#6-connection-management)
7. [Code Organization & Structure](#7-code-organization--structure)
8. [Additional Minor Improvements](#8-additional-minor-improvements)

---

# 1. Message Construction Refactoring

## Reference
[ARCHITECTURE_PUBLISHING_DEEP_DIVE.md - Section 2: Message Construction Deep Dive](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#2-message-construction-deep-dive)

Specifically:
- [Section 2.1.4: Message Data Management Pattern](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#214-message-data-management-pattern)
- Methods: `addPayload()`, `addMeta()`, `setDebug()`, etc.

## Problem: Repetitive JSON Read-Modify-Write Pattern

**Current pattern repeated in multiple methods**:
```php
// Read body
$bodyData = json_decode($this->getBody(), true);

// Modify data
$bodyData['section']['key'] = $value;

// Write back
$this->setBody(json_encode($bodyData));
```

This pattern appears in:
- `addPayload()` - modifies `payload` section
- `addMeta()` - modifies `meta` section
- `setDebug()` - modifies `system.is_debug`
- Multiple other data manipulation methods

## Recommendation 1.1: Extract JSON Mutation Helper

**Create a single method for all JSON mutations**:

```php
/**
 * Safely modify a section of the message body JSON
 */
protected function modifyBodySection(string $section, callable $modifier): void
{
    $bodyData = json_decode($this->getBody(), true);
    $bodyData[$section] = $modifier($bodyData[$section] ?? []);
    $this->setBody(json_encode($bodyData));
}
```

**Simplifies all methods**:
```php
// Before
public function addPayload(array $data): self
{
    $bodyData = json_decode($this->getBody(), true);
    $bodyData['payload'] = array_merge($bodyData['payload'] ?? [], $data);
    $this->setBody(json_encode($bodyData));
    return $this;
}

// After
public function addPayload(array $data): self
{
    $this->modifyBodySection('payload', fn($current) => array_merge($current, $data));
    return $this;
}
```

**Benefits**:
- ✅ Single source of truth for JSON manipulation
- ✅ Easier to add error handling for JSON decode/encode
- ✅ Reduces code duplication across 5+ methods
- ✅ More readable and maintainable

**Priority**: 🔴 High

---

## Recommendation 1.2: Extract Nested Path Updates

**Problem**: Methods like `setDataAttribute()` access nested paths manually

**Create path-based updater**:
```php
/**
 * Update nested value using dot notation
 */
protected function updatePath(string $path, $value): void
{
    $bodyData = json_decode($this->getBody(), true);

    $keys = explode('.', $path);
    $current = &$bodyData;

    foreach ($keys as $key) {
        if (!isset($current[$key])) {
            $current[$key] = [];
        }
        $current = &$current[$key];
    }

    $current = $value;
    $this->setBody(json_encode($bodyData));
}

// Usage
$message->updatePath('system.is_debug', true);
$message->updatePath('meta.tenant', 'client-a');
```

**Benefits**:
- ✅ Simplifies nested updates
- ✅ More expressive API
- ✅ Reduces manual array navigation

**Priority**: 🟡 Medium

---

# 2. Publisher Method Duplication

## Reference
[ARCHITECTURE_PUBLISHING_DEEP_DIVE.md - Sections 4 & 5](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#4-publishing-flow-postgresql-outbox-pattern)

Specifically:
- [Section 4.2: publish() Method](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#42-publish-method---default-outbox-publishing)
- [Section 5.2: publishToRabbit() Method](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#52-step-by-step-flow)

## Problem: Message Preparation Code Duplicated

**Both `publish()` and `publishToRabbit()` have identical code**:

```php
// Step 2 in BOTH methods
$this->message->setEvent($event);
$this->message->set('app_id', $this->getNamespace($this->getEnv(self::MICROSERVICE_NAME)));

if ($this->meta) {
    $this->message->addMeta($this->meta);
}
```

From:
- [publish() - Step 2](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#step-2-prepare-message)
- [publishToRabbit() - Step 2](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#step-2-prepare-message-1)

## Recommendation 2.1: Extract Message Preparation

```php
/**
 * Prepare message for publishing (sets event, app_id, meta)
 */
protected function prepareMessageForPublish(string $event): void
{
    $this->message->setEvent($event);
    $this->message->set('app_id', $this->getNamespace($this->getEnv(self::MICROSERVICE_NAME)));

    if ($this->meta) {
        $this->message->addMeta($this->meta);
    }
}

// Usage in both methods
public function publish(string $event): void
{
    // ... validation ...

    $this->prepareMessageForPublish($event);

    // ... rest of logic ...
}

public function publishToRabbit(string $event): void
{
    // ... checks ...

    $this->prepareMessageForPublish($event);

    // ... rest of logic ...
}
```

**Benefits**:
- ✅ Single source of truth for message preparation
- ✅ Easier to add new preparation steps
- ✅ Reduces duplication

**Priority**: 🔴 High

---

## Recommendation 2.2: Extract Delay Header Logic

**Problem**: Delay header logic is only in `publishToRabbit()` but scattered

From [publishToRabbit() - Step 2](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#step-2-prepare-message-1):

```php
// Before
if ($this->delay) {
    $this->message->set('application_headers', new AMQPTable(['x-delay' => $this->delay]));
}

// After - extract to method
protected function applyDelayIfSet(): void
{
    if ($this->delay) {
        $this->message->set('application_headers', new AMQPTable(['x-delay' => $this->delay]));
    }
}
```

**Benefits**:
- ✅ Named method explains intent
- ✅ Easier to test separately
- ✅ Could be moved to NanoServiceMessage if needed

**Priority**: 🟢 Low

---

# 3. Validation & Configuration

## Reference
[ARCHITECTURE_PUBLISHING_DEEP_DIVE.md - Section 4.2](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#42-publish-method---default-outbox-publishing)

Specifically:
- [Step 1: Validate Required Environment Variables](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#step-1-validate-required-environment-variables)

## Problem: Manual Environment Variable Validation

**Current code has repetitive validation**:

```php
$requiredVars = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_SCHEMA'];
foreach ($requiredVars as $var) {
    if (!isset($_ENV[$var])) {
        throw new \RuntimeException("Missing required environment variable: {$var}");
    }
}

if (!isset($_ENV['AMQP_MICROSERVICE_NAME'])) {
    throw new \RuntimeException("Missing required environment variable: AMQP_MICROSERVICE_NAME");
}
```

## Recommendation 3.1: Create Validation Helper

```php
/**
 * Validate required environment variables exist
 *
 * @param array $variables Variable names to check
 * @throws \RuntimeException if any variable is missing
 */
protected function validateRequiredEnvVars(array $variables): void
{
    $missing = [];

    foreach ($variables as $var) {
        if (!isset($_ENV[$var])) {
            $missing[] = $var;
        }
    }

    if (!empty($missing)) {
        throw new \RuntimeException(
            "Missing required environment variables: " . implode(', ', $missing)
        );
    }
}

// Usage
public function publish(string $event): void
{
    $this->validateRequiredEnvVars([
        'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_SCHEMA',
        'AMQP_MICROSERVICE_NAME'
    ]);

    // ... rest of logic ...
}
```

**Benefits**:
- ✅ Single validation call
- ✅ Better error message (shows all missing vars at once)
- ✅ Reusable across different methods
- ✅ Easier to test

**Priority**: 🔴 High

---

## Recommendation 3.2: Extract Database Connection Builder

**Problem**: PDO connection logic embedded in `publish()` method

From [Step 4: Connect to PostgreSQL](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#step-4-connect-to-postgresql):

```php
// Before - in publish() method
$dsn = sprintf(
    "pgsql:host=%s;port=%s;dbname=%s",
    $_ENV['DB_HOST'],
    $_ENV['DB_PORT'],
    $_ENV['DB_NAME']
);

$pdo = new \PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], [
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
]);

// After - extract to method
protected function getOutboxConnection(): \PDO
{
    static $connection = null;

    if ($connection === null) {
        $dsn = sprintf(
            "pgsql:host=%s;port=%s;dbname=%s",
            $_ENV['DB_HOST'],
            $_ENV['DB_PORT'],
            $_ENV['DB_NAME']
        );

        $connection = new \PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    return $connection;
}

// Usage
public function publish(string $event): void
{
    // ...
    $pdo = $this->getOutboxConnection();
    // ...
}
```

**Benefits**:
- ✅ Separates concerns (connection vs publishing)
- ✅ Connection can be reused (static caching)
- ✅ Easier to mock in tests
- ✅ Could be moved to separate class later

**Priority**: 🟡 Medium

---

# 4. Metrics Collection Separation

## Reference
[ARCHITECTURE_PUBLISHING_DEEP_DIVE.md - Section 5.2](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#52-step-by-step-flow)

Specifically:
- [Step 4: Start Timing & Track Attempt](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#step-4-start-timing--track-attempt)
- [Step 5: Measure Payload Size](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#step-5-measure-payload-size)
- [Step 7: Record Success Metrics](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#step-7-record-success-metrics)

## Problem: Metrics Logic Scattered Throughout Publishing

**Current `publishToRabbit()` has metrics code interleaved with business logic**:

```php
// Metrics
$timerKey = 'publish_' . $event . '_' . uniqid();
$this->statsD->startTimer($timerKey);
$this->statsD->increment('rmq_publish_total', $tags, $sampleRate);

// Business logic
$payloadSize = strlen($this->message->getBody());

// More metrics
$this->statsD->histogram('rmq_payload_bytes', $payloadSize, $tags, ...);

// More business logic
$this->getChannel()->basic_publish($this->message, $exchange, $event);

// More metrics
$duration = $this->statsD->endTimer($timerKey);
$this->statsD->timing('rmq_publish_duration_ms', $duration, $tags, ...);
```

This makes the method harder to read and understand the core publishing flow.

## Recommendation 4.1: Extract Metrics Collection to Wrapper

**Create a metrics decorator/wrapper**:

```php
/**
 * Wrapper to collect metrics around publishing operation
 */
protected function publishWithMetrics(string $event, array $tags, callable $publishOperation): void
{
    $timerKey = 'publish_' . $event . '_' . uniqid();
    $sampleRate = $this->statsD->getSampleRate('ok_events');

    // Pre-publish metrics
    $this->statsD->startTimer($timerKey);
    $this->statsD->increment('rmq_publish_total', $tags, $sampleRate);

    $payloadSize = strlen($this->message->getBody());
    $this->statsD->histogram(
        'rmq_payload_bytes',
        $payloadSize,
        $tags,
        $this->statsD->getSampleRate('payload')
    );

    try {
        // Execute actual publish
        $publishOperation();

        // Success metrics
        $duration = $this->statsD->endTimer($timerKey);
        if ($duration !== null) {
            $this->statsD->timing('rmq_publish_duration_ms', $duration, $tags,
                $this->statsD->getSampleRate('latency'));
        }
        $this->statsD->increment('rmq_publish_success_total', $tags, $sampleRate);

    } catch (\Exception $e) {
        // Error metrics (existing handlePublishError logic)
        $errorType = $this->categorizeException($e);
        $this->handlePublishError($e, $tags, $errorType, $timerKey);
        throw $e;
    }
}

// Usage - clean business logic
public function publishToRabbit(string $event): void
{
    // ... preparation ...

    $tags = $this->buildMetricsTags($event);
    $exchange = $this->getNamespace($this->exchange);

    $this->publishWithMetrics($event, $tags, function() use ($exchange, $event) {
        $this->getChannel()->basic_publish($this->message, $exchange, $event);
    });
}
```

**Benefits**:
- ✅ Separates metrics from business logic
- ✅ Core publish logic is 1 line: `basic_publish()`
- ✅ Metrics collection is centralized and reusable
- ✅ Easier to modify metrics without touching publish logic
- ✅ Easier to test both independently

**Priority**: 🔴 High

---

## Recommendation 4.2: Extract Tags Building

**Problem**: Tags array construction scattered

From [Step 3: Prepare Metrics Tags](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#step-3-prepare-metrics-tags):

```php
// Before - inline in publishToRabbit()
$tags = [
    'service' => $this->getEnv(self::MICROSERVICE_NAME),
    'event' => $event,
    'env' => $this->getEnvironment(),
];

// After - extract to method
protected function buildMetricsTags(string $event): array
{
    return [
        'service' => $this->getEnv(self::MICROSERVICE_NAME),
        'event' => $event,
        'env' => $this->getEnvironment(),
    ];
}
```

**Benefits**:
- ✅ Consistent tags across all metrics
- ✅ Single place to add/modify tags
- ✅ Named method explains purpose

**Priority**: 🟡 Medium

---

# 5. Error Handling Improvements

## Reference
[ARCHITECTURE_PUBLISHING_DEEP_DIVE.md - Sections 5.3 & 8](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#53-error-handling)

Specifically:
- [Section 5.3: Error Handling](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#53-error-handling)
- [categorizeException() Method](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#categorizeexception-method)

## Problem 5.1: Long Exception Catch Chain

**Current code has many catch blocks**:

```php
try {
    // publish
} catch (AMQPChannelClosedException $e) {
    $this->handlePublishError($e, $tags, PublishErrorType::CHANNEL_ERROR, $timerKey);
    throw $e;
} catch (AMQPConnectionClosedException | AMQPIOException $e) {
    $this->handlePublishError($e, $tags, PublishErrorType::CONNECTION_ERROR, $timerKey);
    throw $e;
} catch (AMQPTimeoutException $e) {
    $this->handlePublishError($e, $tags, PublishErrorType::TIMEOUT, $timerKey);
    throw $e;
} catch (\JsonException $e) {
    $this->handlePublishError($e, $tags, PublishErrorType::ENCODING_ERROR, $timerKey);
    throw $e;
} catch (Exception $e) {
    $errorType = $this->categorizeException($e);
    $this->handlePublishError($e, $tags, $errorType, $timerKey);
    throw $e;
}
```

## Recommendation 5.1: Use Exception-to-Type Mapping

```php
/**
 * Map exception class to error type
 */
protected function getErrorTypeForException(\Exception $e): PublishErrorType
{
    return match (true) {
        $e instanceof AMQPChannelClosedException => PublishErrorType::CHANNEL_ERROR,
        $e instanceof AMQPConnectionClosedException => PublishErrorType::CONNECTION_ERROR,
        $e instanceof AMQPIOException => PublishErrorType::CONNECTION_ERROR,
        $e instanceof AMQPTimeoutException => PublishErrorType::TIMEOUT,
        $e instanceof \JsonException => PublishErrorType::ENCODING_ERROR,
        default => $this->categorizeException($e)
    };
}

// Simplified catch
try {
    // publish
} catch (\Exception $e) {
    $errorType = $this->getErrorTypeForException($e);
    $this->handlePublishError($e, $tags, $errorType, $timerKey);
    throw $e;
}
```

**Benefits**:
- ✅ Reduces code from 15+ lines to 5 lines
- ✅ Uses modern PHP 8+ match expression
- ✅ Easier to add new exception types
- ✅ Same functionality, cleaner code

**Priority**: 🔴 High

---

## Problem 5.2: String Matching for Error Categorization

**Current categorizeException() uses fragile string matching**:

```php
private function categorizeException(Exception $e): PublishErrorType
{
    $message = strtolower($e->getMessage());

    if (strpos($message, 'connection') !== false ||
        strpos($message, 'socket') !== false ||
        strpos($message, 'network') !== false) {
        return PublishErrorType::CONNECTION_ERROR;
    }
    // ... more string matching ...
}
```

## Recommendation 5.2: Use Strategy Pattern for Categorization

```php
/**
 * Error categorization strategies
 */
class ErrorCategorizer
{
    private array $strategies = [];

    public function __construct()
    {
        $this->strategies = [
            new ConnectionErrorStrategy(),
            new ChannelErrorStrategy(),
            new TimeoutErrorStrategy(),
            new EncodingErrorStrategy(),
            new ConfigErrorStrategy(),
        ];
    }

    public function categorize(\Exception $e): PublishErrorType
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->matches($e)) {
                return $strategy->getErrorType();
            }
        }

        return PublishErrorType::UNKNOWN;
    }
}

interface ErrorCategorizationStrategy
{
    public function matches(\Exception $e): bool;
    public function getErrorType(): PublishErrorType;
}

class ConnectionErrorStrategy implements ErrorCategorizationStrategy
{
    public function matches(\Exception $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'connection') ||
               str_contains($message, 'socket') ||
               str_contains($message, 'network');
    }

    public function getErrorType(): PublishErrorType
    {
        return PublishErrorType::CONNECTION_ERROR;
    }
}
```

**Benefits**:
- ✅ Each strategy is independently testable
- ✅ Easy to add new categorization rules
- ✅ Uses modern PHP `str_contains()` instead of `strpos()`
- ✅ More maintainable and extensible
- ✅ Could be configured via dependency injection

**Trade-off**: More classes, but better separation of concerns

**Priority**: 🟢 Low (works fine now, optional improvement)

---

# 6. Connection Management

## Reference
[ARCHITECTURE_PUBLISHING_DEEP_DIVE.md - Section 7](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#7-connection--channel-pooling)

Specifically:
- [Section 7.2: getConnection() with Pooling](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#getconnection-with-pooling)
- [Section 7.2: getChannel() with Pooling](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#getchannel-with-pooling)

## Problem: Duplication Between getConnection() and getChannel()

**Both methods follow identical pattern**:

```php
// getConnection()
if (self::$sharedConnection && self::$sharedConnection->isConnected()) {
    return self::$sharedConnection;
}
if (!$this->connection) {
    $this->connection = /* create connection */;
    self::$sharedConnection = $this->connection;
    /* track metrics */
}
return $this->connection;

// getChannel()
if (self::$sharedChannel && self::$sharedChannel->is_open()) {
    return self::$sharedChannel;
}
if (!$this->channel || !$this->channel->is_open()) {
    $this->channel = /* create channel */;
    self::$sharedChannel = $this->channel;
    /* track metrics */
}
return $this->channel;
```

## Recommendation 6.1: Extract Pooling Pattern to Generic Method

```php
/**
 * Generic resource pooling pattern
 *
 * @param mixed $sharedResource Static shared resource reference
 * @param mixed $instanceResource Instance resource reference
 * @param callable $healthCheck Function to check if resource is healthy
 * @param callable $creator Function to create new resource
 * @param string $metricName Metric name for tracking
 * @return mixed The pooled resource
 */
protected function getOrCreatePooledResource(
    &$sharedResource,
    &$instanceResource,
    callable $healthCheck,
    callable $creator,
    string $metricName
) {
    // Try shared resource first
    if ($sharedResource && $healthCheck($sharedResource)) {
        return $sharedResource;
    }

    // Create if needed
    if (!$instanceResource || !$healthCheck($instanceResource)) {
        $instanceResource = $creator();
        $sharedResource = $instanceResource;

        // Track metrics
        $this->statsD->increment("{$metricName}_total", [
            'service' => $this->getEnv(self::MICROSERVICE_NAME),
            'status' => 'success'
        ]);
        $this->statsD->gauge("{$metricName}_active", 1, [
            'service' => $this->getEnv(self::MICROSERVICE_NAME)
        ]);
    }

    return $instanceResource;
}

// Usage
public function getConnection(): AMQPStreamConnection
{
    return $this->getOrCreatePooledResource(
        self::$sharedConnection,
        $this->connection,
        fn($conn) => $conn->isConnected(),
        fn() => $this->createConnection(),
        'rmq_connection'
    );
}

public function getChannel()
{
    return $this->getOrCreatePooledResource(
        self::$sharedChannel,
        $this->channel,
        fn($ch) => $ch->is_open(),
        fn() => $this->getConnection()->channel(),
        'rmq_channel'
    );
}

protected function createConnection(): AMQPStreamConnection
{
    return new AMQPStreamConnection(
        $this->getEnv(self::HOST),
        $this->getEnv(self::PORT),
        // ... all parameters ...
    );
}
```

**Benefits**:
- ✅ DRY - pooling pattern defined once
- ✅ Reduces both methods from 20+ lines to 5 lines each
- ✅ Could be reused for other resources
- ✅ Easier to modify pooling behavior

**Trade-off**: More abstract, but pattern is very clear

**Priority**: 🟡 Medium

---

# 7. Code Organization & Structure

## Reference
[ARCHITECTURE_PUBLISHING_DEEP_DIVE.md - Multiple sections](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md#architecture-deep-dive-event-publishing-in-nano-service)

## Problem 7.1: Large NanoPublisher Class

**Current NanoPublisher has multiple responsibilities**:
- Message preparation
- Outbox publishing (PostgreSQL)
- Direct RabbitMQ publishing
- Metrics collection
- Error handling
- Connection management (via inheritance)

This violates Single Responsibility Principle.

## Recommendation 7.1: Consider Strategy Pattern for Publishing

```php
interface PublishingStrategy
{
    public function publish(NanoServiceMessage $message, string $event): void;
}

class OutboxPublishingStrategy implements PublishingStrategy
{
    // All PostgreSQL outbox logic
    public function publish(NanoServiceMessage $message, string $event): void
    {
        // Current publish() logic
    }
}

class DirectRabbitMQPublishingStrategy implements PublishingStrategy
{
    // All RabbitMQ direct logic
    public function publish(NanoServiceMessage $message, string $event): void
    {
        // Current publishToRabbit() logic
    }
}

class NanoPublisher
{
    private PublishingStrategy $strategy;

    public function setStrategy(PublishingStrategy $strategy): void
    {
        $this->strategy = $strategy;
    }

    public function publish(string $event): void
    {
        $this->prepareMessageForPublish($event);
        $this->strategy->publish($this->message, $event);
    }
}
```

**Benefits**:
- ✅ Clear separation between outbox and direct publishing
- ✅ Each strategy is independently testable
- ✅ Easier to add new publishing strategies (e.g., Kafka, SNS)
- ✅ Follows Open/Closed Principle

**Trade-off**: More classes, requires refactoring existing code

**Alternative**: Keep current structure but extract helpers (recommendations 2.1, 3.1, 4.1)

**Priority**: 🟢 Low (works fine now, future enhancement)

---

## Problem 7.2: Static Methods and Testing

**Current code has static properties for pooling**:
```php
protected static ?AMQPStreamConnection $sharedConnection = null;
protected static $sharedChannel = null;
```

This makes unit testing harder (state persists between tests).

## Recommendation 7.2: Consider Dependency Injection for Testing

**Create ConnectionPool class**:

```php
class ConnectionPool
{
    private ?AMQPStreamConnection $connection = null;
    private $channel = null;

    public function getConnection(callable $creator): AMQPStreamConnection
    {
        if ($this->connection && $this->connection->isConnected()) {
            return $this->connection;
        }

        $this->connection = $creator();
        return $this->connection;
    }

    public function getChannel(callable $creator)
    {
        if ($this->channel && $this->channel->is_open()) {
            return $this->channel;
        }

        $this->channel = $creator();
        return $this->channel;
    }

    public function reset(): void
    {
        $this->connection = null;
        $this->channel = null;
    }
}

class NanoServiceClass
{
    private static ?ConnectionPool $pool = null;

    protected function getConnectionPool(): ConnectionPool
    {
        if (self::$pool === null) {
            self::$pool = new ConnectionPool();
        }
        return self::$pool;
    }

    public function getConnection(): AMQPStreamConnection
    {
        return $this->getConnectionPool()->getConnection(
            fn() => $this->createConnection()
        );
    }
}
```

**Benefits**:
- ✅ Easier to reset state in tests
- ✅ Encapsulates pooling logic
- ✅ Could inject mock pool for testing
- ✅ Could have separate pools per connection config

**Priority**: 🟡 Medium

---

## Recommendation 7.3: Extract Database Operations

**Create OutboxRepository**:

```php
class OutboxRepository
{
    private \PDO $pdo;
    private string $schema;

    public function __construct(array $config)
    {
        $this->schema = $config['schema'];
        $this->pdo = $this->createConnection($config);
    }

    public function insert(
        string $producerService,
        string $eventType,
        string $messageBody,
        ?string $partitionKey = null
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->schema}.outbox (
                producer_service,
                event_type,
                message_body,
                partition_key
            ) VALUES (?, ?, ?::jsonb, ?)
        ");

        $stmt->execute([$producerService, $eventType, $messageBody, $partitionKey]);
    }

    private function createConnection(array $config): \PDO
    {
        $dsn = sprintf(
            "pgsql:host=%s;port=%s;dbname=%s",
            $config['host'],
            $config['port'],
            $config['name']
        );

        return new \PDO($dsn, $config['user'], $config['pass'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }
}

// Usage in NanoPublisher
public function publish(string $event): void
{
    $this->validateRequiredEnvVars([/*...*/]);
    $this->prepareMessageForPublish($event);

    $outbox = new OutboxRepository([
        'host' => $_ENV['DB_HOST'],
        'port' => $_ENV['DB_PORT'],
        'name' => $_ENV['DB_NAME'],
        'user' => $_ENV['DB_USER'],
        'pass' => $_ENV['DB_PASS'],
        'schema' => $_ENV['DB_SCHEMA'],
    ]);

    $outbox->insert(
        $_ENV['AMQP_MICROSERVICE_NAME'],
        $event,
        $this->message->getBody()
    );
}
```

**Benefits**:
- ✅ Separates database logic from publisher
- ✅ Easier to test (can mock repository)
- ✅ Could add more outbox operations (query, update status)
- ✅ Reusable by pg2event dispatcher
- ✅ Could add connection pooling to repository

**Priority**: 🟡 Medium

---

# 8. Additional Minor Improvements

## 8.1: Magic Strings and Constants

**Problem**: Environment variable names repeated as strings

```php
// Current
$this->getEnv(self::MICROSERVICE_NAME)
$_ENV['DB_HOST']
$_ENV['AMQP_MICROSERVICE_NAME']
```

**Recommendation**: Create constants class

```php
class EnvVars
{
    // Database
    public const DB_HOST = 'DB_HOST';
    public const DB_PORT = 'DB_PORT';
    public const DB_NAME = 'DB_NAME';
    public const DB_USER = 'DB_USER';
    public const DB_PASS = 'DB_PASS';
    public const DB_SCHEMA = 'DB_SCHEMA';

    // AMQP
    public const AMQP_HOST = 'AMQP_HOST';
    public const AMQP_MICROSERVICE_NAME = 'AMQP_MICROSERVICE_NAME';
    // ... etc

    public static function getOutboxVars(): array
    {
        return [
            self::DB_HOST,
            self::DB_PORT,
            self::DB_NAME,
            self::DB_USER,
            self::DB_PASS,
            self::DB_SCHEMA,
        ];
    }
}

// Usage
$this->validateRequiredEnvVars(EnvVars::getOutboxVars());
```

**Priority**: 🟡 Medium

---

## 8.2: Return Type Declarations

**Problem**: Some methods lack return type declarations

**Recommendation**: Add return types where possible

```php
// Before
public function getChannel()

// After
public function getChannel(): AMQPChannel
```

Helps with IDE autocompletion and type safety.

**Priority**: 🔴 High

---

## 8.3: Docblock Improvements

**Problem**: Some complex methods lack documentation

**Recommendation**: Add PHPDoc for complex methods

```php
/**
 * Publish message to PostgreSQL outbox table for eventual delivery to RabbitMQ
 *
 * This uses the transactional outbox pattern to ensure reliable message delivery
 * even when RabbitMQ is unavailable. The pg2event worker will process the outbox
 * and publish to RabbitMQ.
 *
 * @param string $event Event name (routing key), e.g., "user.created"
 * @throws \RuntimeException if required environment variables are missing
 * @throws \RuntimeException if database insert fails
 * @return void
 */
public function publish(string $event): void
```

**Priority**: 🟡 Medium

---

# Priority Ranking

Based on impact vs effort:

## High Priority (Low effort, high impact)

1. **Recommendation 2.1**: Extract message preparation (reduces duplication)
2. **Recommendation 3.1**: Validation helper (better error messages)
3. **Recommendation 4.2**: Extract tags building (consistency)
4. **Recommendation 1.1**: JSON mutation helper (reduces duplication across message class)
5. **Recommendation 5.1**: Simplify exception handling (cleaner code)
6. **Recommendation 8.2**: Type hints (better DX)

## Medium Priority (Medium effort, good impact)

7. **Recommendation 4.1**: Metrics wrapper (cleaner publish method)
8. **Recommendation 3.2**: Extract database connection (separation of concerns)
9. **Recommendation 6.1**: Generic pooling pattern (nice abstraction)
10. **Recommendation 7.3**: OutboxRepository (good separation)
11. **Recommendation 8.1**: Constants for env vars (reduces errors)
12. **Recommendation 8.3**: Docs (better DX)

## Low Priority (High effort, lower immediate impact)

13. **Recommendation 7.2**: Dependency injection for pools (testing improvement)
14. **Recommendation 5.2**: Strategy pattern for errors (over-engineering?)
15. **Recommendation 7.1**: Publishing strategies (large refactor)
16. **Recommendation 1.2**: Path-based updater (nice to have)
17. **Recommendation 2.2**: Extract delay logic (minor)

---

# Implementation Guidelines

When implementing these recommendations:

1. **One at a time**: Implement each refactoring separately with tests
2. **Backwards compatibility**: All changes must be non-breaking (see [CLAUDE.md](../CLAUDE.md))
3. **Test coverage**: Add tests before refactoring to ensure behavior unchanged
4. **Gradual rollout**: Deploy to staging first, monitor metrics
5. **Documentation**: Update [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md) when code changes

---

# Anti-Patterns to Avoid

❌ **Don't**: Refactor everything at once
✅ **Do**: Pick one small improvement, implement, test, deploy

❌ **Don't**: Create abstractions for hypothetical future use
✅ **Do**: Refactor based on actual duplication (rule of three)

❌ **Don't**: Change behavior during refactoring
✅ **Do**: Preserve exact same behavior, just cleaner code

❌ **Don't**: Add dependencies without major version bump
✅ **Do**: Keep refactorings internal to existing classes

---

# Questions Before Implementing

For each refactoring, ask:

1. Does this maintain 100% backwards compatibility?
2. Is the code objectively clearer after the change?
3. Does this reduce actual duplication (not hypothetical)?
4. Can I test this change independently?
5. Will this make future changes easier?

If yes to all → Good refactoring candidate
If no to any → Reconsider or adjust approach

---

**Last updated**: 2026-01-30

**Related documents**:
- [ARCHITECTURE_PUBLISHING_DEEP_DIVE.md](ARCHITECTURE_PUBLISHING_DEEP_DIVE.md) - Current publishing architecture
- [REFACTORING_RECOMMENDATIONS.md](REFACTORING_RECOMMENDATIONS.md) - Consumer refactoring recommendations
- [CLAUDE.md](../CLAUDE.md) - Development guidelines
- [CHANGELOG.md](../CHANGELOG.md) - Version history
