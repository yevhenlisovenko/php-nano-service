# Architecture Deep Dive: Event Publishing in nano-service

> **Audience**: Developers working with or maintaining nano-service event publishing
> **Purpose**: Comprehensive understanding of event publishing architecture, flow, and internal implementation

**Last Updated**: 2026-01-29

---

## Table of Contents

1. [Publishing Architecture Overview](#1-publishing-architecture-overview)
2. [Message Construction Deep Dive](#2-message-construction-deep-dive)
3. [Publisher Initialization & Configuration](#3-publisher-initialization--configuration)
4. [Publishing Flow: PostgreSQL Outbox Pattern](#4-publishing-flow-postgresql-outbox-pattern)
5. [Legacy Publishing: Direct RabbitMQ](#5-legacy-publishing-direct-rabbitmq)
6. [Metrics Instrumentation](#6-metrics-instrumentation)
7. [Connection & Channel Pooling](#7-connection--channel-pooling)
8. [Error Handling & Classification](#8-error-handling--classification)

---

# 1. Publishing Architecture Overview

## 1.1 High-Level Publishing Flow

```
Application Code
    ↓
NanoServiceMessage (message construction)
    ↓
NanoPublisher (publish logic)
    ↓
PostgreSQL Outbox Table (default)
    ↓
pg2event Dispatcher (background worker)
    ↓
RabbitMQ (via publishToRabbit)
```

**Key Architectural Decisions:**

1. **Outbox Pattern (Default)**: Services publish to PostgreSQL, not directly to RabbitMQ
   - Transactional safety (publish atomically with DB changes)
   - Survives RabbitMQ outages
   - Reliable message delivery guarantee

2. **Direct Publishing (Legacy)**: Used only by pg2event dispatcher
   - `publishToRabbit()` method
   - With full metrics instrumentation
   - Connection pooling to prevent channel exhaustion

3. **Message Structure**: Standardized JSON format
   - `payload`: Application data
   - `meta`: Tenant context (product, env, tenant slug)
   - `status`: Processing status tracking
   - `system`: Debug mode, timestamps, consumer errors

---

## 1.2 Typical Usage Pattern

```php
// 1. Create message
$message = new NanoServiceMessage;

// 2. Add application data
$message->addPayload($this->payload);

// 3. Add tenant/context metadata
$message->addMeta($this->meta);

// 4. Enable debug mode (optional)
if ($this->isDev) {
    $message->setDebug(true);
}

// 5. Create publisher with AMQP config
$publisher = (new NanoPublisher(config('amqp')));

// 6. Set message delay (optional)
if ($this->eventDelay) {
    $publisher->delay($this->eventDelay * 1000);
}

// 7. Publish to PostgreSQL outbox
$publisher->setMessage($message)->publish($this->eventName);
```

**What happens under the hood:**
- Message is serialized to JSON
- Inserted into `pg2event.outbox` table
- pg2event worker picks it up
- Worker publishes to RabbitMQ via `publishToRabbit()`

---

# 2. Message Construction Deep Dive

## 2.1 NanoServiceMessage Class

**Location**: [src/NanoServiceMessage.php](../src/NanoServiceMessage.php)

**Inheritance**: `extends AMQPMessage`

The message object wraps AMQP message functionality with a structured JSON body.

### 2.1.1 Message Structure

**Default Structure** (from `dataStructure()` method):

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
        'created_at' => '2026-01-29 10:15:30.123',  // Timestamp with milliseconds
    ]
]
```

### 2.1.2 Constructor Flow

```php
public function __construct(array|string $data = [], array $properties = [], array $config = [])
{
    // 1. Prepare body: array → JSON or use provided string
    $body = is_array($data)
        ? json_encode(array_merge($this->dataStructure(), $data))
        : $data;

    // 2. Merge with default AMQP properties
    $properties = array_merge($this->defaultProperty(), $properties);

    // 3. Store config
    $this->config = $config;

    // 4. Call parent AMQPMessage constructor
    parent::__construct($body, $properties);
}
```

**Default AMQP Properties** (from `defaultProperty()` method):

```php
[
    'message_id' => Uuid::uuid4(),              // Unique message identifier
    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,  // Survive broker restart
]
```

### 2.1.3 Key Message Methods

#### Adding Payload

```php
$message->addPayload(['user_id' => 123, 'email' => 'test@example.com']);
```

**Internal flow**:
1. Reads current body JSON
2. Merges new payload into `payload` key
3. Re-encodes to JSON
4. Sets body

See [NanoServiceMessage.php:105-110](../src/NanoServiceMessage.php#L105-L110)

#### Adding Meta (Tenant Context)

```php
$message->addMeta([
    'product' => 'easyweek',
    'env' => 'production',
    'tenant' => 'client-slug'
]);
```

**Why meta?**
- Multi-tenant architecture
- Routing events to correct database/tenant
- Filtering events by environment (prod/staging/e2e)

See [NanoServiceMessage.php:231-236](../src/NanoServiceMessage.php#L231-L236)

#### Setting Debug Mode

```php
$message->setDebug(true);
```

**Effect**:
- Sets `system.is_debug = true` in message body
- Consumers may log additional details
- Used in dev/staging environments

See [NanoServiceMessage.php:276-281](../src/NanoServiceMessage.php#L276-L281)

### 2.1.4 Message Data Management Pattern

All data operations follow this pattern:

```php
// Read body
$bodyData = json_decode($this->getBody(), true);

// Modify data
$bodyData['section']['key'] = $value;

// Write back
$this->setBody(json_encode($bodyData));
```

**Methods**:
- `getData()`: Decode full body JSON
- `addData($key, $data)`: Merge data into section
- `getDataAttribute($attribute)`: Get section by key
- `setDataAttribute($attribute, $key, $value)`: Set nested value

---

# 3. Publisher Initialization & Configuration

## 3.1 NanoPublisher Class

**Location**: [src/NanoPublisher.php](../src/NanoPublisher.php)

**Inheritance**: `extends NanoServiceClass implements NanoPublisherContract`

### 3.2 Constructor

```php
$publisher = new NanoPublisher(config('amqp'));
```

**Internal flow**:

```php
public function __construct(array $config = [])
{
    // 1. Call parent to store config
    parent::__construct($config);

    // 2. Initialize StatsD client for metrics
    $this->statsD = new StatsDClient();
}
```

See [NanoPublisher.php:47-51](../src/NanoPublisher.php#L47-L51)

### 3.3 Configuration Sources

Configuration is resolved via the `Environment` trait:

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

**Priority** (highest to lowest):
1. `$config` array passed to constructor
2. `getenv($param, true)` - local-only env vars
3. `getenv($param)` - env vars
4. `$_ENV[$param]` - superglobal

See [src/Traits/Environment.php:11-16](../src/Traits/Environment.php#L11-L16)

### 3.4 Required Environment Variables

**For Outbox Publishing** (default `publish()`):
- `DB_HOST` - PostgreSQL host
- `DB_PORT` - PostgreSQL port
- `DB_NAME` - Database name
- `DB_USER` - Database user
- `DB_PASS` - Database password
- `DB_SCHEMA` - Schema containing outbox table (usually `pg2event`)
- `AMQP_MICROSERVICE_NAME` - Producer service identifier

**For Direct RabbitMQ Publishing** (`publishToRabbit()`):
- `AMQP_HOST` - RabbitMQ host
- `AMQP_PORT` - RabbitMQ port (5672)
- `AMQP_USER` - RabbitMQ username
- `AMQP_PASS` - RabbitMQ password
- `AMQP_VHOST` - RabbitMQ virtual host
- `AMQP_PROJECT` - Project namespace prefix
- `AMQP_MICROSERVICE_NAME` - Service name
- `AMQP_PUBLISHER_ENABLED` - Enable/disable publishing (bool)

### 3.5 Fluent Interface Methods

#### Set Message

```php
$publisher->setMessage($message);
```

Stores the NanoServiceMessage for publishing.

See [NanoPublisher.php:60-65](../src/NanoPublisher.php#L60-L65)

#### Set Meta

```php
$publisher->setMeta(['custom_key' => 'value']);
```

Add additional metadata to be merged into message meta before publishing.

See [NanoPublisher.php:53-58](../src/NanoPublisher.php#L53-L58)

#### Set Delay

```php
$publisher->delay($milliseconds);
```

For delayed messages (requires RabbitMQ delayed message exchange plugin).

⚠️ **Note**: Only works with direct RabbitMQ publishing (`publishToRabbit()`), not outbox pattern.

See [NanoPublisher.php:67-72](../src/NanoPublisher.php#L67-L72)

---

# 4. Publishing Flow: PostgreSQL Outbox Pattern

## 4.1 The Outbox Pattern

**Problem**: How to ensure events are published even if RabbitMQ is down?

**Solution**: Transactional outbox pattern

```
Application Transaction {
  1. Update application database
  2. Insert event into outbox table (same transaction)
}
↓ (committed together, atomically)
Background Worker (pg2event) {
  3. Read from outbox
  4. Publish to RabbitMQ
  5. Mark as processed
}
```

**Benefits**:
- ✅ Transactional safety (event published iff DB change committed)
- ✅ Survives RabbitMQ outages
- ✅ Guaranteed delivery (at-least-once)
- ✅ No message loss

**Trade-offs**:
- ⏱️ Slight publish delay (worker polling interval)
- 💾 Requires PostgreSQL

---

## 4.2 publish() Method - Default Outbox Publishing

**Location**: [NanoPublisher.php:94-157](../src/NanoPublisher.php#L94-L157)

```php
public function publish(string $event): void
```

### Step-by-Step Flow

#### Step 1: Validate Required Environment Variables

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

**Why?** Fail fast if configuration is incomplete.

#### Step 2: Prepare Message

```php
// Set event name (routing key)
$this->message->setEvent($event);

// Set producer service name
$this->message->set('app_id', $this->getNamespace($this->getEnv(self::MICROSERVICE_NAME)));

// Merge any additional meta set on publisher
if ($this->meta) {
    $this->message->addMeta($this->meta);
}
```

**getNamespace()** adds project prefix:
```php
public function getNamespace(string $path): string
{
    return "{$this->getProject()}.$path";  // e.g., "easyweek.myservice"
}
```

See [NanoServiceClass.php:142-145](../src/NanoServiceClass.php#L142-L145)

#### Step 3: Serialize Message Body

```php
$messageBody = $this->message->getBody();
```

**Result**: Full JSON string containing payload, meta, status, system.

**Example**:
```json
{
  "payload": {"user_id": 123, "email": "test@example.com"},
  "meta": {"product": "easyweek", "env": "production", "tenant": "client-a"},
  "status": {"code": "unknown", "data": []},
  "system": {
    "is_debug": false,
    "consumer_error": null,
    "created_at": "2026-01-29 10:15:30.123"
  }
}
```

#### Step 4: Connect to PostgreSQL

```php
$dsn = sprintf(
    "pgsql:host=%s;port=%s;dbname=%s",
    $_ENV['DB_HOST'],
    $_ENV['DB_PORT'],
    $_ENV['DB_NAME']
);

$pdo = new \PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], [
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
]);
```

**Why PDO?** Standard PHP database interface, no additional dependencies.

#### Step 5: Insert into Outbox Table

```php
$stmt = $pdo->prepare("
    INSERT INTO {$_ENV['DB_SCHEMA']}.outbox (
        producer_service,
        event_type,
        message_body,
        partition_key
    ) VALUES (?, ?, ?::jsonb, ?)
");

$stmt->execute([
    $_ENV['AMQP_MICROSERVICE_NAME'],  // e.g., "invoice-service"
    $event,                            // e.g., "invoice.paid"
    $messageBody,                      // Full NanoServiceMessage JSON
    null,                              // partition_key (optional, for ordering)
]);
```

**Outbox Table Schema**:
```sql
CREATE TABLE pg2event.outbox (
    id BIGSERIAL PRIMARY KEY,
    producer_service TEXT NOT NULL,
    event_type TEXT NOT NULL,
    message_body JSONB NOT NULL,
    partition_key TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    processed_at TIMESTAMPTZ,
    status TEXT DEFAULT 'pending'
);
```

**Columns**:
- `producer_service`: Which service created this event
- `event_type`: Routing key (e.g., `user.created`)
- `message_body`: Full NanoServiceMessage as JSONB
- `partition_key`: Optional key for ordering guarantees
- `created_at`: When event was created
- `processed_at`: When pg2event published it
- `status`: `pending` → `processed` → `archived`

#### Step 6: Error Handling

```php
try {
    // ... insert code ...
} catch (\PDOException $e) {
    throw new \RuntimeException(
        "Failed to publish to outbox table: " . $e->getMessage(),
        0,
        $e
    );
}
```

**Errors**:
- Connection failure → RuntimeException
- Invalid JSONB → RuntimeException
- Missing table → RuntimeException

Application should handle and log these exceptions.

---

## 4.3 What Happens Next?

After `publish()` returns:

1. **pg2event worker** polls outbox table:
   ```sql
   SELECT * FROM pg2event.outbox
   WHERE status = 'pending'
   ORDER BY id
   LIMIT 100;
   ```

2. **Worker publishes each event**:
   ```php
   $publisher = new NanoPublisher(config('amqp'));
   $publisher->setMessage($messageFromOutbox);
   $publisher->publishToRabbit($event);
   ```

3. **Worker marks as processed**:
   ```sql
   UPDATE pg2event.outbox
   SET status = 'processed', processed_at = NOW()
   WHERE id = ?;
   ```

4. **Archival** (optional):
   - Move old processed events to archive table
   - Delete after retention period

---

# 5. Legacy Publishing: Direct RabbitMQ

## 5.1 publishToRabbit() Method

**Location**: [NanoPublisher.php:178-261](../src/NanoPublisher.php#L178-L261)

```php
public function publishToRabbit(string $event): void
```

**When to use**:
- ❌ NOT for normal service usage
- ✅ Used by pg2event dispatcher to relay outbox events
- ✅ Legacy services still using direct publishing

**Why deprecated?**
- No transactional safety
- Messages lost if RabbitMQ is down
- Tight coupling to RabbitMQ availability

---

## 5.2 Step-by-Step Flow

### Step 1: Check if Publishing is Enabled

```php
if ((bool) $this->getEnv(self::PUBLISHER_ENABLED) !== true) {
    return;
}
```

**Environment variable**: `AMQP_PUBLISHER_ENABLED`

**Values**:
- `true` / `"true"` / `1` → Enabled
- `false` / `"false"` / `0` / unset → Disabled (no-op)

### Step 2: Prepare Message

```php
// Set event name (routing key)
$this->message->setEvent($event);

// Set producer app_id with namespace
$this->message->set('app_id', $this->getNamespace($this->getEnv(self::MICROSERVICE_NAME)));

// Add delayed delivery headers (if delay set)
if ($this->delay) {
    $this->message->set('application_headers', new AMQPTable(['x-delay' => $this->delay]));
}

// Merge publisher meta into message
if ($this->meta) {
    $this->message->addMeta($this->meta);
}
```

**Delayed messages**:
- Requires RabbitMQ `rabbitmq_delayed_message_exchange` plugin
- Header `x-delay` in milliseconds
- Exchange type must be `x-delayed-message`

### Step 3: Prepare Metrics Tags

```php
$tags = [
    'service' => $this->getEnv(self::MICROSERVICE_NAME),
    'event' => $event,
    'env' => $this->getEnvironment(),
];
```

**Tags used for all metrics**:
- `service`: Which service is publishing
- `event`: Event type (routing key)
- `env`: Environment (production, staging, e2e, local)

See [NanoPublisher.php:342-345](../src/NanoPublisher.php#L342-L345) for `getEnvironment()`.

### Step 4: Start Timing & Track Attempt

```php
// Start timer for latency tracking
$timerKey = 'publish_' . $event . '_' . uniqid();
$this->statsD->startTimer($timerKey);

// Increment total publish attempts
$sampleRate = $this->statsD->getSampleRate('ok_events');
$this->statsD->increment('rmq_publish_total', $tags, $sampleRate);
```

**Why uniqid()?**
- Multiple publishes may happen concurrently
- Unique timer key prevents collisions

### Step 5: Measure Payload Size

```php
$payloadSize = strlen($this->message->getBody());
$this->statsD->histogram(
    'rmq_payload_bytes',
    $payloadSize,
    $tags,
    $this->statsD->getSampleRate('payload')
);
```

**Metric**: `rmq_payload_bytes`
- Tracks message size distribution
- Helps identify large messages causing performance issues

### Step 6: Perform Publish

```php
$exchange = $this->getNamespace($this->exchange);  // e.g., "easyweek.bus"
$this->getChannel()->basic_publish($this->message, $exchange, $event);
```

**How `getChannel()` works**:

```php
public function getChannel()
{
    // Try shared channel first (connection pooling)
    if (self::$sharedChannel && self::$sharedChannel->is_open()) {
        return self::$sharedChannel;
    }

    // Create new channel if needed
    if (!$this->channel || !$this->channel->is_open()) {
        $this->channel = $this->getConnection()->channel();

        // Store in shared pool for reuse
        self::$sharedChannel = $this->channel;

        // Track metrics
        $this->statsD->increment('rmq_channel_total', ['service' => ..., 'status' => 'success']);
        $this->statsD->gauge('rmq_channel_active', 1, ['service' => ...]);
    }

    return $this->channel;
}
```

See [NanoServiceClass.php:157-195](../src/NanoServiceClass.php#L157-L195)

**Connection pooling critical**:
- One connection per worker process
- One channel per worker process
- Prevents channel exhaustion (see incident 2026-01-16)

### Step 7: Record Success Metrics

```php
// End timer
$duration = $this->statsD->endTimer($timerKey);

// Record latency
if ($duration !== null) {
    $this->statsD->timing(
        'rmq_publish_duration_ms',
        $duration,
        $tags,
        $this->statsD->getSampleRate('latency')
    );
}

// Increment success counter
$this->statsD->increment('rmq_publish_success_total', $tags, $sampleRate);
```

---

## 5.3 Error Handling

### Exception Categories

Publishing can fail in multiple ways. Exceptions are caught and categorized:

```php
try {
    // ... publish code ...
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
    // Generic exception - categorize by message
    $errorType = $this->categorizeException($e);
    $this->handlePublishError($e, $tags, $errorType, $timerKey);
    throw $e;
} finally {
    // Cleanup timer if not already ended
    $this->statsD->endTimer($timerKey);
}
```

See [NanoPublisher.php:237-257](../src/NanoPublisher.php#L237-L257)

### Error Types Enum

**Location**: [src/Enums/PublishErrorType.php](../src/Enums/PublishErrorType.php)

```php
enum PublishErrorType: string
{
    case CONNECTION_ERROR = 'connection_error';   // Network/connection issues
    case CHANNEL_ERROR = 'channel_error';         // Channel closed/error
    case TIMEOUT = 'timeout';                     // Operation timed out
    case ENCODING_ERROR = 'encoding_error';       // JSON serialization failed
    case CONFIG_ERROR = 'config_error';           // Missing exchange, invalid routing
    case UNKNOWN = 'unknown';                     // Uncategorized error
}
```

**Why bounded enum?**
- Prevents cardinality explosion in metrics
- Only 6 possible values for `error_type` tag
- Easy to create alerts and dashboards

### handlePublishError() Method

```php
private function handlePublishError(
    Exception $e,
    array $tags,
    PublishErrorType $errorType,
    string $timerKey
): void {
    // Add error type to tags
    $errorTags = array_merge($tags, ['error_type' => $errorType->getValue()]);

    // Track error count (always 100% sample rate for errors)
    $this->statsD->increment('rmq_publish_error_total', $errorTags, 1.0);

    // Record error duration
    $duration = $this->statsD->endTimer($timerKey);
    if ($duration !== null) {
        $errorTags['status'] = 'failed';
        $this->statsD->timing('rmq_publish_duration_ms', $duration, $errorTags, 1.0);
    }
}
```

See [NanoPublisher.php:272-289](../src/NanoPublisher.php#L272-L289)

**Key points**:
- Errors always sampled at 100% (no data loss)
- Error duration tracked separately with `status=failed` tag
- Original exception re-thrown (caller handles retry logic)

### categorizeException() Method

For generic exceptions, categorize by message content:

```php
private function categorizeException(Exception $e): PublishErrorType
{
    $message = strtolower($e->getMessage());

    // Connection errors
    if (strpos($message, 'connection') !== false ||
        strpos($message, 'socket') !== false ||
        strpos($message, 'network') !== false) {
        return PublishErrorType::CONNECTION_ERROR;
    }

    // Channel errors
    if (strpos($message, 'channel') !== false) {
        return PublishErrorType::CHANNEL_ERROR;
    }

    // Timeout errors
    if (strpos($message, 'timeout') !== false ||
        strpos($message, 'timed out') !== false) {
        return PublishErrorType::TIMEOUT;
    }

    // Encoding errors
    if (strpos($message, 'json') !== false ||
        strpos($message, 'encode') !== false ||
        strpos($message, 'serialize') !== false) {
        return PublishErrorType::ENCODING_ERROR;
    }

    // Configuration errors
    if (strpos($message, 'config') !== false ||
        strpos($message, 'exchange') !== false ||
        strpos($message, 'routing') !== false) {
        return PublishErrorType::CONFIG_ERROR;
    }

    // Unknown
    return PublishErrorType::UNKNOWN;
}
```

See [NanoPublisher.php:297-335](../src/NanoPublisher.php#L297-L335)

**Why text matching?**
- php-amqplib throws generic `AMQPRuntimeException` for many errors
- Exception message contains details about what went wrong
- Categorization enables better metrics and alerting

---

# 6. Metrics Instrumentation

## 6.1 StatsD Client

**Location**: [src/Clients/StatsDClient/StatsDClient.php](../src/Clients/StatsDClient/StatsDClient.php)

### Initialization

```php
$this->statsD = new StatsDClient();
```

Auto-configures from environment:
- `STATSD_ENABLED` - Enable/disable metrics (default: `false`)
- `STATSD_HOST` - StatsD host (default: `127.0.0.1`)
- `STATSD_PORT` - StatsD port (default: `8125`)
- `STATSD_NAMESPACE` - Metric prefix (default: service name)

**Sample rates**:
- `STATSD_SAMPLE_OK` - Success events (default: `0.1` = 10%)
- `STATSD_SAMPLE_ERROR` - Error events (default: `1.0` = 100%)
- `STATSD_SAMPLE_LATENCY` - Latency metrics (default: `0.1`)
- `STATSD_SAMPLE_PAYLOAD` - Payload size (default: `0.01` = 1%)

**Why sampling?**
- Reduce metrics volume in high-traffic services
- Errors always sampled at 100% (no data loss)
- Latency/payload sampled for cost control

---

## 6.2 Published Metrics

### rmq_publish_total

**Type**: Counter
**Tags**: `service`, `event`, `env`
**Sample rate**: `STATSD_SAMPLE_OK`

**Description**: Total publish attempts (success + failure)

**PromQL queries**:
```promql
# Publish rate by service
rate(rmq_publish_total[5m])

# Publish rate by event type
sum(rate(rmq_publish_total[5m])) by (event)
```

### rmq_publish_success_total

**Type**: Counter
**Tags**: `service`, `event`, `env`
**Sample rate**: `STATSD_SAMPLE_OK`

**Description**: Successful publishes

**PromQL queries**:
```promql
# Success rate
rate(rmq_publish_success_total[5m])

# Success ratio
rate(rmq_publish_success_total[5m])
/
rate(rmq_publish_total[5m])
```

### rmq_publish_error_total

**Type**: Counter
**Tags**: `service`, `event`, `env`, `error_type`
**Sample rate**: `1.0` (always)

**Description**: Failed publishes by error type

**PromQL queries**:
```promql
# Error rate by type
rate(rmq_publish_error_total[5m])

# Error rate by service
sum(rate(rmq_publish_error_total[5m])) by (service)

# Alert: High publish error rate
rate(rmq_publish_error_total[5m]) > 10
```

**Error types**:
- `connection_error`
- `channel_error`
- `timeout`
- `encoding_error`
- `config_error`
- `unknown`

### rmq_publish_duration_ms

**Type**: Timing (histogram)
**Tags**: `service`, `event`, `env`, `status` (optional)
**Sample rate**: `STATSD_SAMPLE_LATENCY`

**Description**: Publish latency in milliseconds

**PromQL queries**:
```promql
# P95 latency by service
histogram_quantile(0.95, rate(rmq_publish_duration_ms_bucket[5m]))

# Average latency
rate(rmq_publish_duration_ms_sum[5m])
/
rate(rmq_publish_duration_ms_count[5m])

# Alert: High latency
histogram_quantile(0.95, rate(rmq_publish_duration_ms_bucket[5m])) > 100
```

**Status tag**:
- Not set → Success
- `failed` → Error path (from `handlePublishError()`)

### rmq_payload_bytes

**Type**: Histogram
**Tags**: `service`, `event`, `env`
**Sample rate**: `STATSD_SAMPLE_PAYLOAD`

**Description**: Message payload size distribution

**PromQL queries**:
```promql
# P95 payload size
histogram_quantile(0.95, rate(rmq_payload_bytes_bucket[5m]))

# Average payload size by event
avg(rate(rmq_payload_bytes_sum[5m])) by (event)

# Alert: Large messages
histogram_quantile(0.95, rate(rmq_payload_bytes_bucket[5m])) > 1048576  # 1MB
```

**Why track?**
- Large messages slow down RabbitMQ
- Identify events that need optimization
- Capacity planning

---

## 6.3 Connection & Channel Metrics

These metrics are emitted by `NanoServiceClass` when connections/channels are created.

### rmq_connection_total

**Type**: Counter
**Tags**: `service`, `status`
**Sample rate**: `1.0`

**Description**: Connection open events

**Values**:
- `status=success` → Connected
- `status=error` → Connection failed (tracked separately in `rmq_connection_errors_total`)

See [NanoServiceClass.php:243-246](../src/NanoServiceClass.php#L243-L246)

### rmq_connection_active

**Type**: Gauge
**Tags**: `service`
**Sample rate**: `1.0`

**Description**: Active connections (0 or 1 per worker process)

**Expected value**: `1` (connection pooling ensures single shared connection)

### rmq_connection_errors_total

**Type**: Counter
**Tags**: `service`, `error_type`
**Sample rate**: `1.0`

**Description**: Connection errors

### rmq_channel_total

**Type**: Counter
**Tags**: `service`, `status`
**Sample rate**: `1.0`

**Description**: Channel open events

See [NanoServiceClass.php:176-179](../src/NanoServiceClass.php#L176-L179)

### rmq_channel_active

**Type**: Gauge
**Tags**: `service`
**Sample rate**: `1.0`

**Description**: Active channels (0 or 1 per worker process)

**Expected value**: `1` (channel pooling)

**Alert**:
```promql
# Detect channel leaks
rmq_channel_active > 1
```

### rmq_channel_errors_total

**Type**: Counter
**Tags**: `service`, `error_type`
**Sample rate**: `1.0`

**Description**: Channel errors

---

# 7. Connection & Channel Pooling

## 7.1 The Channel Exhaustion Problem

**Background**: January 16, 2026 incident (SEV2)

**What happened**:
- Service was creating new channel for each job
- Long-running worker process leaked channels
- RabbitMQ hit channel limit (17,840 channels)
- Service crashed

**Root cause**:
```php
// ❌ BAD: Creates new channel every time
public function getChannel() {
    $this->channel = $this->getConnection()->channel();
    return $this->channel;
}
```

**Impact**:
- 97% reduction in channels after fix
- From 17,840 → ~500 channels

See `incidents/2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2` in devops repo.

---

## 7.2 Solution: Static Connection Pooling

**Pattern**: One connection + one channel per worker process, shared across all instances.

```php
class NanoServiceClass
{
    // Static shared pool - ONE per worker process
    protected static ?AMQPStreamConnection $sharedConnection = null;
    protected static $sharedChannel = null;

    // Instance-specific (fallback)
    protected $connection;
    protected $channel;
}
```

### getConnection() with Pooling

```php
public function getConnection(): AMQPStreamConnection
{
    // 1. Try shared connection first
    if (self::$sharedConnection && self::$sharedConnection->isConnected()) {
        return self::$sharedConnection;
    }

    // 2. Create new connection if needed
    if (!$this->connection) {
        $this->connection = new AMQPStreamConnection(
            $this->getEnv(self::HOST),
            $this->getEnv(self::PORT),
            $this->getEnv(self::USER),
            $this->getEnv(self::PASS),
            $this->getEnv(self::VHOST),
            false,  // insist
            'AMQPLAIN',  // login_method
            null,  // login_response
            'en_US',  // locale
            10.0,  // connection_timeout
            10.0,  // read_write_timeout
            null,  // context
            true,  // keepalive
            180    // heartbeat (match RabbitMQ server config)
        );

        // 3. Store in shared pool
        self::$sharedConnection = $this->connection;

        // 4. Track metrics
        $this->statsD->increment('rmq_connection_total', [
            'service' => $this->getEnv(self::MICROSERVICE_NAME),
            'status' => 'success'
        ]);
        $this->statsD->gauge('rmq_connection_active', 1, [
            'service' => $this->getEnv(self::MICROSERVICE_NAME)
        ]);
    }

    return $this->connection;
}
```

See [NanoServiceClass.php:207-262](../src/NanoServiceClass.php#L207-L262)

### getChannel() with Pooling

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
        $this->statsD->increment('rmq_channel_total', [
            'service' => $this->getEnv(self::MICROSERVICE_NAME),
            'status' => 'success'
        ]);
        $this->statsD->gauge('rmq_channel_active', 1, [
            'service' => $this->getEnv(self::MICROSERVICE_NAME)
        ]);
    }

    return $this->channel;
}
```

See [NanoServiceClass.php:157-195](../src/NanoServiceClass.php#L157-L195)

---

## 7.3 Key Pooling Principles

### 1. One Connection Per Worker Process

**Why?**
- Worker process runs multiple jobs sequentially
- Each job may create new `NanoPublisher` instance
- All instances share same `$sharedConnection`

**Example**:
```php
// Worker loop
while ($job = $queue->pop()) {
    // NEW publisher instance
    $publisher = new NanoPublisher(config('amqp'));

    // Uses SHARED connection (no new connection created)
    $publisher->setMessage($message)->publish($event);
}
```

### 2. Never Close Shared Connections

```php
// ❌ WRONG: Closes connection needed by other jobs
$publisher->getConnection()->close();

// ✅ CORRECT: Let worker process termination close connection
// (automatic cleanup on shutdown)
```

From NanoPublisher.php:259-260:
```php
// DO NOT close shared connection - it will be reused by next job in this worker
// Connection will be closed naturally when worker process terminates
```

### 3. Heartbeat Configuration

```php
new AMQPStreamConnection(
    // ... connection params ...
    true,  // keepalive
    180    // heartbeat (must match RabbitMQ server config)
);
```

**Why heartbeat?**
- Detects stale connections (network issues, proxy restarts)
- RabbitMQ closes connection if no heartbeat received
- Client reconnects automatically

**Heartbeat check**:
```php
public function isConnectionHealthy(): bool
{
    try {
        $connection = $this->getConnection();

        if (!$connection->isConnected()) {
            $this->reset();
            return false;
        }

        // Sends heartbeat if idle > 90s (half of 180s interval)
        // Throws AMQPHeartbeatMissedException if missed > 360s (2x interval)
        $connection->checkHeartBeat();

        return true;
    } catch (\PhpAmqpLib\Exception\AMQPHeartbeatMissedException $e) {
        $this->reset();
        return false;
    }
}
```

See [NanoServiceClass.php:275-295](../src/NanoServiceClass.php#L275-L295)

### 4. Outage Circuit Breaker

For long-running workers, check connection health in loop:

```php
while (true) {
    // Check connection before processing
    if (!$consumer->ensureConnectionOrSleep(30)) {
        continue;  // Connection unhealthy, slept 30s, retry
    }

    // Process job
    $job = $queue->pop();
    // ...
}
```

**How it works**:
```php
public function ensureConnectionOrSleep(int $outageSleepSeconds): bool
{
    if (!$this->isConnectionHealthy()) {
        if (!$this->outageMode) {
            $this->outageMode = true;
            if ($this->onOutageEnter) {
                ($this->onOutageEnter)($outageSleepSeconds);  // Log outage
            }
        }
        sleep($outageSleepSeconds);
        return false;
    }

    if ($this->outageMode) {
        $this->outageMode = false;
        if ($this->onOutageExit) {
            ($this->onOutageExit)();  // Log recovery
        }
    }

    return true;
}
```

See [NanoServiceClass.php:319-340](../src/NanoServiceClass.php#L319-L340)

**Benefits**:
- Prevents error spam during RabbitMQ outages
- Automatic recovery when connection restored
- Configurable sleep interval
- Optional callbacks for logging/alerting

---

# 8. Error Handling & Classification

## 8.1 Error Categories

### CONNECTION_ERROR

**Examples**:
- Network unreachable
- Connection refused (RabbitMQ down)
- DNS lookup failed
- Socket timeout

**Exception types**:
- `AMQPConnectionClosedException`
- `AMQPIOException`

**What to do**:
- Retry after delay
- Check RabbitMQ service status
- Check network connectivity
- Check DNS resolution

### CHANNEL_ERROR

**Examples**:
- Channel closed unexpectedly
- Channel precondition failed (e.g., exchange doesn't exist)
- Channel exception

**Exception types**:
- `AMQPChannelClosedException`

**What to do**:
- Recreate channel
- Check exchange/queue configuration
- Check permissions

### TIMEOUT

**Examples**:
- Network latency
- RabbitMQ overloaded
- Slow acknowledgment

**Exception types**:
- `AMQPTimeoutException`

**What to do**:
- Increase timeout configuration
- Check RabbitMQ load
- Check network latency
- Retry

### ENCODING_ERROR

**Examples**:
- Invalid JSON in payload
- UTF-8 encoding issues
- Circular reference in object

**Exception types**:
- `\JsonException`

**What to do**:
- Validate payload before publishing
- Fix data serialization
- Check for circular references

### CONFIG_ERROR

**Examples**:
- Exchange doesn't exist
- Invalid routing key
- Missing permissions

**What to do**:
- Verify RabbitMQ configuration
- Check exchange/queue declarations
- Check user permissions

### UNKNOWN

**Examples**:
- Uncategorized errors

**What to do**:
- Investigate error message
- Add new error type if pattern emerges
- Report to nano-service maintainers

---

## 8.2 Error Handling Best Practices

### 1. Always Re-throw

Publishing errors should propagate to caller:

```php
try {
    $publisher->publish($event);
} catch (\Exception $e) {
    // Log error
    \Log::error('Publish failed', ['event' => $event, 'error' => $e->getMessage()]);

    // Re-throw for retry logic
    throw $e;
}
```

**Why?**
- Caller knows whether publish succeeded
- Enables retry logic (e.g., job queue retry)
- Preserves exception context

### 2. Monitor Error Metrics

```promql
# Alert: High publish error rate
(
  sum(rate(rmq_publish_error_total[5m])) by (service)
  /
  sum(rate(rmq_publish_total[5m])) by (service)
) > 0.05  # 5% error rate
```

### 3. Differentiate Transient vs Permanent Errors

**Transient** (retry):
- `CONNECTION_ERROR` → RabbitMQ restarting
- `TIMEOUT` → Temporary overload
- `CHANNEL_ERROR` → Stale channel

**Permanent** (don't retry):
- `ENCODING_ERROR` → Invalid payload (fix code)
- `CONFIG_ERROR` → Missing exchange (fix config)

### 4. Use Outbox Pattern

**Best practice**: Always use `publish()` (outbox pattern), not `publishToRabbit()`.

**Why?**
- Survives all error types
- Guaranteed delivery (at-least-once)
- pg2event handles retries automatically
- Transactional safety

---

## Summary: Publishing Decision Tree

```
Do you need to publish an event?
│
├─ Are you a normal service?
│  └─ Use publish() → PostgreSQL outbox
│     ✅ Transactional
│     ✅ Survives RabbitMQ outages
│     ✅ Guaranteed delivery
│
├─ Are you pg2event dispatcher?
│  └─ Use publishToRabbit() → Direct RabbitMQ
│     ⚠️  No transactional safety
│     ✅ Metrics instrumentation
│     ✅ Connection pooling
│
└─ Are you a legacy service?
   └─ Migrate to publish() → PostgreSQL outbox
      📋 Update to use outbox pattern
      📋 Deploy pg2event dispatcher
      📋 Remove publishToRabbit() usage
```

---

## Quick Reference

### Publish to Outbox (Recommended)

```php
$message = new NanoServiceMessage;
$message->addPayload(['user_id' => 123]);
$message->addMeta(['tenant' => 'client-a']);

$publisher = new NanoPublisher(config('amqp'));
$publisher->setMessage($message)->publish('user.created');
```

**Environment variables required**:
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_SCHEMA`
- `AMQP_MICROSERVICE_NAME`

### Direct Publish to RabbitMQ (Legacy)

```php
$message = new NanoServiceMessage;
$message->addPayload(['user_id' => 123]);

$publisher = new NanoPublisher(config('amqp'));
$publisher->setMessage($message)->publishToRabbit('user.created');
```

**Environment variables required**:
- `AMQP_HOST`, `AMQP_PORT`, `AMQP_USER`, `AMQP_PASS`, `AMQP_VHOST`
- `AMQP_PROJECT`, `AMQP_MICROSERVICE_NAME`
- `AMQP_PUBLISHER_ENABLED=true`
- Optional: `STATSD_ENABLED=true` (for metrics)

---

## References

- **Consuming architecture**: [ARCHITECTURE_DEEP_DIVE.md](ARCHITECTURE_DEEP_DIVE.md)
- **Metrics documentation**: [METRICS.md](../METRICS.md)
- **Configuration**: [CONFIGURATION.md](../CONFIGURATION.md)
- **Changelog**: [CHANGELOG.md](../CHANGELOG.md)
- **Incident report**: `devops/incidents/2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2`

---

**Last updated**: 2026-01-29
**Author**: Generated from source code analysis
**Package version**: 6.0+
