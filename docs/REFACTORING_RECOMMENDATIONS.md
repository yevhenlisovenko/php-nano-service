# Refactoring Recommendations: nano-service

> **Audience**: Developers planning future improvements to nano-service
> **Purpose**: Code quality improvements for better readability, maintainability, and reduced duplication
> **Scope**: Refactoring only - NO bug fixes, stability changes, or feature additions

**Last Updated**: 2026-01-29

---

## ⚠️ CRITICAL: Backwards Compatibility

**ALL recommendations must maintain backwards compatibility.**

This is a library used by production services. Any refactoring MUST:
- ✅ Keep existing public method signatures unchanged
- ✅ Maintain current behavior and defaults
- ✅ Pass all existing tests
- ✅ Not introduce new required dependencies
- ⚠️ See [CLAUDE.md](../CLAUDE.md) for complete compatibility rules

---

## Table of Contents

1. [Consumer Implementation Refactoring](#1-consumer-implementation-refactoring)
2. [Message Processing Refactoring](#2-message-processing-refactoring)
3. [Configuration & Setup Refactoring](#3-configuration--setup-refactoring)
4. [Metrics Collection Refactoring](#4-metrics-collection-refactoring)
5. [Connection Management Refactoring](#5-connection-management-refactoring)
6. [Cross-Cutting Concerns](#6-cross-cutting-concerns)

---

## How to Use This Document

Each recommendation includes:
- **Related Architecture Section**: Link to [ARCHITECTURE_DEEP_DIVE.md](ARCHITECTURE_DEEP_DIVE.md) context
- **Current Issue**: What makes the code hard to read/maintain
- **Recommendation**: Specific refactoring approach
- **Benefits**: Why this improves code quality
- **Example**: Before/after code snippets
- **Impact**: Scope and risk level

**Priority Levels**:
- 🔴 **High**: Significant improvement to code quality
- 🟡 **Medium**: Moderate improvement, nice to have
- 🟢 **Low**: Minor cleanup, low priority

---

# 1. Consumer Implementation Refactoring

## 1.1 Extract Queue Setup Logic into Dedicated Class

**Related Architecture**: [Section 2.4 - Dead Letter Exchange Setup](ARCHITECTURE_DEEP_DIVE.md#24-dead-letter-exchange-setup-initialwithfailedqueue)

**Priority**: 🔴 High

### Current Issue

The `initialWithFailedQueue()` method in [NanoConsumer.php:78-92](../src/NanoConsumer.php#L78-L92) does too many things:
1. Calculates queue names
2. Creates main queue with DLX configuration
3. Creates delayed exchange
4. Creates failed queue
5. Binds queue to exchange

This violates Single Responsibility Principle and makes the logic hard to test in isolation.

### Recommendation

Create a dedicated `QueueSetupBuilder` class:

```php
class QueueSetupBuilder
{
    private string $serviceName;
    private string $namespace;

    public function __construct(string $serviceName, string $namespace) { ... }

    public function buildMainQueue(): QueueConfiguration { ... }
    public function buildDelayedExchange(): ExchangeConfiguration { ... }
    public function buildFailedQueue(): QueueConfiguration { ... }
    public function buildBinding(): BindingConfiguration { ... }

    public function createAll(AMQPChannel $channel): void { ... }
}
```

Then simplify `initialWithFailedQueue()`:

```php
private function initialWithFailedQueue(): void
{
    $builder = new QueueSetupBuilder(
        $this->getEnv(self::MICROSERVICE_NAME),
        $this->getProject()
    );

    $builder->createAll($this->getChannel());
}
```

### Benefits

- **Testability**: Can unit test queue setup without RabbitMQ connection
- **Readability**: Clear separation of concerns
- **Reusability**: Setup logic can be used by other components (e.g., admin tools)
- **Documentation**: Configuration objects are self-documenting

---

## 1.2 Reduce Complexity of `consumeCallback()` Method

**Related Architecture**: [Section 3.2 - Line-by-Line Breakdown: consumeCallback()](ARCHITECTURE_DEEP_DIVE.md#32-line-by-line-breakdown-consumecallback)

**Priority**: 🔴 High

### Current Issue

The `consumeCallback()` method ([NanoConsumer.php:158-261](../src/NanoConsumer.php#L158-L261)) is 103 lines long and handles:
1. Message preparation
2. System event routing
3. Callback selection
4. Retry tracking
5. Metrics setup
6. Message processing
7. Error handling
8. Retry logic
9. DLX routing

**Cyclomatic complexity**: Too high for maintainability.

### Recommendation

Extract logical phases into private methods:

```php
public function consumeCallback(AMQPMessage $message): void
{
    $wrappedMessage = $this->prepareMessage($message);

    if ($this->handleSystemEvent($wrappedMessage)) {
        return; // System event handled
    }

    $callback = $this->selectCallback($wrappedMessage);
    $retryContext = $this->buildRetryContext($message);

    $this->trackMessageReceived($wrappedMessage, $retryContext);

    try {
        $this->processMessage($callback, $wrappedMessage);
        $this->handleSuccess($wrappedMessage);
    } catch (Throwable $e) {
        $this->handleFailure($wrappedMessage, $retryContext, $e);
    }
}

private function prepareMessage(AMQPMessage $message): NanoServiceMessage { ... }
private function handleSystemEvent(NanoServiceMessage $message): bool { ... }
private function selectCallback(NanoServiceMessage $message): callable { ... }
private function buildRetryContext(AMQPMessage $message): RetryContext { ... }
private function trackMessageReceived(NanoServiceMessage $msg, RetryContext $ctx): void { ... }
private function processMessage(callable $callback, NanoServiceMessage $message): void { ... }
private function handleSuccess(NanoServiceMessage $message): void { ... }
private function handleFailure(NanoServiceMessage $msg, RetryContext $ctx, Throwable $e): void { ... }
```

### Benefits

- **Readability**: Each method has single purpose
- **Testability**: Can test each phase independently
- **Maintainability**: Easier to modify one phase without affecting others
- **Debugging**: Stack traces show clear method names

---

## 1.3 Introduce Value Object for Retry Context

**Related Architecture**: [Section 3.3 - Retry Strategy Deep Dive](ARCHITECTURE_DEEP_DIVE.md#33-retry-strategy-deep-dive)

**Priority**: 🟡 Medium

### Current Issue

Retry information is scattered across multiple variables:
- `$retryCount` (from headers)
- `$retryTag` (FIRST/RETRY/LAST)
- `$backoffDelay` (calculated from array)
- `$this->tries` (max attempts)
- `$this->backoff` (delay configuration)

This makes it hard to track retry state and pass between methods.

### Recommendation

Create a `RetryContext` value object:

```php
class RetryContext
{
    private int $attemptNumber;
    private int $maxAttempts;
    private EventRetryStatusTag $retryTag;
    private int $backoffDelayMs;
    private array $backoffConfig;

    public function __construct(
        int $attemptNumber,
        int $maxAttempts,
        array $backoffConfig
    ) {
        $this->attemptNumber = $attemptNumber;
        $this->maxAttempts = $maxAttempts;
        $this->backoffConfig = $backoffConfig;
        $this->retryTag = $this->calculateRetryTag();
        $this->backoffDelayMs = $this->calculateBackoff();
    }

    public function hasRetriesRemaining(): bool
    {
        return $this->attemptNumber < $this->maxAttempts;
    }

    public function getAttemptNumber(): int { return $this->attemptNumber; }
    public function getRetryTag(): EventRetryStatusTag { return $this->retryTag; }
    public function getBackoffDelayMs(): int { return $this->backoffDelayMs; }

    private function calculateRetryTag(): EventRetryStatusTag { ... }
    private function calculateBackoff(): int { ... }
}
```

Usage:

```php
// Before: scattered variables
$retryCount = (int)($message->get('x-retry-count') ?? 0) + 1;
$retryTag = $this->getRetryTag($retryCount);
$backoff = $this->getBackoff($retryCount);

// After: cohesive object
$retryContext = RetryContext::fromMessage($message, $this->tries, $this->backoff);
if ($retryContext->hasRetriesRemaining()) { ... }
```

### Benefits

- **Cohesion**: All retry logic in one place
- **Type Safety**: IDE autocomplete for retry properties
- **Immutability**: Value object can't be accidentally modified
- **Clarity**: Clear intent when passing `RetryContext` vs individual variables

---

## 1.4 Consolidate Callback Management

**Related Architecture**: [Section 2.3 - Store Callbacks](ARCHITECTURE_DEEP_DIVE.md#line-122-123-store-callbacks)

**Priority**: 🟢 Low

### Current Issue

Multiple callback properties with similar handling:
- `$this->callback` (main handler)
- `$this->debugCallback` (debug handler)
- `$this->catchCallback` (retry handler)
- `$this->failedCallback` (DLX handler)

Each has similar null checks: `if ($this->catchCallback) { ... }`

### Recommendation

Create a `CallbackRegistry` class:

```php
class CallbackRegistry
{
    private ?callable $mainCallback = null;
    private ?callable $debugCallback = null;
    private ?callable $catchCallback = null;
    private ?callable $failedCallback = null;

    public function setMainCallback(callable $callback): void { ... }
    public function setDebugCallback(callable $callback): void { ... }
    public function setCatchCallback(callable $callback): void { ... }
    public function setFailedCallback(callable $callback): void { ... }

    public function getActiveCallback(bool $debugMode): callable
    {
        return $debugMode && $this->debugCallback !== null
            ? $this->debugCallback
            : $this->mainCallback;
    }

    public function invokeCatchCallback(NanoServiceMessage $msg, Throwable $e): void
    {
        if ($this->catchCallback) {
            ($this->catchCallback)($msg, $e);
        }
    }

    public function invokeFailedCallback(NanoServiceMessage $msg, Throwable $e): void
    {
        if ($this->failedCallback) {
            ($this->failedCallback)($msg, $e);
        }
    }
}
```

### Benefits

- **Encapsulation**: Callback logic in one place
- **Consistent Interface**: All callbacks accessed through registry
- **Testability**: Can mock CallbackRegistry for testing

---

# 2. Message Processing Refactoring

## 2.1 Extract Message Publishing Logic

**Related Architecture**: [Section 3.2 Phase 8A - Retry Logic](ARCHITECTURE_DEEP_DIVE.md#phase-8a-retry-logic-lines-217-231)

**Priority**: 🔴 High

### Current Issue

Message publishing for retries and DLX is embedded in `consumeCallback()`:

```php
// Retry publishing (lines 223-228)
$newMessage = new AMQPMessage($message->getBody(), $messageProperties);
$headers = new AMQPTable([
    'x-delay' => $this->getBackoff($retryCount),
    'x-retry-count' => $retryCount
]);
$newMessage->set('application_headers', $headers);
$this->getChannel()->basic_publish($newMessage, $this->queue, $key);
```

This logic is repeated for DLX publishing with different parameters.

### Recommendation

Create a `MessagePublisher` class:

```php
class MessagePublisher
{
    private AMQPChannel $channel;
    private string $queueName;

    public function __construct(AMQPChannel $channel, string $queueName) { ... }

    public function publishForRetry(
        AMQPMessage $originalMessage,
        RetryContext $retryContext,
        string $routingKey
    ): void {
        $headers = new AMQPTable([
            'x-delay' => $retryContext->getBackoffDelayMs(),
            'x-retry-count' => $retryContext->getAttemptNumber()
        ]);

        $message = $this->cloneMessage($originalMessage, $headers);
        $this->channel->basic_publish($message, $this->queueName, $routingKey);
    }

    public function publishToDLX(
        AMQPMessage $originalMessage,
        string $errorMessage,
        int $retryCount
    ): void {
        $dlxQueue = $this->queueName . '.failed';
        $headers = new AMQPTable([
            'x-retry-count' => $retryCount,
            'x-error-message' => $errorMessage
        ]);

        $message = $this->cloneMessage($originalMessage, $headers);
        $this->channel->basic_publish($message, '', $dlxQueue);
    }

    private function cloneMessage(AMQPMessage $original, AMQPTable $headers): AMQPMessage { ... }
}
```

### Benefits

- **DRY**: Eliminates duplication between retry and DLX publishing
- **Testability**: Can mock MessagePublisher to verify publishing behavior
- **Clarity**: Intent is clear from method names

---

## 2.2 Introduce Strategy Pattern for Backoff Calculation

**Related Architecture**: [Section 3.3 - Backoff Calculation](ARCHITECTURE_DEEP_DIVE.md#backoff-calculation-getbackoff)

**Priority**: 🟡 Medium

### Current Issue

The `getBackoff()` method handles two different strategies with conditional logic:

```php
public function getBackoff(int $count): int
{
    if (is_array($this->backoff)) {
        // Array strategy: exponential backoff
        $lastIndex = count($this->backoff) - 1;
        $delay = $this->backoff[min($count, $lastIndex)];
        return $delay * 1000;
    }

    // Scalar strategy: fixed delay
    return $this->backoff * 1000;
}
```

### Recommendation

Use Strategy pattern:

```php
interface BackoffStrategy
{
    public function calculateDelay(int $attemptNumber): int; // Returns milliseconds
}

class ExponentialBackoffStrategy implements BackoffStrategy
{
    private array $delays;

    public function __construct(array $delaysInSeconds)
    {
        $this->delays = $delaysInSeconds;
    }

    public function calculateDelay(int $attemptNumber): int
    {
        $lastIndex = count($this->delays) - 1;
        $delay = $this->delays[min($attemptNumber, $lastIndex)];
        return $delay * 1000;
    }
}

class FixedBackoffStrategy implements BackoffStrategy
{
    private int $delaySeconds;

    public function __construct(int $delaySeconds)
    {
        $this->delaySeconds = $delaySeconds;
    }

    public function calculateDelay(int $attemptNumber): int
    {
        return $this->delaySeconds * 1000;
    }
}
```

Usage:

```php
// In NanoConsumer
private BackoffStrategy $backoffStrategy;

public function backoff($backoff): self
{
    $this->backoffStrategy = is_array($backoff)
        ? new ExponentialBackoffStrategy($backoff)
        : new FixedBackoffStrategy($backoff);

    return $this;
}
```

### Benefits

- **Extensibility**: Easy to add new backoff strategies (linear, fibonacci, etc.)
- **Testability**: Each strategy can be tested independently
- **Clarity**: Strategy intent is explicit in class name
- **Open/Closed Principle**: Open for extension, closed for modification

---

## 2.3 Separate ACK Logic from Error Handling

**Related Architecture**: [Section 3.2 Phase 6 - Process Message](ARCHITECTURE_DEEP_DIVE.md#phase-6-process-message-lines-197-210)

**Priority**: 🟡 Medium

### Current Issue

ACK logic is duplicated in multiple places:
- Success path with nested try-catch (lines 203-207)
- Retry path (line 230)
- DLX path (line 255)

Each has similar error handling for ACK failures.

### Recommendation

Create an `AcknowledgmentManager`:

```php
class AcknowledgmentManager
{
    private StatsDClient $statsD;
    private string $serviceName;

    public function safeAcknowledge(NanoServiceMessage $message): void
    {
        try {
            $message->ack();
        } catch (Throwable $e) {
            $this->trackAckFailure($message, $e);
            // Don't rethrow - ACK failures are tracked but don't stop processing
        }
    }

    private function trackAckFailure(NanoServiceMessage $message, Throwable $e): void
    {
        if (!$this->statsD || !$this->statsD->isEnabled()) {
            return;
        }

        $tags = [
            'service' => $this->serviceName,
            'event' => $message->getEventName(),
            'error_type' => get_class($e),
        ];

        $this->statsD->increment('rmq_consumer_ack_failed_total', $tags);
    }
}
```

### Benefits

- **DRY**: Single implementation of ACK logic
- **Consistent Error Handling**: All ACK failures handled same way
- **Testability**: Can verify ACK behavior in isolation

---

# 3. Configuration & Setup Refactoring

## 3.1 Replace Magic Strings with Constants Class

**Related Architecture**: [Section 1.3 - This Package's Architecture](ARCHITECTURE_DEEP_DIVE.md#13-this-packages-architecture)

**Priority**: 🟡 Medium

### Current Issue

String literals scattered throughout code:
- Exchange types: `'topic'`, `'x-delayed-message'`
- Queue suffixes: `'.failed'`
- Headers: `'x-delay'`, `'x-retry-count'`, `'x-error-message'`
- Routing keys: `'#'`

Hard to track usage and prone to typos.

### Recommendation

Create constants class:

```php
class RabbitMQConstants
{
    // Exchange Types
    public const EXCHANGE_TYPE_TOPIC = 'topic';
    public const EXCHANGE_TYPE_FANOUT = 'fanout';
    public const EXCHANGE_TYPE_DIRECT = 'direct';
    public const EXCHANGE_TYPE_DELAYED = 'x-delayed-message';

    // Queue Suffixes
    public const FAILED_QUEUE_SUFFIX = '.failed';

    // Headers
    public const HEADER_DELAY = 'x-delay';
    public const HEADER_RETRY_COUNT = 'x-retry-count';
    public const HEADER_ERROR_MESSAGE = 'x-error-message';
    public const HEADER_DELAYED_TYPE = 'x-delayed-type';
    public const HEADER_DLX = 'x-dead-letter-exchange';

    // Routing Keys
    public const ROUTING_KEY_ALL = '#';

    // QoS Settings
    public const DEFAULT_PREFETCH_COUNT = 1;
}
```

### Benefits

- **Discoverability**: IDE autocomplete shows all available constants
- **Refactoring Safety**: Rename constant updates all usages
- **Documentation**: Constants are self-documenting
- **Type Safety**: Less risk of typos

---

## 3.2 Extract Environment Variable Management

**Related Architecture**: [Section 2.4 - Get Queue Name](ARCHITECTURE_DEEP_DIVE.md#line-80-get-queue-name)

**Priority**: 🟢 Low

### Current Issue

Environment variable names are scattered:
- `AMQP_MICROSERVICE_NAME`
- `STATSD_ENABLED`
- `STATSD_HOST`
- etc.

Mix of `getEnv()` and `envBool()` methods.

### Recommendation

Create `EnvironmentConfig` class:

```php
class EnvironmentConfig
{
    public function getServiceName(): string
    {
        return $this->getRequired('AMQP_MICROSERVICE_NAME');
    }

    public function getProjectNamespace(): string
    {
        return $this->getRequired('AMQP_PROJECT');
    }

    public function isStatsDEnabled(): bool
    {
        return $this->getBool('STATSD_ENABLED', false);
    }

    public function getStatsDHost(): string
    {
        return $this->get('STATSD_HOST', 'localhost');
    }

    private function getRequired(string $key): string { ... }
    private function get(string $key, string $default): string { ... }
    private function getBool(string $key, bool $default): bool { ... }
}
```

### Benefits

- **Centralized Configuration**: All env vars in one place
- **Type Safety**: Methods return correct types
- **Documentation**: Clear what configuration is available
- **Testing**: Can mock EnvironmentConfig

---

# 4. Metrics Collection Refactoring

## 4.1 Extract Metrics Building Logic

**Related Architecture**: [Section 3.4 - Metrics Collection](ARCHITECTURE_DEEP_DIVE.md#34-metrics-collection-lines-2471-2639)

**Priority**: 🔴 High

### Current Issue

Metric tags are built inline throughout `consumeCallback()`:

```php
// Lines 181-186
$tags = [
    'service' => $this->getEnv(self::MICROSERVICE_NAME),
    'event' => $wrappedMessage->getEventName(),
];

// Lines 239-244
$tags = [
    'service' => $this->getEnv(self::MICROSERVICE_NAME),
    'event' => $wrappedMessage->getEventName(),
    'reason' => PublishErrorType::DLX_MAX_RETRIES->value,
];
```

Tag building logic is duplicated with slight variations.

### Recommendation

Create `MetricTagBuilder`:

```php
class MetricTagBuilder
{
    private string $serviceName;

    public function __construct(string $serviceName)
    {
        $this->serviceName = $serviceName;
    }

    public function buildBaseTags(string $eventName): array
    {
        return [
            'service' => $this->serviceName,
            'event' => $eventName,
        ];
    }

    public function buildRetryTags(
        string $eventName,
        EventRetryStatusTag $retryTag
    ): array {
        return array_merge($this->buildBaseTags($eventName), [
            'retry' => $retryTag->value,
        ]);
    }

    public function buildDLXTags(
        string $eventName,
        PublishErrorType $reason
    ): array {
        return array_merge($this->buildBaseTags($eventName), [
            'reason' => $reason->value,
        ]);
    }

    public function buildErrorTags(
        string $eventName,
        EventRetryStatusTag $retryTag,
        PublishErrorType $errorType
    ): array {
        return array_merge($this->buildRetryTags($eventName, $retryTag), [
            'error_type' => $errorType->value,
        ]);
    }
}
```

### Benefits

- **DRY**: Eliminates tag building duplication
- **Consistency**: All tags built using same logic
- **Testability**: Can verify tag building independently
- **Maintainability**: Change tag structure in one place

---

## 4.2 Create Metrics Facade for Consumer

**Related Architecture**: [Section 3.4 - 6 Key Metrics](ARCHITECTURE_DEEP_DIVE.md#6-key-metrics-emitted)

**Priority**: 🔴 High

### Current Issue

Metrics tracking code is verbose and scattered:

```php
// Check if enabled before every metric call
if ($this->statsD && $this->statsD->isEnabled()) {
    $this->statsD->increment('event_started_count', $tags);
}

if ($this->statsD && $this->statsD->isEnabled()) {
    $this->statsD->histogram('rmq_consumer_payload_bytes', $size, $tags);
}
```

Lots of boilerplate null checks.

### Recommendation

Create `ConsumerMetrics` facade:

```php
class ConsumerMetrics
{
    private ?StatsDClient $statsD;
    private MetricTagBuilder $tagBuilder;

    public function __construct(?StatsDClient $statsD, MetricTagBuilder $tagBuilder)
    {
        $this->statsD = $statsD;
        $this->tagBuilder = $tagBuilder;
    }

    public function trackMessageReceived(
        string $eventName,
        int $payloadBytes,
        EventRetryStatusTag $retryTag
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $tags = $this->tagBuilder->buildRetryTags($eventName, $retryTag);
        $this->statsD->increment('event_started_count', $tags);
        $this->statsD->histogram('rmq_consumer_payload_bytes', $payloadBytes, $tags);
    }

    public function trackSuccess(
        string $eventName,
        EventRetryStatusTag $retryTag,
        int $durationMs
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $tags = array_merge(
            $this->tagBuilder->buildRetryTags($eventName, $retryTag),
            ['exit_status' => 'success']
        );
        $this->statsD->timing('event_processed_duration', $durationMs, $tags);
    }

    public function trackFailure(
        string $eventName,
        EventRetryStatusTag $retryTag,
        PublishErrorType $errorType,
        int $durationMs
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $tags = array_merge(
            $this->tagBuilder->buildErrorTags($eventName, $retryTag, $errorType),
            ['exit_status' => 'failed']
        );
        $this->statsD->timing('event_processed_duration', $durationMs, $tags);
    }

    public function trackDLX(
        string $eventName,
        PublishErrorType $reason
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $tags = $this->tagBuilder->buildDLXTags($eventName, $reason);
        $this->statsD->increment('rmq_consumer_dlx_total', $tags);
    }

    private function isEnabled(): bool
    {
        return $this->statsD && $this->statsD->isEnabled();
    }
}
```

### Benefits

- **Cleaner Code**: No null checks in consumer code
- **Domain Language**: Methods named after business events
- **Single Responsibility**: Metrics logic separate from message processing
- **Easy to Mock**: Can disable metrics in tests

---

# 5. Connection Management Refactoring

## 5.1 Extract Connection Pool Management

**Related Architecture**: [Appendix A - Channel Exhaustion Incident](ARCHITECTURE_DEEP_DIVE.md#appendix-a-related-incidents)

**Priority**: 🔴 High (Critical for stability)

### Current Issue

Connection pooling is implemented with static properties scattered across `NanoServiceClass`:

```php
protected static ?AMQPStreamConnection $sharedConnection = null;
protected static ?AMQPChannel $sharedChannel = null;
```

Logic is embedded in `getConnection()` and `getChannel()` methods with checks like:

```php
if (self::$sharedConnection && self::$sharedConnection->isConnected()) {
    return self::$sharedConnection;
}
```

### Recommendation

Create dedicated `ConnectionPool` class:

```php
class ConnectionPool
{
    private static ?AMQPStreamConnection $connection = null;
    private static ?AMQPChannel $channel = null;
    private static int $connectionCount = 0;
    private static int $channelCount = 0;

    public static function getConnection(array $config): AMQPStreamConnection
    {
        if (self::$connection === null || !self::$connection->isConnected()) {
            self::$connection = new AMQPStreamConnection(
                $config['host'],
                $config['port'],
                $config['user'],
                $config['pass']
            );
            self::$connectionCount++;
        }

        return self::$connection;
    }

    public static function getChannel(array $config): AMQPChannel
    {
        if (self::$channel === null || !self::channelIsOpen()) {
            $connection = self::getConnection($config);
            self::$channel = $connection->channel();
            self::$channelCount++;
        }

        return self::$channel;
    }

    public static function close(): void
    {
        if (self::$channel !== null) {
            self::$channel->close();
            self::$channel = null;
        }

        if (self::$connection !== null) {
            self::$connection->close();
            self::$connection = null;
        }
    }

    public static function getStats(): array
    {
        return [
            'connections_created' => self::$connectionCount,
            'channels_created' => self::$channelCount,
            'connection_active' => self::$connection !== null,
            'channel_active' => self::$channel !== null,
        ];
    }

    private static function channelIsOpen(): bool
    {
        return self::$channel !== null
            && self::$channel->is_open();
    }
}
```

### Benefits

- **Centralized Logic**: All connection pooling in one place
- **Observability**: `getStats()` helps prevent future channel leaks
- **Testability**: Can inject mock ConnectionPool
- **Safety**: Harder to accidentally create connections outside pool

---

## 5.2 Add Connection Health Checks

**Related Architecture**: [Section 1.4 Decision 8 - Manual Acknowledgment](ARCHITECTURE_DEEP_DIVE.md#decision-8-manual-acknowledgment)

**Priority**: 🟡 Medium

### Current Issue

No proactive health checks for connections. Only discovers connection issues when trying to publish/consume.

### Recommendation

Add health check capability to `ConnectionPool`:

```php
class ConnectionPool
{
    // ... existing code ...

    public static function healthCheck(): HealthStatus
    {
        $status = new HealthStatus();

        // Check connection
        if (self::$connection === null) {
            $status->addError('No connection established');
        } elseif (!self::$connection->isConnected()) {
            $status->addError('Connection is not connected');
        } else {
            $status->addSuccess('Connection is healthy');
        }

        // Check channel
        if (self::$channel === null) {
            $status->addWarning('No channel established');
        } elseif (!self::$channel->is_open()) {
            $status->addError('Channel is not open');
        } else {
            $status->addSuccess('Channel is healthy');
        }

        return $status;
    }

    public static function reconnectIfNeeded(): bool
    {
        $health = self::healthCheck();

        if (!$health->isHealthy()) {
            self::close();
            // Connection will be recreated on next getConnection() call
            return true;
        }

        return false;
    }
}

class HealthStatus
{
    private array $errors = [];
    private array $warnings = [];
    private array $successes = [];

    public function isHealthy(): bool
    {
        return empty($this->errors);
    }

    public function addError(string $message): void { ... }
    public function addWarning(string $message): void { ... }
    public function addSuccess(string $message): void { ... }
    public function getReport(): array { ... }
}
```

### Benefits

- **Proactive Detection**: Catch connection issues before they cause failures
- **Better Logging**: Clear health status for monitoring
- **Auto-Recovery**: Can reconnect without manual intervention

---

# 6. Cross-Cutting Concerns

## 6.1 Introduce Domain Events for Internal Actions

**Related Architecture**: [Section 3.2 - consumeCallback() phases](ARCHITECTURE_DEEP_DIVE.md#high-level-flow)

**Priority**: 🟢 Low

### Current Issue

Side effects (metrics, logging, callbacks) are hardcoded in message processing flow. Hard to add new observers without modifying core code.

### Recommendation

Use domain events:

```php
interface DomainEvent {}

class MessageReceivedEvent implements DomainEvent
{
    public function __construct(
        public readonly NanoServiceMessage $message,
        public readonly RetryContext $retryContext
    ) {}
}

class MessageProcessedSuccessfullyEvent implements DomainEvent
{
    public function __construct(
        public readonly NanoServiceMessage $message,
        public readonly int $durationMs
    ) {}
}

class MessageFailedEvent implements DomainEvent
{
    public function __construct(
        public readonly NanoServiceMessage $message,
        public readonly RetryContext $retryContext,
        public readonly Throwable $error
    ) {}
}

interface EventListener
{
    public function handle(DomainEvent $event): void;
}

class MetricsEventListener implements EventListener
{
    private ConsumerMetrics $metrics;

    public function handle(DomainEvent $event): void
    {
        match (true) {
            $event instanceof MessageReceivedEvent => $this->onMessageReceived($event),
            $event instanceof MessageProcessedSuccessfullyEvent => $this->onSuccess($event),
            $event instanceof MessageFailedEvent => $this->onFailure($event),
            default => null,
        };
    }

    private function onMessageReceived(MessageReceivedEvent $event): void { ... }
    private function onSuccess(MessageProcessedSuccessfullyEvent $event): void { ... }
    private function onFailure(MessageFailedEvent $event): void { ... }
}

class EventDispatcher
{
    private array $listeners = [];

    public function addListener(EventListener $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function dispatch(DomainEvent $event): void
    {
        foreach ($this->listeners as $listener) {
            $listener->handle($event);
        }
    }
}
```

Then in `consumeCallback()`:

```php
public function consumeCallback(AMQPMessage $message): void
{
    $wrappedMessage = $this->prepareMessage($message);
    $retryContext = $this->buildRetryContext($message);

    $this->eventDispatcher->dispatch(
        new MessageReceivedEvent($wrappedMessage, $retryContext)
    );

    try {
        $this->processMessage($callback, $wrappedMessage);
        $this->eventDispatcher->dispatch(
            new MessageProcessedSuccessfullyEvent($wrappedMessage, $duration)
        );
    } catch (Throwable $e) {
        $this->eventDispatcher->dispatch(
            new MessageFailedEvent($wrappedMessage, $retryContext, $e)
        );
    }
}
```

### Benefits

- **Open/Closed Principle**: Add new listeners without modifying consumer
- **Separation of Concerns**: Metrics, logging, callbacks are separate listeners
- **Testability**: Can test listeners independently
- **Flexibility**: Users can add custom listeners

**Note**: This is a more advanced refactoring and may be overkill for current needs.

---

## 6.2 Add Type Hints Throughout Codebase

**Related Architecture**: All sections

**Priority**: 🔴 High

### Current Issue

Many methods lack strict type hints, especially for parameters and return types:

```php
// Current
public function backoff($backoff)
{
    $this->backoff = $backoff;
    return $this;
}

// Missing types
protected function getEnv($key, $default = null)
{
    return $_ENV[$key] ?? $default;
}
```

### Recommendation

Add strict types everywhere:

```php
// Improved
public function backoff(int|array $backoff): self
{
    $this->backoff = $backoff;
    return $this;
}

protected function getEnv(string $key, ?string $default = null): ?string
{
    return $_ENV[$key] ?? $default;
}
```

Enable strict types at file level:

```php
<?php

declare(strict_types=1);

namespace AlexFN\NanoService;
```

### Benefits

- **IDE Support**: Better autocomplete and type checking
- **Early Error Detection**: Type errors caught at runtime
- **Documentation**: Types serve as inline documentation
- **Refactoring Safety**: IDE can warn about type mismatches

---

## 6.3 Improve Naming Consistency

**Related Architecture**: Multiple sections

**Priority**: 🟡 Medium

### Current Issue

Inconsistent naming patterns:

- `initialWithFailedQueue()` - verb is "initial" (should be "initialize")
- `consumeCallback()` - noun + verb (should be verb + noun like `handleConsumedMessage()`)
- `getBackoff()` - returns calculated value, not property (should be `calculateBackoff()`)
- `$this->queue` - stores queue name string, not queue object (should be `$this->queueName`)

### Recommendation

Standardize naming:

**Methods**:
- Prefix with verb: `create`, `calculate`, `build`, `get`, `set`, `is`, `has`
- `initialWithFailedQueue()` → `initializeQueueWithDLX()`
- `consumeCallback()` → `handleConsumedMessage()`
- `getBackoff()` → `calculateBackoffDelay()`

**Properties**:
- Reflect actual type:
  - `$this->queue` → `$this->queueName` (it's a string, not queue object)
  - `$this->exchange` → `$this->exchangeName`
  - `$this->callback` → `$this->messageHandler`

**Constants**:
- Use SCREAMING_SNAKE_CASE for all constants
- Group by domain: `QUEUE_`, `EXCHANGE_`, `HEADER_`, etc.

### Benefits

- **Clarity**: Name reflects purpose
- **Consistency**: Easier to guess method/property names
- **Maintainability**: Code reads like documentation

---

# Implementation Strategy

## Recommended Order

**Phase 1: Foundation (No Breaking Changes)**
1. Add type hints throughout (6.2)
2. Create constants class (3.1)
3. Extract environment config (3.2)

**Phase 2: Metrics & Observability**
1. Create MetricTagBuilder (4.1)
2. Create ConsumerMetrics facade (4.2)
3. Update consumer to use new metrics classes

**Phase 3: Message Processing**
1. Create RetryContext value object (1.3)
2. Extract MessagePublisher (2.1)
3. Extract AcknowledgmentManager (2.3)
4. Break down consumeCallback() (1.2)

**Phase 4: Configuration & Setup**
1. Create QueueSetupBuilder (1.1)
2. Add backoff strategy pattern (2.2)

**Phase 5: Connection Management**
1. Extract ConnectionPool (5.1)
2. Add health checks (5.2)

**Phase 6: Polish**
1. Improve naming consistency (6.3)
2. Add callback registry (1.4)
3. Consider domain events (6.1) - optional

---

## Testing Strategy

For each refactoring:

1. **Before Refactoring**:
   - Run all existing tests to ensure baseline
   - Add new tests for behavior being refactored
   - Document expected behavior

2. **During Refactoring**:
   - Make changes incrementally
   - Run tests after each change
   - Keep old code as private methods initially

3. **After Refactoring**:
   - Verify all tests pass
   - Add tests for new classes/methods
   - Test with real RabbitMQ (integration tests)
   - Update documentation

4. **Backwards Compatibility Check**:
   - Test with existing consumer code (e.g., easyweek-service-backend)
   - Verify no changes to public API
   - Check that metrics still work correctly

---

## Success Criteria

A refactoring is successful when:

- ✅ All existing tests pass
- ✅ No changes to public method signatures
- ✅ Code coverage maintained or improved
- ✅ Cyclomatic complexity reduced
- ✅ Fewer lines of code per method (ideally < 20 lines)
- ✅ Existing services work without modification
- ✅ Documentation updated

---

## Final Notes

### Prioritization

Focus on refactorings that:
1. **Reduce complexity** in critical paths (consumeCallback, connection pooling)
2. **Improve testability** (extract dependencies, use interfaces)
3. **Enhance observability** (better metrics, health checks)

### Risk Management

- Start with low-risk refactorings (type hints, constants)
- Test extensively with real RabbitMQ before deploying
- Deploy to staging first, monitor metrics
- Have rollback plan ready

### Long-Term Vision

These refactorings prepare the codebase for future enhancements:
- Easier to add new features (circuit breaker, rate limiting)
- Better testability (can mock dependencies)
- Clearer architecture (easier onboarding for new developers)
- More maintainable (changes isolated to specific classes)

---

**Remember**: All refactorings MUST maintain backwards compatibility. This library is used in production. Safety first!
