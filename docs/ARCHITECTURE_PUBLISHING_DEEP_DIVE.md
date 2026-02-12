# Architecture Deep Dive: Event Publishing in nano-service

> **Audience**: Developers working with or maintaining nano-service event publishing
> **Purpose**: Comprehensive understanding of event publishing architecture, flow, and internal implementation

**Last Updated**: 2026-02-09

---

## Table of Contents

1. [Publishing Architecture Overview](#1-publishing-architecture-overview)
2. [Message Construction Deep Dive](#2-message-construction-deep-dive)
3. [Publisher Initialization & Configuration](#3-publisher-initialization--configuration)
4. [Publishing Flow: Hybrid Outbox Pattern](#4-publishing-flow-hybrid-outbox-pattern)
5. [Direct RabbitMQ Publishing](#5-direct-rabbitmq-publishing)
6. [EventRepository: Database Operations with Resilience](#6-eventrepository-database-operations-with-resilience)
7. [Metrics Instrumentation](#7-metrics-instrumentation)
8. [Connection & Channel Pooling](#8-connection--channel-pooling)
9. [Error Handling & Classification](#9-error-handling--classification)

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
PostgreSQL Outbox Table (idempotency check + insert with status='processing')
    ↓
Immediate RabbitMQ Publish Attempt
    ├─ Success → Mark as 'published' → Return true
    └─ Failure → Mark as 'pending' → Return false
           ↓
    pg2event Dispatcher (retry pending events)
           ↓
    RabbitMQ (via publishToRabbit)
```

**Key Architectural Decisions:**

1. **Hybrid Outbox Pattern (Default)**: Best of both worlds - immediate + fallback
   - Idempotency check before insert (prevents duplicates)
   - Immediate RabbitMQ publish for low latency
   - Automatic fallback to dispatcher retry if RabbitMQ fails
   - Transactional safety (event never lost)
   - Survives RabbitMQ outages

2. **Direct Publishing (Legacy)**: Used by pg2event dispatcher for retries
   - `publishToRabbit()` method
   - With full metrics instrumentation
   - Connection pooling to prevent channel exhaustion

3. **Message Structure**: Standardized JSON format
   - `payload`: Application data
   - `meta`: Tenant context (product, env, tenant slug)
   - `status`: Processing status tracking
   - `system`: Debug mode, timestamps, consumer errors, trace_id

4. **EventRepository Singleton**: Centralized database operations
   - Single cached PDO connection
   - Automatic retry logic for transient failures
   - Handles outbox, inbox, and event tracing

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
    'message_id' => Uuid::uuid7(),              // Time-ordered unique identifier (v7.3.0+)
    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,  // Survive broker restart
]
```

**Note**: Since v7.3.0, nano-service uses UUID v7 instead of UUID v4:
- ✅ Time-ordered (sortable by creation time)
- ✅ Better database index locality (sequential writes)
- ✅ Reduces index fragmentation in high-throughput systems
- ✅ Backwards compatible with existing UUID v4 messages

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

#### Setting Trace ID (Distributed Tracing)

```php
$message->setTraceId(['parent-event-id-1', 'parent-event-id-2']);
```

**What is trace_id?**
- Array of parent message IDs that led to this event being published
- Enables distributed tracing across event chains
- Helps debug complex event flows and identify root causes

**Example trace chain**:
```
order.created (id: abc-123)
  ↓ consumer publishes
invoice.created (id: def-456, trace_id: ['abc-123'])
  ↓ consumer publishes
payment.requested (id: ghi-789, trace_id: ['abc-123', 'def-456'])
```

**Storage**:
- Stored in `system.trace_id` in message body
- Also stored in `event_trace` table for queryability
- Non-blocking: Trace insert failure doesn't block publishing

See [NanoServiceMessage.php:284-289](../src/NanoServiceMessage.php#L284-L289) for `setTraceId()`
See [NanoServiceMessage.php:331-336](../src/NanoServiceMessage.php#L331-L336) for `getTraceId()`

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
- `DB_BOX_HOST` - PostgreSQL host
- `DB_BOX_PORT` - PostgreSQL port
- `DB_BOX_NAME` - Database name
- `DB_BOX_USER` - Database user
- `DB_BOX_PASS` - Database password
- `DB_BOX_SCHEMA` - Schema containing outbox table (usually `pg2event`)
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

# 4. Publishing Flow: Hybrid Outbox Pattern

## 4.1 The Hybrid Outbox Pattern

**Problem**: How to ensure events are published even if RabbitMQ is down, while maintaining low latency?

**Solution**: Hybrid outbox pattern with immediate publish + fallback

```
Application publishes event {
  1. Check if message already exists (idempotency)
  2. Insert event into outbox with status='processing'
  3. Attempt immediate RabbitMQ publish
     ├─ Success: Mark as 'published', return true
     └─ Failure: Mark as 'pending', return false
}
↓ (if immediate publish failed)
Background Worker (pg2event) {
  4. Read events with status='pending' or old 'processing'
  5. Publish to RabbitMQ
  6. Mark as 'published'
}
```

**Benefits**:
- ✅ **Low latency**: Immediate publish when RabbitMQ is available
- ✅ **Reliability**: Automatic retry if RabbitMQ fails
- ✅ **Idempotency**: Duplicate detection prevents double-publishing
- ✅ **Transactional safety**: Event never lost
- ✅ **Survives RabbitMQ outages**: Dispatcher retries pending events
- ✅ **At-least-once delivery**: Better to duplicate than lose events

**Trade-offs**:
- 💾 Requires PostgreSQL
- 🔄 Possible duplicates if RabbitMQ succeeds but DB update fails (consumers must be idempotent)

---

## 4.2 publish() Method - Hybrid Outbox Publishing

**Location**: [NanoPublisher.php:133-264](../src/NanoPublisher.php#L133-L264)

```php
public function publish(string $event): bool
```

**Returns**: `true` if published to RabbitMQ successfully, `false` if RabbitMQ publish failed (dispatcher will retry)

### Step-by-Step Flow

#### Step 1: Validate Required Environment Variables

```php
$this->validateRequiredEnvironmentVariables();
```

**Checks**:
- `AMQP_MICROSERVICE_NAME` - Producer service identifier
- `DB_BOX_SCHEMA` - Database schema containing outbox table

**Why?** Fail fast if configuration is incomplete.

See [NanoPublisher.php:460-469](../src/NanoPublisher.php#L460-L469)

#### Step 2: Validate Message is Set

```php
if (!isset($this->message)) {
    throw new \RuntimeException("Message must be set before publishing. Call setMessage() first.");
}
```

**Metrics tracked**: `rmq_publisher_error_total` with `error_type=validation_error`

#### Step 3: Prepare Message

```php
$this->prepareMessageForPublish($event);
```

**Internal operations**:
```php
// Set event name (routing key)
$this->message->setEvent($event);

// Set producer service name with namespace
$this->message->set('app_id', $this->getNamespace($this->getEnv(self::MICROSERVICE_NAME)));

// Add delay headers if configured (for delayed message exchange)
if ($this->delay) {
    $this->message->set('application_headers', new AMQPTable(['x-delay' => $this->delay]));
}

// Merge any additional meta set on publisher
if ($this->meta) {
    $this->message->addMeta($this->meta);
}
```

See [NanoPublisher.php:84-96](../src/NanoPublisher.php#L84-L96)

#### Step 4: Get Message ID for Tracking

```php
$messageId = $this->message->getId();
$producerService = $_ENV['AMQP_MICROSERVICE_NAME'];

if (empty($messageId)) {
    throw new \RuntimeException("Message ID cannot be empty. Ensure message has a valid ID.");
}
```

**Why message ID?**
- Idempotency: Prevents duplicate publishes
- Tracking: Links outbox, RabbitMQ, and consumers
- Distributed tracing: Chains related events

#### Step 5: Idempotency Check

```php
$repository = EventRepository::getInstance();

if ($repository->existsInOutbox($messageId, $producerService, $schema)) {
    // Message already in outbox - return true and skip (idempotent behavior)
    return true;
}
```

**Why check first?**
- Race condition protection: Multiple threads might try to publish same event
- Retry safety: Application can safely retry publish calls
- Returns true: Event is already handled

See [EventRepository.php:372-400](../src/EventRepository.php#L372-L400)

#### Step 6: Serialize Message Body

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
    "created_at": "2026-01-29 10:15:30.123",
    "trace_id": ["parent-event-id-1", "parent-event-id-2"]
  }
}
```

#### Step 7: Insert into Outbox Table with 'processing' Status

```php
$inserted = $repository->insertOutbox(
    $producerService,        // producer_service
    $event,                  // event_type (routing key)
    $messageBody,            // message_body (full NanoServiceMessage as JSONB)
    $messageId,              // message_id (UUID for tracking)
    null,                    // partition_key (optional)
    $schema,                 // schema (e.g., 'pg2event')
    'processing'             // status (currently publishing to RabbitMQ)
);

if (!$inserted) {
    // Race condition: Another thread inserted same message_id
    return true;  // Idempotent behavior
}
```

**Why 'processing' status?**
- Indicates publish is in progress
- If process crashes, dispatcher can retry based on old timestamps
- Prevents race conditions with dispatcher

**Outbox Table Schema**:
```sql
CREATE TABLE pg2event.outbox (
    id BIGSERIAL PRIMARY KEY,
    producer_service TEXT NOT NULL,
    event_type TEXT NOT NULL,
    message_body JSONB NOT NULL,
    message_id TEXT NOT NULL UNIQUE,  -- UUID for idempotency
    partition_key TEXT,
    status TEXT DEFAULT 'pending',    -- 'processing', 'published', 'pending'
    created_at TIMESTAMPTZ DEFAULT NOW(),
    published_at TIMESTAMPTZ,
    last_error TEXT
);
```

See [EventRepository.php:211-271](../src/EventRepository.php#L211-L271)

#### Step 8: Store Event Trace (Best Effort)

```php
try {
    $traceIds = $this->message->getTraceId();
    $repository->insertEventTrace($messageId, $traceIds, $schema);
} catch (\Exception $e) {
    // Log error but don't block publishing - tracing is observability, not critical path
    $this->statsD->increment('rmq_publisher_error_total', [
        'error_type' => OutboxErrorType::TRACE_INSERT_ERROR->getValue(),
    ]);
}
```

**Event tracing**:
- Tracks distributed trace chain: which parent events led to this event
- Stored in separate `event_trace` table
- Non-blocking: Failure doesn't prevent publishing
- Used for debugging event chains and diagnosing issues

See [EventRepository.php:714-764](../src/EventRepository.php#L714-L764)

#### Step 9: Attempt Immediate RabbitMQ Publish

```php
try {
    $this->publishToRabbit($event);

    // Success! Mark as published
    $marked = $repository->markAsPublished($messageId, $schema);

    if (!$marked) {
        // CRITICAL: RabbitMQ succeeded but DB update failed
        // Return true (publish did succeed) but log critical warning
        // Accepts duplicate risk when dispatcher retries
        $this->statsD->increment('rmq_publisher_error_total', [
            'error_type' => OutboxErrorType::OUTBOX_UPDATE_ERROR->getValue(),
        ]);
    }

    return true;  // Published successfully

} catch (Exception $e) {
    // RabbitMQ publish failed - mark as pending for dispatcher retry
    $errorMessage = get_class($e) . ': ' . $e->getMessage();
    $repository->markAsPending($messageId, $schema, $errorMessage);

    return false;  // Dispatcher will retry
}
```

**Success path**:
1. `publishToRabbit()` succeeds → event in RabbitMQ
2. `markAsPublished()` updates status → 'published', sets published_at timestamp
3. Return true → caller knows publish succeeded

**Failure path**:
1. `publishToRabbit()` throws exception → RabbitMQ failed
2. `markAsPending()` updates status → 'pending', stores error message
3. Return false → caller knows to expect retry from dispatcher
4. pg2event dispatcher will pick up and retry later

See [EventRepository.php:289-312](../src/EventRepository.php#L289-312) for `markAsPublished()`
See [EventRepository.php:331-355](../src/EventRepository.php#L331-355) for `markAsPending()`

#### Step 10: Error Handling Trade-offs

**At-Least-Once Delivery Philosophy**:

The error handling is designed to **prefer duplicates over lost events**:

1. **DB insert fails (non-duplicate)**: Exception thrown → event lost (caller notified to retry)
2. **DB insert fails (duplicate key)**: Return true → idempotent (safe)
3. **RabbitMQ fails**: Mark as 'pending', return false → dispatcher retries
4. **RabbitMQ succeeds, DB update fails**: Log critical warning, return true → accept duplicate risk
5. **Status update to 'pending' fails**: Log warning, return false → dispatcher can retry based on 'processing' status

**Why this trade-off?**
- Event consumers MUST be idempotent (industry best practice)
- Better to deliver twice than never
- Critical warnings enable alerting on duplicate risk

---

## 4.3 What Happens Next?

### Scenario A: Immediate Publish Succeeds (Most Common)

1. **Application receives `true`** → Event published successfully
2. **Event visible in RabbitMQ** immediately (low latency)
3. **Outbox record has status='published'** → No further action needed
4. **Consumers receive event** within milliseconds

**Timeline**: ~10-50ms end-to-end (depending on RabbitMQ latency)

### Scenario B: Immediate Publish Fails (RabbitMQ Down/Slow)

1. **Application receives `false`** → RabbitMQ publish failed, dispatcher will retry
2. **Outbox record has status='pending'** → Queued for retry
3. **pg2event dispatcher** polls outbox table (every 1-5 seconds):
   ```sql
   SELECT * FROM pg2event.outbox
   WHERE status = 'pending'
      OR (status = 'processing' AND created_at < NOW() - INTERVAL '30 seconds')
   ORDER BY created_at
   LIMIT 100;
   ```

4. **Dispatcher publishes each event**:
   ```php
   $publisher = new NanoPublisher(config('amqp'));
   $publisher->setMessage($messageFromOutbox);
   $publisher->publishToRabbit($event);  // Retry with full metrics
   ```

5. **Dispatcher marks as published**:
   ```sql
   UPDATE pg2event.outbox
   SET status = 'published', published_at = NOW()
   WHERE message_id = ?;
   ```

6. **Archival** (optional, separate process):
   - Move old published events to archive table
   - Delete after retention period (e.g., 30 days)

**Timeline**: ~1-5 seconds delay (polling interval) + RabbitMQ latency

### Scenario C: Process Crashes Mid-Publish

1. **Application crashes** after insert but before RabbitMQ publish
2. **Outbox record has status='processing'** → Stuck
3. **pg2event dispatcher** detects stale 'processing' records:
   ```sql
   -- Events stuck in 'processing' for > 30 seconds are considered failed
   WHERE status = 'processing' AND created_at < NOW() - INTERVAL '30 seconds'
   ```
4. **Dispatcher retries** → Normal retry flow

**Why this works**: 'processing' status with old timestamp indicates crash/failure

### EventRepository Singleton

All database operations are handled by the singleton `EventRepository`:

**Key features**:
- **Single cached PDO connection**: Reused across operations
- **Automatic retry logic**: Handles transient DB failures (connection errors, deadlocks, timeouts)
- **Exponential backoff**: 100ms, 200ms, 300ms delays between retries
- **Fail-open strategy**: Prefers duplicates over lost events on persistent DB failure

See [EventRepository.php](../src/EventRepository.php) for full implementation

---

# 5. Direct RabbitMQ Publishing

## 5.1 publishToRabbit() Method

**Location**: [NanoPublisher.php:285-368](../src/NanoPublisher.php#L285-L368)

```php
public function publishToRabbit(string $event): void
```

**When to use**:
- ✅ Called internally by `publish()` for immediate publish attempt
- ✅ Used by pg2event dispatcher to relay outbox events
- ❌ NOT recommended for direct service usage (use `publish()` instead)

**Why not use directly?**
- No idempotency protection
- No transactional safety
- Messages lost if RabbitMQ is down
- No automatic retry mechanism

---

## 5.2 Step-by-Step Flow

### Step 1: Validate Required Environment Variables

```php
if (!isset($_ENV['AMQP_MICROSERVICE_NAME'])) {
    throw new \RuntimeException("Missing required environment variable: AMQP_MICROSERVICE_NAME");
}
```

**Why?** Ensures service identifier is set for metrics and tracking.

### Step 2: Validate Message is Set

```php
if (!isset($this->message)) {
    throw new \RuntimeException("Message must be set before publishing. Call setMessage() first.");
}
```

### Step 3: Prepare Message

```php
$this->prepareMessageForPublish($event);
```

**Internal operations** (see [NanoPublisher.php:84-96](../src/NanoPublisher.php#L84-L96)):
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

### Step 4: Prepare Metrics Tags

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

See [NanoPublisher.php:449-452](../src/NanoPublisher.php#L449-L452) for `getEnvironment()`.

### Step 5: Start Timing & Track Attempt

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

### Step 6: Measure Payload Size

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

### Step 7: Perform Publish

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

### Step 8: Record Success Metrics

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
    $this->reset();  // Reset connection for next attempt
    throw $e;
} catch (AMQPConnectionClosedException | AMQPIOException $e) {
    $this->handlePublishError($e, $tags, PublishErrorType::CONNECTION_ERROR, $timerKey);
    $this->reset();  // Reset connection for next attempt
    throw $e;
} catch (AMQPTimeoutException $e) {
    $this->handlePublishError($e, $tags, PublishErrorType::TIMEOUT, $timerKey);
    $this->reset();  // Reset connection for next attempt
    throw $e;
} catch (\JsonException $e) {
    $this->handlePublishError($e, $tags, PublishErrorType::ENCODING_ERROR, $timerKey);
    throw $e;  // No reset needed for encoding errors
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

**Why reset() on connection/channel/timeout errors?**
- Clears stale connection/channel state
- Forces reconnection on next publish attempt
- Prevents cascading failures with broken connections

See [NanoPublisher.php:341-364](../src/NanoPublisher.php#L341-L364)

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

See [NanoPublisher.php:379-396](../src/NanoPublisher.php#L379-L396)

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

See [NanoPublisher.php:404-442](../src/NanoPublisher.php#L404-L442)

**Why text matching?**
- php-amqplib throws generic `AMQPRuntimeException` for many errors
- Exception message contains details about what went wrong
- Categorization enables better metrics and alerting

---

# 6. EventRepository: Database Operations with Resilience

## 6.1 Singleton Pattern

**Location**: [EventRepository.php](../src/EventRepository.php)

The `EventRepository` is a singleton class that handles all database operations for the outbox/inbox patterns.

```php
$repository = EventRepository::getInstance();
```

**Key features**:
- **Single instance**: Prevents connection proliferation
- **Cached PDO connection**: Reused across all operations
- **Automatic retry logic**: Handles transient database failures
- **Fail-open strategy**: Prefers duplicates over lost events

## 6.2 Automatic Retry Mechanism

All database operations use `executeWithRetry()` for resilience:

```php
private function executeWithRetry(callable $operation, int $maxRetries = 3, int $baseDelayMs = 100)
{
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            $pdo = $this->getConnection();
            return $operation($pdo);
        } catch (\PDOException $e) {
            // Check if error is retryable
            $isRetryable = (
                // Connection errors
                stripos($errorMessage, 'connection') !== false ||
                stripos($errorMessage, 'server closed') !== false ||
                stripos($errorMessage, 'broken pipe') !== false ||
                // Deadlocks
                $errorCode === '40P01' ||
                stripos($errorMessage, 'deadlock') !== false ||
                // Lock timeouts
                stripos($errorMessage, 'lock timeout') !== false ||
                stripos($errorMessage, 'timeout') !== false
            );

            if (!$isRetryable || $attempt >= $maxRetries) {
                throw $e;
            }

            // Exponential backoff: 100ms, 200ms, 300ms
            usleep($baseDelayMs * 1000 * $attempt);

            // Reset connection on retry
            $this->connection = null;
        }
    }
}
```

**Retryable errors**:
- Connection failures (server restart, network issues)
- Deadlocks (concurrent transaction conflicts)
- Lock timeouts (table locked by other transaction)

**Non-retryable errors**:
- Constraint violations (except duplicate key for idempotency)
- Invalid SQL syntax
- Permission errors

See [EventRepository.php:99-148](../src/EventRepository.php#L99-L148)

## 6.3 Key Repository Methods

### insertOutbox()

Inserts message into outbox table with duplicate key handling:

```php
$inserted = $repository->insertOutbox(
    $producerService,  // 'invoice-service'
    $eventType,        // 'invoice.paid'
    $messageBody,      // Full JSON
    $messageId,        // UUID
    $partitionKey,     // Optional
    $schema,           // 'pg2event'
    $status            // 'processing' or 'pending'
);
```

**Returns**:
- `true`: Insert successful
- `false`: Duplicate message_id (idempotent, already exists)

**Throws**: `RuntimeException` for non-duplicate errors

See [EventRepository.php:211-271](../src/EventRepository.php#L211-L271)

### existsInOutbox()

Checks if message already exists (idempotency check):

```php
$exists = $repository->existsInOutbox($messageId, $producerService, $schema);
```

**Returns**:
- `true`: Message exists in outbox
- `false`: Message doesn't exist OR persistent DB error (fail-open)

**Fail-open behavior**: On persistent DB failure, returns `false` to allow publishing (prefers duplicate risk over blocking all events)

See [EventRepository.php:372-400](../src/EventRepository.php#L372-L400)

### markAsPublished()

Updates status to 'published' after successful RabbitMQ publish:

```php
$marked = $repository->markAsPublished($messageId, $schema);
```

**Returns**:
- `true`: Status updated successfully
- `false`: Database update failed after retries (logs error, non-blocking)

See [EventRepository.php:289-312](../src/EventRepository.php#L289-L312)

### markAsPending()

Updates status to 'pending' after failed RabbitMQ publish:

```php
$marked = $repository->markAsPending($messageId, $schema, $errorMessage);
```

**Returns**:
- `true`: Status updated successfully
- `false`: Database update failed after retries (logs error, non-blocking)

See [EventRepository.php:331-355](../src/EventRepository.php#L331-L355)

### insertEventTrace()

Stores distributed trace chain for debugging event flows:

```php
$inserted = $repository->insertEventTrace($messageId, $traceIds, $schema);
```

**Trace chain example**:
```
order.created (id: abc-123)
  ↓ triggers
invoice.created (id: def-456, trace_ids: ['abc-123'])
  ↓ triggers
payment.requested (id: ghi-789, trace_ids: ['abc-123', 'def-456'])
```

**Returns**:
- `true`: Trace inserted successfully
- `false`: Duplicate message_id (trace already exists)

**Throws**: `RuntimeException` for non-duplicate errors

See [EventRepository.php:714-764](../src/EventRepository.php#L714-L764)

## 6.4 Connection Management

### getConnection()

Returns cached PDO connection with automatic configuration:

```php
$pdo = $repository->getConnection();
```

**Configuration**:
- `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`: Throw exceptions on errors
- `PDO::ATTR_TIMEOUT => 5`: 5 second connection timeout (prevents indefinite hangs)

**Environment variables required**:
- `DB_BOX_HOST` - PostgreSQL host
- `DB_BOX_PORT` - PostgreSQL port
- `DB_BOX_NAME` - Database name
- `DB_BOX_USER` - Database user
- `DB_BOX_PASS` - Database password

See [EventRepository.php:159-193](../src/EventRepository.php#L159-L193)

## 6.5 Outbox Error Types

**Location**: [Enums/OutboxErrorType.php](../src/Enums/OutboxErrorType.php)

```php
enum OutboxErrorType: string
{
    case VALIDATION_ERROR = 'validation_error';        // Message not set, empty ID
    case TRACE_INSERT_ERROR = 'trace_insert_error';    // Event trace insert failed
    case OUTBOX_UPDATE_ERROR = 'outbox_update_error';  // Status update failed
}
```

**Why bounded enum?**
- Prevents cardinality explosion in metrics
- Only 3 possible values for `error_type` tag
- Clear categorization for monitoring

**Usage in NanoPublisher**:
```php
$this->statsD->increment('rmq_publisher_error_total', [
    'service' => $producerService,
    'error_type' => OutboxErrorType::VALIDATION_ERROR->getValue(),
]);
```

---

# 7. Metrics Instrumentation

## 7.1 StatsD Client

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

## 7.2 Published Metrics

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

## 7.3 Connection & Channel Metrics

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

# 8. Connection & Channel Pooling

## 8.1 The Channel Exhaustion Problem

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

## 8.2 Solution: Static Connection Pooling

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

## 8.3 Key Pooling Principles

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

# 9. Error Handling & Classification

## 9.1 RabbitMQ Error Categories (PublishErrorType)

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

## 9.2 Error Handling Best Practices

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
│  └─ Use publish() → Hybrid outbox pattern
│     ✅ Low latency (immediate publish when RabbitMQ available)
│     ✅ Reliability (automatic retry if RabbitMQ fails)
│     ✅ Idempotency (duplicate detection)
│     ✅ Transactional safety
│     ✅ Survives RabbitMQ outages
│     ✅ Guaranteed at-least-once delivery
│
├─ Are you pg2event dispatcher?
│  └─ Use publishToRabbit() → Direct RabbitMQ
│     ⚠️  No idempotency protection
│     ⚠️  No transactional safety
│     ✅ Full metrics instrumentation
│     ✅ Connection pooling
│     ✅ Automatic connection reset on errors
│
└─ Are you a legacy service?
   └─ Migrate to publish() → Hybrid outbox pattern
      📋 Update to use outbox pattern
      📋 Deploy pg2event dispatcher
      📋 Remove direct publishToRabbit() usage
      📋 Add idempotency handling in consumers
```

---

## Quick Reference

### Hybrid Outbox Publish (Recommended)

```php
$message = new NanoServiceMessage;
$message->addPayload(['user_id' => 123]);
$message->addMeta(['tenant' => 'client-a', 'product' => 'easyweek', 'env' => 'production']);

$publisher = new NanoPublisher(config('amqp'));
$success = $publisher->setMessage($message)->publish('user.created');

if ($success) {
    // Event published to RabbitMQ immediately
} else {
    // Event saved to outbox, pg2event dispatcher will retry
}
```

**Environment variables required**:
- `DB_BOX_HOST`, `DB_BOX_PORT`, `DB_BOX_NAME`, `DB_BOX_USER`, `DB_BOX_PASS`, `DB_BOX_SCHEMA`
- `AMQP_MICROSERVICE_NAME`, `AMQP_HOST`, `AMQP_PORT`, `AMQP_USER`, `AMQP_PASS`, `AMQP_VHOST`, `AMQP_PROJECT`
- Optional: `STATSD_ENABLED=true` (for metrics)

**Features**:
- ✅ Immediate publish when RabbitMQ available
- ✅ Automatic retry if RabbitMQ fails
- ✅ Idempotency protection
- ✅ At-least-once delivery guarantee

### Direct Publish to RabbitMQ (Internal Use Only)

```php
$message = new NanoServiceMessage;
$message->addPayload(['user_id' => 123]);

$publisher = new NanoPublisher(config('amqp'));
$publisher->setMessage($message)->publishToRabbit('user.created');
```

**When to use**:
- ❌ NOT for normal service usage
- ✅ Used internally by `publish()` method
- ✅ Used by pg2event dispatcher for retries

**Environment variables required**:
- `AMQP_HOST`, `AMQP_PORT`, `AMQP_USER`, `AMQP_PASS`, `AMQP_VHOST`
- `AMQP_PROJECT`, `AMQP_MICROSERVICE_NAME`
- Optional: `STATSD_ENABLED=true` (for metrics)

---

## References

- **Consuming architecture**: [ARCHITECTURE_CONSUMING_DEEP_DIVE.md](ARCHITECTURE_CONSUMING_DEEP_DIVE.md)
- **Metrics documentation**: [METRICS.md](../METRICS.md)
- **Configuration**: [CONFIGURATION.md](../CONFIGURATION.md)
- **Changelog**: [CHANGELOG.md](../CHANGELOG.md)
- **Incident report**: `devops/incidents/2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2`
- **Source code**:
  - [NanoPublisher.php](../src/NanoPublisher.php) - Main publisher class
  - [EventRepository.php](../src/EventRepository.php) - Database operations
  - [NanoServiceMessage.php](../src/NanoServiceMessage.php) - Message structure
  - [PublishErrorType.php](../src/Enums/PublishErrorType.php) - RabbitMQ error types
  - [OutboxErrorType.php](../src/Enums/OutboxErrorType.php) - Outbox error types

---

**Last updated**: 2026-02-09
**Author**: Generated from source code analysis (feat-3607)
**Package version**: 7.0+ (hybrid outbox pattern with immediate publish + fallback)
