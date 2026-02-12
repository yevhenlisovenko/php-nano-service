# Architecture Deep Dive: nano-service

> **Audience**: Developers working with or maintaining nano-service
> **Purpose**: Comprehensive understanding of RabbitMQ architecture decisions and internal implementation

**Last Updated**: 2026-02-09

---

## Table of Contents

1. [RabbitMQ Architecture & Design Decisions](#1-rabbitmq-architecture--design-decisions)
2. [Consumer Implementation Deep Dive](#2-consumer-implementation-deep-dive)
3. [Message Processing Lifecycle](#3-message-processing-lifecycle)

---

# 1. RabbitMQ Architecture & Design Decisions

## 1.1 Core RabbitMQ Concepts

### Basic Message Flow

```
Publisher → Exchange → Queue → Consumer
```

**Key Components:**

1. **Producer/Publisher**
   - Service that sends messages
   - Doesn't send directly to queues
   - Sends to exchanges with a **routing key**

2. **Exchange**
   - Message router (like a post office)
   - Receives messages and routes them to queues
   - Uses routing keys and bindings to decide where to send

3. **Queue**
   - Message buffer (like a mailbox)
   - Stores messages until consumed
   - FIFO (First In, First Out)

4. **Binding**
   - Link between exchange and queue
   - Defines routing rules

5. **Routing Key**
   - Message "address" (e.g., `"user.created"`, `"invoice.paid"`)
   - Exchange uses this to decide which queue(s) receive the message

---

## 1.2 Exchange Types

### Direct Exchange
```
Routing key must EXACTLY match binding key
```

**Example:**
```
Message: routing_key = "error"
Binding: queue_A bound with "error" ✅ receives
Binding: queue_B bound with "info"  ❌ doesn't receive
```

**Use case:** Send to specific queue by name

---

### Fanout Exchange
```
Ignores routing key, sends to ALL bound queues
```

**Example:**
```
Message: routing_key = "anything"
Binding: queue_A ✅ receives
Binding: queue_B ✅ receives
Binding: queue_C ✅ receives
```

**Use case:** Broadcast (e.g., cache invalidation to all services)

---

### Topic Exchange ⭐ (Used by this package)
```
Pattern matching with wildcards:
* = exactly one word
# = zero or more words
```

**Example:**
```
Message: "user.created"
Binding: "user.*"        ✅ matches (user.created, user.deleted)
Binding: "user.#"        ✅ matches (user.created, user.profile.updated)
Binding: "invoice.*"     ❌ doesn't match
Binding: "#"             ✅ matches everything
```

**Why topic?**
- Flexible routing patterns
- Services subscribe to event categories
- One message can go to multiple queues

---

### Headers Exchange
```
Routes based on message headers instead of routing key
```

**Use case:** Complex routing with multiple criteria

---

### x-delayed-message ⭐ (Used by this package for retries)
```
Plugin that delays message delivery
```

**Example:**
```php
// Publish with delay header
$headers = ['x-delay' => 5000]; // 5 seconds
// Message sits in exchange for 5 seconds, then delivered
```

**Why delayed?**
- Retry backoff without external schedulers
- No need for timed jobs or cron

---

## 1.3 This Package's Architecture

### Event Bus Pattern

```
┌─────────────────────────────────────────────────────────────────┐
│                    CENTRAL EVENT BUS                             │
│  Exchange: "easyweek.bus" (topic exchange)                      │
│  - All services publish here                                     │
│  - Routes events by routing key patterns                         │
└──────────────┬──────────────────────────────────────────────────┘
               │
               ├─────> invoice-service queue (binds: invoice.*)
               ├─────> user-service queue (binds: user.*)
               ├─────> notification-service queue (binds: #)
               └─────> elasticsearch queue (binds: *.created, *.updated)
```

### Complete Architecture for One Service

Using `invoice-service` as example:

```
┌──────────────────────────────────────────────────────────────────┐
│ 1. CENTRAL BUS EXCHANGE                                          │
│    Name: "easyweek.bus"                                          │
│    Type: topic                                                   │
│    Purpose: All services publish events here                     │
└─────────────┬────────────────────────────────────────────────────┘
              │
              │ Routes by event name (routing key)
              ↓
┌──────────────────────────────────────────────────────────────────┐
│ 2. MAIN QUEUE                                                    │
│    Name: "easyweek.invoice-service"                              │
│    Config: x-dead-letter-exchange = "easyweek.invoice-service.failed"│
│    Purpose: Receives events for processing                       │
└─────────────┬────────────────────────────────────────────────────┘
              │
              │ Consumer processes message
              ↓
┌─────────────┴─────────────┬────────────────────────────────────┐
│                           │                                    │
│ SUCCESS                   │ FAILURE                            │
│ ack()                     │                                    │
│ Message deleted           │                                    │
│                           ↓                                    │
│              ┌────────────────────────────┐                    │
│              │ 3. DELAYED EXCHANGE        │                    │
│              │ Name: "easyweek.invoice-*" │                    │
│              │ Type: x-delayed-message    │                    │
│              │ Purpose: Retry with delay  │                    │
│              └────────────┬───────────────┘                    │
│                           │ After delay                        │
│                           │ (1s → 5s → 60s)                    │
│                           ↓                                    │
│              ┌────────────────────────────┐                    │
│              │ Back to MAIN QUEUE         │                    │
│              │ (binding: queue → queue)   │                    │
│              └────────────────────────────┘                    │
│                           │                                    │
│                           │ Max retries exceeded?              │
│                           ↓                                    │
│              ┌────────────────────────────┐                    │
│              │ 4. FAILED QUEUE (DLX)      │                    │
│              │ Name: "easyweek.inv*.failed"│                    │
│              │ Purpose: Manual review     │                    │
│              └────────────────────────────┘                    │
└───────────────────────────────────────────────────────────────┘
```

---

## 1.4 Design Decisions & Rationale

### Decision 1: Topic Exchange for Event Bus

**Choice:** Central `"easyweek.bus"` topic exchange

**Why?**
1. **Event-driven architecture**: Services react to events like `user.created`, `invoice.paid`
2. **Flexible subscriptions**:
   - `invoice-service` subscribes to `invoice.*`
   - `elasticsearch` subscribes to `*.created`, `*.updated`
   - `audit-service` subscribes to `#` (everything)
3. **Decoupling**: Publishers don't know who consumes
4. **Scalability**: Easy to add new consumers without changing publishers

**Alternative rejected:** Direct exchange
- Problem: Publisher must know queue names
- Problem: No pattern matching (can't subscribe to `user.*`)

---

### Decision 2: One Queue Per Microservice

**Choice:** Each service has its own queue (`easyweek.invoice-service`)

**Why?**
1. **Isolation**: One service's failures don't affect others
2. **Independent scaling**: Scale consumers per service
3. **Clear ownership**: Queue name = service name
4. **Independent retry logic**: Each service sets its own `tries` and `backoff`

**Code:**
```php
// Queue name from environment
$queue = $this->getEnv(self::MICROSERVICE_NAME); // "invoice-service"
$queue = $this->getNamespace($queue); // "easyweek.invoice-service"
```

**Alternative rejected:** Shared queue
- Problem: All services compete for same messages
- Problem: Can't have different retry strategies

---

### Decision 3: Delayed Exchange for Retries

**Choice:** Use `x-delayed-message` exchange plugin

**Why?**
1. **Built into RabbitMQ**: No external scheduler needed
2. **Automatic retry**: Failed messages automatically retried after delay
3. **Exponential backoff**: `[1, 5, 60]` seconds delays
4. **Simplicity**: No cron jobs or background workers

**How it works:**
```php
// On failure (NanoConsumer.php:223-228)
$headers = new AMQPTable([
    'x-delay' => $this->getBackoff($retryCount), // 1000, 5000, 60000 ms
    'x-retry-count' => $retryCount
]);
$this->getChannel()->basic_publish($newMessage, $this->queue, $key);
```

**Alternative rejected:** Scheduled tasks
- Problem: Requires external scheduler (cron, Kubernetes CronJob)
- Problem: More infrastructure complexity

---

### Decision 4: Dead Letter Exchange (DLX) for Failed Messages

**Choice:** Separate `.failed` queue with DLX routing

**Why?**
1. **No message loss**: Failed messages preserved for analysis
2. **Automatic routing**: RabbitMQ handles it (no code)
3. **Alerting**: Monitor DLX queue depth for alerts
4. **Manual recovery**: Admins can reprocess or delete

**Code:**
```php
// Main queue configured with DLX (NanoConsumer.php:83-85)
$this->queue($queue, new AMQPTable([
    'x-dead-letter-exchange' => $dlx, // "easyweek.invoice-service.failed"
]));
```

**What happens:**
- Message fails 3 times
- RabbitMQ automatically routes to DLX
- Stored in `.failed` queue
- Admin investigates: bug? data issue? external API down?

**Alternative rejected:** Discard failed messages
- Problem: Data loss
- Problem: No visibility into failures

---

### Decision 5: Namespacing with Project Prefix

**Choice:** Prefix all names with project (`easyweek.`)

**Why?**
1. **Multi-tenancy**: Multiple projects on same RabbitMQ cluster
2. **Isolation**: `easyweek.invoice-service` vs `otherproject.invoice-service`
3. **Clear ownership**: Know which project owns which resources
4. **Avoid collisions**: Two teams can use same service names

**Code:**
```php
public function getNamespace(string $path): string
{
    return "{$this->getProject()}.$path"; // "easyweek.invoice-service"
}
```

**Reference:** [NanoServiceClass.php:142-144](../src/NanoServiceClass.php#L142-L144)

---

### Decision 6: Durable Queues and Messages

**Choice:** Queues are `durable = true`

**Why?**
1. **Survive RabbitMQ restarts**: Queues recreated after crash
2. **Message persistence**: Messages written to disk
3. **No data loss**: Critical for production

**Trade-off:**
- Slower than in-memory queues
- Acceptable for event-driven architecture (not high-frequency trading)

**Reference:** [NanoServiceClass.php:117](../src/NanoServiceClass.php#L117)

---

### Decision 7: QoS Prefetch = 1

**Choice:** `basic_qos(0, 1, 0)`

**Why?**
1. **Fair distribution**: Each worker gets 1 message at a time
2. **Prevent overload**: Slow consumers don't get flooded
3. **Horizontal scaling**: Add more workers to process faster

**Alternative:** Prefetch = 10
- Problem: One slow message blocks 9 others
- Problem: Uneven distribution (fast worker idle, slow worker overloaded)

**Reference:** [NanoConsumer.php:125](../src/NanoConsumer.php#L125)

---

### Decision 8: Manual Acknowledgment

**Choice:** `no_ack = false`

**Why?**
1. **Reliability**: Message not deleted until explicitly ack'd
2. **Retry on failure**: Failed messages can be retried
3. **No loss on crash**: Un-acked messages redelivered

**How it works:**
```php
try {
    call_user_func($callback, $message);
    $message->ack(); // Only ACK on success
} catch (Throwable $e) {
    // Republish for retry, THEN ack original
}
```

**Alternative:** Auto-acknowledge
- Problem: Message deleted before processing
- Problem: Crash = data loss

**Reference:** [NanoConsumer.php:126](../src/NanoConsumer.php#L126)

---

## 1.5 Real-World Example: Invoice Payment Flow

### Scenario

**Services:**
- `invoice-service` - Creates invoices
- `payment-service` - Processes payments
- `notification-service` - Sends emails
- `elasticsearch` - Indexes for search

**Architecture:**

```
1. PUBLISHER (invoice-service)
   ↓
   publisher->publish("invoice.paid")
   ↓
2. CENTRAL BUS ("easyweek.bus")
   ↓
   Routes to all subscribers:
   ├─> payment-service queue (binds: "invoice.*")
   ├─> notification-service queue (binds: "#")
   └─> elasticsearch queue (binds: "*.paid")

3. CONSUMERS
   Each service processes independently:

   payment-service:
   - Receives "invoice.paid"
   - Updates accounting
   - If fails → retry 3x with backoff
   - Max retries → payment-service.failed queue

   notification-service:
   - Receives "invoice.paid"
   - Sends email to user
   - If fails → retry, eventually → notification-service.failed

   elasticsearch:
   - Receives "invoice.paid"
   - Updates search index
   - Independent retry logic
```

**Benefits of this architecture:**
1. **Decoupled**: invoice-service doesn't know who listens
2. **Resilient**: Each service has independent retries
3. **Observable**: Metrics on each queue depth, retry rates
4. **Scalable**: Add more consumers per queue as needed

---

## 1.6 Anti-Patterns to Avoid

### ❌ Direct Queue Publishing
```php
// Anti-pattern
$publisher->publishToQueue("payment-service"); // Don't do this
```

**Problems:**
- Publisher knows consumer names (tight coupling)
- Can't add new consumers without changing code
- No broadcast capability

---

### ❌ Shared Queue
```php
// Anti-pattern
$consumer1->events('user.*')->queue('shared-queue');
$consumer2->events('invoice.*')->queue('shared-queue');
```

**Problems:**
- Services compete for messages
- No isolation (one service's failure affects another)
- Can't scale independently

---

### ❌ Synchronous REST Calls
```php
// Anti-pattern
HTTP::post('http://payment-service/invoice-paid', $data); // Blocking!
```

**Problems:**
- Synchronous = slow (wait for response)
- Cascading failures (payment-service down = invoice-service blocked)
- No retry logic
- No buffering (spike = overload)

---

## 1.7 Summary: Why This Architecture?

| Requirement | Solution | Benefit |
|------------|----------|---------|
| Event-driven | Topic exchange | Flexible routing patterns |
| Decoupling | Central bus | Publishers don't know consumers |
| Reliability | Manual ACK + DLX | No message loss |
| Retries | Delayed exchange | Automatic backoff |
| Isolation | Queue per service | Independent scaling/retries |
| Multi-tenancy | Namespacing | Multiple projects, no collisions |
| Resilience | Durable queues | Survive RabbitMQ restarts |
| Fair distribution | QoS prefetch=1 | Even load across workers |

This architecture is **production-ready for event-driven microservices** at scale.

---

# 2. Consumer Implementation Deep Dive

## 2.1 High-Level Usage

### Typical Consumer Usage

```php
use AlexFN\NanoService\NanoConsumer;

$consumer = new NanoConsumer();
$consumer
    ->events('invoice.created', 'invoice.updated')  // Events to listen for
    ->backoff([1, 5, 60])                           // Retry delays in seconds
    ->tries(3)                                       // Max retry attempts
    ->consume(function (NanoServiceMessage $message) {
        // Your business logic here
        echo "Processing: " . $message->getEventName() . "\n";
    });
```

**What happens:**
1. Consumer connects to RabbitMQ
2. Creates/binds queue to events
3. Starts infinite loop waiting for messages
4. Calls your callback for each message
5. Handles retries and failures automatically

---

## 2.2 The `consume()` Method: Entry Point with Circuit Breaker

### Method Signature

```php
public function consume(callable $callback, ?callable $debugCallback = null): void
```

**Reference:** [NanoConsumer.php:208-280](../src/NanoConsumer.php#L208-L280)

### Parameters

- `$callback` - Your main message handler function (required)
- `$debugCallback` - Optional debug handler (used when message has debug flag)

### Return Type

- `void` - This method runs indefinitely in a **blocking loop with circuit breaker**
- Never returns unless killed or error occurs

---

## 2.3 Consumer Implementation Architecture

### Two-Phase Initialization Pattern

The consumer uses a two-phase initialization to handle RabbitMQ outages gracefully:

#### Phase 1: Safe Components (`initSafeComponents()`)

**Reference:** [NanoConsumer.php:72-101](../src/NanoConsumer.php#L72-L101)

```php
private function initSafeComponents(): void
{
    // 1. Validate required environment variables BEFORE starting
    if (!isset($_ENV['AMQP_MICROSERVICE_NAME'])) {
        throw new \RuntimeException("Missing required environment variables: AMQP_MICROSERVICE_NAME");
    }
    if (!isset($_ENV['DB_BOX_SCHEMA'])) {
        throw new \RuntimeException("Missing required environment variables: DB_BOX_SCHEMA");
    }

    // 2. Initialize StatsD client for metrics
    if (!$this->statsD) {
        $this->statsD = new StatsDClient();
    }

    // 3. Initialize logger
    if (!isset($this->logger)) {
        $this->logger = LoggerFactory::getInstance();
    }

    // 4. Initialize message validator
    if (!isset($this->messageValidator)) {
        $this->messageValidator = new MessageValidator(
            $this->statsD,
            $this->logger,
            $this->getEnv(self::MICROSERVICE_NAME)
        );
    }
}
```

**Key characteristics:**
- ✅ No RabbitMQ connection required
- ✅ Validates environment before starting
- ✅ Safe to call multiple times (idempotent)
- ✅ Can initialize even if RabbitMQ is down

#### Phase 2: RabbitMQ Resources (`initRabbitMQ()`)

**Reference:** [NanoConsumer.php:111-127](../src/NanoConsumer.php#L111-L127)

```php
private function initRabbitMQ(): void
{
    // 1. Create main queue + DLX queue + delayed exchange
    $this->initialWithFailedQueue();

    // 2. Bind your events to the queue
    $exchange = $this->getNamespace($this->exchange); // "easyweek.bus"
    foreach ($this->events as $event) {
        $this->getChannel()->queue_bind($this->queue, $exchange, $event);
    }

    // 3. Bind system events (like system.ping.1)
    foreach (array_keys($this->handlers) as $systemEvent) {
        $this->getChannel()->queue_bind($this->queue, $exchange, $systemEvent);
    }

    $this->rabbitMQInitialized = true;
}
```

**Key characteristics:**
- ❌ Requires active RabbitMQ connection
- ✅ Idempotent - safe to call multiple times (RabbitMQ declarations are idempotent)
- ✅ Sets `$rabbitMQInitialized` flag to track state

**See Section 2.4 for detailed explanation of `initialWithFailedQueue()`**

---

### Circuit Breaker Pattern for RabbitMQ Outages

The `consume()` method implements a resilient consumption pattern that survives RabbitMQ outages:

**Reference:** [NanoConsumer.php:208-280](../src/NanoConsumer.php#L208-L280)

```php
public function consume(callable $callback, ?callable $debugCallback = null): void
{
    $this->callback = $callback;
    $this->debugCallback = $debugCallback;

    // Phase 1: Initialize safe components (no RabbitMQ needed)
    $this->initSafeComponents();

    // Phase 2: Set up circuit breaker callbacks
    $this->setOutageCallbacks(
        fn(int $sleepSeconds) => $this->logger->warning('[NanoConsumer] Entering outage mode', [
            'sleep_seconds' => $sleepSeconds,
            'microservice' => $this->getEnv(self::MICROSERVICE_NAME),
        ]),
        fn() => $this->logger->info('[NanoConsumer] Connection restored', [
            'microservice' => $this->getEnv(self::MICROSERVICE_NAME),
        ])
    );

    // Phase 3: Main consumption loop with circuit breaker
    while (true) {
        try {
            // Check connection health
            if (!$this->ensureConnectionOrSleep($this->outageSleepSeconds)) {
                continue;
            }

            // Initialize RabbitMQ resources (queues, bindings)
            if (!$this->rabbitMQInitialized) {
                $this->initRabbitMQ();
            }

            // Set up consumption
            $this->getChannel()->basic_qos(0, 1, 0);
            $this->getChannel()->basic_consume(
                $this->queue,
                $this->getEnv(self::MICROSERVICE_NAME),
                false, false, false, false,
                [$this, 'consumeCallback']
            );

            // Register shutdown handler (once)
            if (!self::$shutdownRegistered) {
                register_shutdown_function([$this, 'shutdown'], $this->getChannel(), $this->getConnection());
                self::$shutdownRegistered = true;
            }

            // Start blocking consumption
            $this->getChannel()->consume();

        } catch (\PhpAmqpLib\Exception\AMQPHeartbeatMissedException $e) {
            $this->handleRabbitMQError($e, ConsumerErrorType::CONNECTION_ERROR);
        } catch (\PhpAmqpLib\Exception\AMQPConnectionClosedException $e) {
            $this->handleRabbitMQError($e, ConsumerErrorType::CONNECTION_ERROR);
        } catch (\PhpAmqpLib\Exception\AMQPChannelClosedException $e) {
            $this->handleRabbitMQError($e, ConsumerErrorType::CHANNEL_ERROR);
        } catch (\PhpAmqpLib\Exception\AMQPSocketException $e) {
            $this->handleRabbitMQError($e, ConsumerErrorType::IO_ERROR);
        } catch (\PhpAmqpLib\Exception\AMQPIOException $e) {
            $this->handleRabbitMQError($e, ConsumerErrorType::IO_ERROR);
        } catch (\PhpAmqpLib\Exception\AMQPRuntimeException $e) {
            $this->handleRabbitMQError($e, ConsumerErrorType::CONSUME_SETUP_ERROR);
        } catch (\Throwable $e) {
            // Non-RabbitMQ error - crash consumer
            $this->logger->critical('[NanoConsumer] Unexpected error, crashing', [
                'microservice' => $this->getEnv(self::MICROSERVICE_NAME),
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);
            throw $e;
        }
    }
}
```

**Circuit breaker flow:**

```
1. Initialize safe components (ENV, logger, StatsD, validator)
2. Set up outage callbacks
3. Enter infinite loop:
   ├─> Check RabbitMQ health (ensureConnectionOrSleep)
   │   ├─> If DOWN: log, sleep 30s, continue loop
   │   └─> If UP: proceed
   ├─> Initialize RabbitMQ (if not already initialized)
   ├─> Set up QoS and consumption
   ├─> Start consuming messages
   └─> On RabbitMQ error:
       ├─> Log error
       ├─> Track metric (rmq_consumer_error_total)
       ├─> Reset connection state
       ├─> Sleep 2s
       └─> Continue loop (reconnect)
```

**Benefits:**
- ✅ Survives RabbitMQ outages (retries every 30s)
- ✅ Doesn't crash on connection loss
- ✅ Logs outage mode entry/exit
- ✅ Tracks error metrics
- ✅ Only crashes on non-RabbitMQ errors (let orchestration restart)

---

### RabbitMQ Error Handling

**Reference:** [NanoConsumer.php:1018-1039](../src/NanoConsumer.php#L1018-L1039)

```php
private function handleRabbitMQError(\Throwable $e, ConsumerErrorType $errorType): void
{
    $this->logger->error('[NanoConsumer] RabbitMQ error, will retry', [
        'microservice' => $this->getEnv(self::MICROSERVICE_NAME),
        'error' => $e->getMessage(),
        'error_class' => get_class($e),
        'error_type' => $errorType->getValue(),
    ]);

    // Track connection error metric
    $this->statsD->increment('rmq_consumer_error_total', [
        'nano_service_name' => $this->getEnv(self::MICROSERVICE_NAME),
        'error_type' => $errorType->getValue(),
    ]);

    // Reset connection state (forces reconnection on next iteration)
    $this->reset();
    $this->rabbitMQInitialized = false;

    // Brief pause before retry (let connection state settle)
    sleep(2);
}
```

**Error types tracked** ([ConsumerErrorType.php](../src/Enums/ConsumerErrorType.php)):
- `CONNECTION_ERROR` - Connection lost, heartbeat missed
- `CHANNEL_ERROR` - Channel closed
- `IO_ERROR` - Network/socket errors
- `CONSUME_SETUP_ERROR` - Other RabbitMQ runtime errors

---

### Line 122-123: Store Callbacks

```php
$this->callback = $callback;
$this->debugCallback = $debugCallback;
```

**Purpose:**
- Stores your callback functions as instance properties
- Used later in `consumeCallback()` when messages arrive

**Why two callbacks?**
- `$callback` - Normal message processing
- `$debugCallback` - Special handler for messages with debug flag
- Debug messages can be routed to logging/monitoring without processing

---

### Line 125: `$this->getChannel()->basic_qos(0, 1, 0);`

**Sets Quality of Service (QoS) for the RabbitMQ channel**

**Parameters explained:**
```php
basic_qos(
    $prefetch_size,   // 0 = no limit on message size
    $prefetch_count,  // 1 = process only 1 message at a time
    $global           // 0/false = apply per consumer (not per channel)
)
```

**Why `prefetch_count = 1`?**
1. **Fair distribution**: Each worker processes one message at a time
2. **Prevents overload**: Slow consumers don't get flooded with messages
3. **Horizontal scaling**: Add more workers to increase throughput

**Example scenario:**
```
Without QoS (prefetch unlimited):
Worker A: Processing 10 messages (slow)
Worker B: Idle (no messages left)

With QoS (prefetch=1):
Worker A: Processing 1 message
Worker B: Processing 1 message
Queue: 8 messages waiting
→ Fair distribution!
```

---

### Line 126: `$this->getChannel()->basic_consume(...)`

**Registers the consumer with RabbitMQ**

**Full signature:**
```php
$this->getChannel()->basic_consume(
    $queue,           // "easyweek.invoice-service"
    $consumer_tag,    // "invoice-service" (identifier)
    $no_local,        // false (not used in RabbitMQ)
    $no_ack,          // false = MANUAL acknowledgment (you control when ACKed)
    $exclusive,       // false = allow multiple consumers
    $nowait,          // false = wait for server response
    $callback         // [$this, 'consumeCallback'] = method to call for each message
);
```

**Critical parameter: `$no_ack = false`**
- Manual acknowledgment mode
- Message stays in queue until explicitly ack'd
- If consumer crashes before ACK → message redelivered
- Prevents data loss

**The callback:**
- Points to `[$this, 'consumeCallback']`
- RabbitMQ calls this method for EACH incoming message
- See Section 2.5 for detailed explanation

---

### Line 127: `register_shutdown_function(...)`

**Registers cleanup function for graceful shutdown**

```php
register_shutdown_function(
    [$this, 'shutdown'],      // Method to call
    $this->getChannel(),      // Pass channel
    $this->getConnection()    // Pass connection
);
```

**Why this is critical:**
- Closes channel and connection gracefully
- Prevents connection leaks when process terminates
- Called automatically on: exit(), die(), fatal error, SIGTERM

**Shutdown method ([NanoConsumer.php:266-270](../src/NanoConsumer.php#L266-L270)):**
```php
public function shutdown(): void
{
    $this->getChannel()->close();
    $this->getConnection()->close();
}
```

**See also:** [incidents/2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2](../incidents/2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2) for why proper cleanup is critical

---

### Line 128: `$this->getChannel()->consume();`

**Starts the blocking consumption loop**

**What this does:**
- Enters infinite loop
- Waits for messages from RabbitMQ
- Calls `consumeCallback()` for each received message
- **BLOCKS FOREVER** until process is killed or error occurs

**Important:**
- This is why `consume()` never returns
- Your script execution stops here
- All subsequent code runs in callbacks only

**Behind the scenes (php-amqplib internals):**
```php
while (count($this->callbacks)) {
    $this->wait();  // Wait for RabbitMQ to send message
    // When message arrives → call registered callback
}
```

---

## 2.4 Dead Letter Exchange Setup: `initialWithFailedQueue()`

### Complete Method

**Reference:** [NanoConsumer.php:78-92](../src/NanoConsumer.php#L78-L92)

```php
private function initialWithFailedQueue(): void
{
    // 1. Get queue name from environment
    $queue = $this->getEnv(self::MICROSERVICE_NAME); // "invoice-service"

    // 2. Build DLX name
    $dlx = $this->getNamespace($queue).self::FAILED_POSTFIX;
    // Result: "easyweek.invoice-service.failed"

    // 3. Create main queue with DLX configuration
    $this->queue($queue, new AMQPTable([
        'x-dead-letter-exchange' => $dlx,
    ]));

    // 4. Create delayed message exchange for retries
    $this->createExchange($this->queue, 'x-delayed-message', new AMQPTable([
        'x-delayed-type' => 'topic',
    ]));

    // 5. Create the failed queue (DLX destination)
    $this->createQueue($dlx);

    // 6. Bind main queue to delayed exchange
    $this->getChannel()->queue_bind($this->queue, $this->queue, '#');
}
```

### Line-by-Line Deep Dive

#### Line 80: Get Queue Name

```php
$queue = $this->getEnv(self::MICROSERVICE_NAME);
```

- Gets microservice name from environment variable `AMQP_MICROSERVICE_NAME`
- Example: `"invoice-service"`
- This becomes your main queue name (before namespacing)

---

#### Line 81: Build DLX Name

```php
$dlx = $this->getNamespace($queue).self::FAILED_POSTFIX;
```

**What happens:**
1. `getNamespace()` adds project prefix: `"easyweek.invoice-service"`
2. `FAILED_POSTFIX` is `".failed"` constant
3. Result: `"easyweek.invoice-service.failed"`

**What is DLX (Dead Letter Exchange)?**
- Special RabbitMQ exchange where **rejected/failed messages** are sent
- When a message exceeds max retries, it goes here instead of being lost
- Automatic routing by RabbitMQ (no code needed)

---

#### Line 83-85: Create Main Queue with DLX

```php
$this->queue($queue, new AMQPTable([
    'x-dead-letter-exchange' => $dlx,
]));
```

**What this does:**

1. **Calls `queue()` method** ([NanoServiceClass.php:110-115](../src/NanoServiceClass.php#L110-L115))
   - Adds namespace: `"invoice-service"` → `"easyweek.invoice-service"`
   - Stores in `$this->queue` property

2. **Creates queue with special property:**
   - Queue name: `"easyweek.invoice-service"`
   - **`x-dead-letter-exchange`**: Tells RabbitMQ "when a message is rejected, send it to this exchange"
   - DLX value: `"easyweek.invoice-service.failed"`

3. **Under the hood** ([NanoServiceClass.php:117-122](../src/NanoServiceClass.php#L117-L122)):
   ```php
   $this->getChannel()->queue_declare(
       $queue,               // "easyweek.invoice-service"
       $passive = false,     // Create if doesn't exist
       $durable = true,      // ✅ Survive RabbitMQ restarts
       $exclusive = false,   // Allow multiple consumers
       $auto_delete = false, // Don't delete when consumers disconnect
       $nowait = false,      // Wait for server response
       $arguments            // ['x-dead-letter-exchange' => 'easyweek.invoice-service.failed']
   );
   ```

**Why durable = true?**
- Queue survives RabbitMQ server restarts
- Messages are persisted to disk
- Critical for production (no data loss on restart)

---

#### Line 86-88: Create Delayed Exchange for Retries

```php
$this->createExchange($this->queue, 'x-delayed-message', new AMQPTable([
    'x-delayed-type' => 'topic',
]));
```

**Creates a special exchange for delayed retries**

**Parameters:**
1. **Exchange name**: `"easyweek.invoice-service"` (same as queue name)
2. **Type**: `'x-delayed-message'` - RabbitMQ Delayed Message Plugin
3. **Arguments**: `x-delayed-type: 'topic'` - When delay expires, route as topic exchange

**What is x-delayed-message?**
- RabbitMQ plugin that allows scheduling messages to be delivered later
- Messages are held in the exchange for a specified delay
- After delay expires, routed to bound queues

**Why delayed exchange?**
- Used for retry backoffs: `[1, 5, 60]` seconds
- No external scheduler needed (cron, Kubernetes CronJob)
- Automatic retry handling

**How retries work:**
```php
// When message fails (retry 1/3)
$headers = ['x-delay' => 1000]; // 1 second delay
$this->getChannel()->basic_publish($message, $exchange, $routingKey);

// Exchange holds message for 1 second
// After 1 second → delivers to queue again
// Consumer processes → fails again
// Retry 2/3 with 5 second delay
// ... and so on
```

**See:** [NanoConsumer.php:223-228](../src/NanoConsumer.php#L223-L228) for retry implementation

---

#### Line 90: Create Failed Queue

```php
$this->createQueue($dlx);
```

**Creates the dead-letter queue**

**Properties:**
- Queue name: `"easyweek.invoice-service.failed"`
- Where messages go **after max retries exceeded**
- No DLX on this queue (it's the final destination)
- Messages stay here until manually processed or deleted

**Use cases:**
- Admin investigates why messages failed
- Bug fixes applied, then messages reprocessed
- Alerts triggered when queue depth > threshold
- Permanent failures discarded after review

---

#### Line 91: Bind Queue to Delayed Exchange

```php
$this->getChannel()->queue_bind($this->queue, $this->queue, '#');
```

**Binds the main queue to the delayed exchange**

**Parameters:**
- **Queue**: `"easyweek.invoice-service"` (main queue)
- **Exchange**: `"easyweek.invoice-service"` (delayed exchange)
- **Routing key**: `'#'` (wildcard - match all routing keys)

**Why this binding is needed:**
1. When retry is needed, message is published to delayed exchange
2. Exchange holds message for delay period (1s, 5s, 60s)
3. After delay expires, exchange routes message back to queue
4. Consumer receives message again (retry attempt)

**Routing flow:**
```
Message fails
  ↓
Publish to delayed exchange with x-delay header
  ↓
Exchange holds message (waiting...)
  ↓
Delay expires
  ↓
Routing: Use binding with '#' pattern
  ↓
Message delivered back to main queue
  ↓
Consumer processes again
```

---

### Complete Architecture Created

After `initialWithFailedQueue()` completes, you have:

```
┌─────────────────────────────────────────────────────────┐
│ MAIN QUEUE: "easyweek.invoice-service"                  │
│ Properties:                                             │
│   - durable: true (survives restarts)                   │
│   - x-dead-letter-exchange: "easyweek.invoice-*.failed" │
│ Bindings:                                               │
│   - From "easyweek.bus" exchange (for incoming events)  │
│   - From "easyweek.invoice-service" (for retries)       │
└──────────────────┬──────────────────────────────────────┘
                   │
                   ├─ Success → ack() → deleted
                   │
                   ├─ Failure (retry) ↓
                   │
┌──────────────────┴──────────────────────────────────────┐
│ DELAYED EXCHANGE: "easyweek.invoice-service"            │
│ Type: x-delayed-message                                 │
│ Properties:                                             │
│   - x-delayed-type: topic                               │
│ Behavior:                                               │
│   - Holds messages for x-delay milliseconds             │
│   - Routes back to main queue after delay               │
└──────────────────┬──────────────────────────────────────┘
                   │ (after max retries)
                   ↓
┌─────────────────────────────────────────────────────────┐
│ FAILED QUEUE: "easyweek.invoice-service.failed"         │
│ Properties:                                             │
│   - durable: true                                       │
│   - No DLX (final destination)                          │
│ Purpose:                                                │
│   - Manual review and reprocessing                      │
└─────────────────────────────────────────────────────────┘
```

---

### 2.4.7 Key Concept: Exchanges vs Queues Explained

> **Common Confusion**: Why does the code create a queue AND an exchange with the same name?
> This section clarifies the fundamental difference and how they work together.

#### Fundamental Difference

```
EXCHANGE                           QUEUE
└─ Router                          └─ Storage
└─ Receives messages               └─ Holds messages
└─ Routes to queues                └─ Consumers read from here
└─ Does NOT store                  └─ FIFO buffer
└─ Disappears after routing        └─ Persists until consumed
```

**Critical point:** They are **separate resources** in RabbitMQ, even if named the same!

---

#### Step-by-Step: What `initialWithFailedQueue()` Creates

**Step 1 (Line 83-85): Create Main Queue**

```php
$this->queue($queue, new AMQPTable([
    'x-dead-letter-exchange' => $dlx,
]));
```

**Creates:**
```
┌─────────────────────────────────────────────┐
│ RESOURCE TYPE: Queue                        │
│ NAME: "easyweek.invoice-service"            │
│ PURPOSE: Store messages for consumption     │
│ CONFIG: x-dead-letter-exchange = "...failed"│
└─────────────────────────────────────────────┘
```

---

**Step 2 (Line 86-88): Create Delayed Exchange**

```php
$this->createExchange($this->queue, 'x-delayed-message', new AMQPTable([
    'x-delayed-type' => 'topic',
]));
```

**Creates:**
```
┌─────────────────────────────────────────────┐
│ RESOURCE TYPE: Exchange                     │
│ NAME: "easyweek.invoice-service"            │  ← SAME NAME!
│ PURPOSE: Route messages after delay         │
│ TYPE: x-delayed-message                     │
└─────────────────────────────────────────────┘
```

**⚠️ IMPORTANT:** This is a **different resource** than the queue above!
- They have the same name: `"easyweek.invoice-service"`
- But RabbitMQ treats them as separate: one exchange, one queue

---

**Step 3 (Line 90): Create Failed Queue**

```php
$this->createQueue($dlx);
```

**Creates:**
```
┌─────────────────────────────────────────────┐
│ RESOURCE TYPE: Queue                        │
│ NAME: "easyweek.invoice-service.failed"     │
│ PURPOSE: Store permanently failed messages  │
└─────────────────────────────────────────────┘
```

---

**Step 4 (Line 91): Bind Queue to Exchange**

```php
$this->getChannel()->queue_bind($this->queue, $this->queue, '#');
```

**What this does:**
```
queue_bind(
    $queue = "easyweek.invoice-service",      ← QUEUE (storage)
    $exchange = "easyweek.invoice-service",   ← EXCHANGE (router)
    $routing_key = "#"                        ← Match all
)
```

**Creates a binding:**
```
┌───────────────────────────────┐
│ Exchange:                     │
│ "easyweek.invoice-service"    │
│ (x-delayed-message)           │
└──────────┬────────────────────┘
           │
           │ BINDING (routing_key: "#")
           │ "Route messages to queue"
           ↓
┌───────────────────────────────┐
│ Queue:                        │
│ "easyweek.invoice-service"    │
│ (storage)                     │
└───────────────────────────────┘
```

---

#### Complete RabbitMQ Resources After Setup

After all 4 steps, here's what exists in RabbitMQ:

```
┌─────────────────────────────────────────────────────────────┐
│ RabbitMQ Resources                                          │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│ 1. QUEUE: "easyweek.invoice-service"                        │
│    Type: Queue (storage)                                    │
│    Config: x-dead-letter-exchange = "...failed"             │
│                                                             │
│ 2. EXCHANGE: "easyweek.invoice-service"                     │
│    Type: x-delayed-message exchange (router)                │
│    Config: x-delayed-type = topic                           │
│                                                             │
│ 3. QUEUE: "easyweek.invoice-service.failed"                 │
│    Type: Queue (storage)                                    │
│                                                             │
│ 4. BINDING: Exchange "easyweek.invoice-service"             │
│             → Queue "easyweek.invoice-service"              │
│             (routing_key: "#")                              │
└─────────────────────────────────────────────────────────────┘
```

---

#### How They Work Together: Message Retry Flow

**Normal Message Flow:**

```
1. Message arrives at main QUEUE
   ┌────────────────────────────┐
   │ Queue: invoice-service     │
   │ Message: {data}            │
   └────────────────────────────┘

2. Consumer processes
   ↓
   SUCCESS → ack() → deleted
```

---

**Retry Flow (Message Fails):**

```
1. Message fails in consumer
   ↓
2. Code publishes to EXCHANGE with delay

   $this->getChannel()->basic_publish(
       $message,
       "easyweek.invoice-service",  ← EXCHANGE (not queue!)
       "invoice.created"            ← routing key
   );

   Headers: ['x-delay' => 5000]  // 5 seconds

   ↓

3. Message goes to EXCHANGE
   ┌─────────────────────────────────┐
   │ Exchange: invoice-service       │
   │ (x-delayed-message)             │
   │                                 │
   │ Holds message for 5 seconds...  │
   │ ⏱️  Waiting...                   │
   └─────────────────────────────────┘

   ↓ (after 5 seconds)

4. Exchange routes via BINDING

   Looks for bindings where:
   - Routing key matches "#" (matches anything)
   - Points to queue

   ↓

5. Message delivered to QUEUE
   ┌────────────────────────────┐
   │ Queue: invoice-service     │
   │ Message: {data}            │
   │ (retry attempt 2)          │
   └────────────────────────────┘

   ↓

6. Consumer receives again
   Process → Success or retry again
```

**See:** [NanoConsumer.php:223-228](../src/NanoConsumer.php#L223-L228) for retry publishing code

---

#### Why Same Name for Exchange and Queue?

**Design choice reasons:**

##### 1. Simplicity
```php
// Publish to delayed exchange
$this->getChannel()->basic_publish($message, $this->queue, $key);
                                          // ↑ Same variable
                                          // $this->queue = "easyweek.invoice-service"
```

- `$this->queue` stores the name
- Used for both queue name AND exchange name
- Less configuration to manage

---

##### 2. Logical Grouping
```
Service: invoice-service
  ├─ Queue:    easyweek.invoice-service (storage)
  ├─ Exchange: easyweek.invoice-service (delayed router)
  └─ Queue:    easyweek.invoice-service.failed (DLX storage)
```

- All resources named after the service
- Clear ownership and purpose

---

##### 3. Self-Contained Retry System
```
"easyweek.invoice-service" resources work together:

  Exchange (router) → Queue (storage)
       ↑__________________|
       Binding connects them
```

- Exchange only routes to its own queue
- Self-contained system per service

---

#### Visual: Complete Message Lifecycle

```
┌─────────────────────────────────────────────────────────────┐
│ 1. NEW MESSAGE ARRIVES                                      │
│                                                             │
│    From: "easyweek.bus" exchange                            │
│    To:   QUEUE "easyweek.invoice-service"                   │
│          [Message stored here]                              │
└──────────────┬──────────────────────────────────────────────┘
               │
               ↓ Consumer reads

┌──────────────┴──────────────────────────────────────────────┐
│ 2. CONSUMER PROCESSES                                       │
│                                                             │
│    try { callback($message); }                              │
│    catch (Exception $e) { ... }                             │
└──────────────┬──────────────────────────────────────────────┘
               │
               ├─ SUCCESS ─────> ack() ─────> DELETED
               │
               └─ FAILURE (retry 1/3)
                  │
┌─────────────────┴───────────────────────────────────────────┐
│ 3. PUBLISH TO DELAYED EXCHANGE                              │
│                                                             │
│    basic_publish(                                           │
│        $message,                                            │
│        "easyweek.invoice-service",  ← EXCHANGE              │
│        "invoice.created"            ← routing key           │
│    )                                                        │
│    Headers: ['x-delay' => 1000]                             │
└──────────────┬──────────────────────────────────────────────┘
               │
               ↓
┌──────────────┴──────────────────────────────────────────────┐
│ 4. EXCHANGE HOLDS MESSAGE                                   │
│                                                             │
│    EXCHANGE "easyweek.invoice-service"                      │
│    Type: x-delayed-message                                  │
│    Status: Holding message for 1000ms... ⏱️                  │
│                                                             │
│    (Message NOT in queue yet!)                              │
└──────────────┬──────────────────────────────────────────────┘
               │
               ↓ After 1 second

┌──────────────┴──────────────────────────────────────────────┐
│ 5. EXCHANGE ROUTES VIA BINDING                              │
│                                                             │
│    EXCHANGE "easyweek.invoice-service"                      │
│         ↓ (uses binding with routing_key "#")               │
│    QUEUE "easyweek.invoice-service"                         │
│    [Message stored again]                                   │
└──────────────┬──────────────────────────────────────────────┘
               │
               ↓ Consumer reads again

┌──────────────┴──────────────────────────────────────────────┐
│ 6. RETRY ATTEMPT                                            │
│                                                             │
│    Consumer processes again                                 │
│    ├─ SUCCESS → ack() → DELETED                             │
│    └─ FAILURE → Retry 2/3 (5s delay)                        │
│                 └─ Max retries? → DLX (failed queue)        │
└─────────────────────────────────────────────────────────────┘
```

---

#### Key Insights

##### 1. Exchange = Router, Queue = Storage
```
Exchange                        Queue
└─ Temporary                    └─ Persistent
└─ Routes and disappears        └─ Stores until consumed
└─ Can delay (x-delayed-msg)    └─ Can't delay
└─ No consumers                 └─ Has consumers
```

---

##### 2. Bindings Connect Them
```
Without binding:
  Exchange → (nowhere) → messages lost

With binding:
  Exchange → Queue → consumers receive
```

---

##### 3. Same Name ≠ Same Resource
```
RabbitMQ namespace:
  exchanges/easyweek.invoice-service    ← Exchange
  queues/easyweek.invoice-service       ← Queue

Different resources, different purposes!
```

---

##### 4. Why Binding Uses "#"?
```
queue_bind($queue, $exchange, '#')
                              ↑
                    Match ALL routing keys

Because:
- Messages published with event names: "invoice.created", "invoice.updated"
- We want ALL retries to return to this queue
- "#" wildcard matches everything
```

---

#### Compare: What If We Used Different Names?

**Alternative (more explicit):**

```php
// More explicit naming
$queueName = "easyweek.invoice-service.queue";
$exchangeName = "easyweek.invoice-service.retry-exchange";

$this->queue($queueName);
$this->createExchange($exchangeName, 'x-delayed-message');
$this->getChannel()->queue_bind($queueName, $exchangeName, '#');
```

**Why package doesn't do this:**
- More configuration to manage
- Same name is simpler (one variable: `$this->queue`)
- Self-documenting (all resources named after service)

---

#### Summary Table

| Step | Resource Type | Name | Purpose |
|------|--------------|------|---------|
| 1 | Queue | `easyweek.invoice-service` | Store incoming messages |
| 2 | Exchange | `easyweek.invoice-service` | Route delayed retries |
| 3 | Queue | `easyweek.invoice-service.failed` | Store permanently failed |
| 4 | Binding | Exchange→Queue (routing: `#`) | Connect exchange to queue |

**Result:** Self-contained retry system per microservice!

---

## 2.5 Message Validation with MessageValidator

### Introduction

**Before processing begins**, the consumer validates every incoming message to ensure it has the required structure and fields. Invalid messages are rejected immediately to prevent processing errors.

**Reference:** [MessageValidator.php](../src/Validators/MessageValidator.php)

### Why Message Validation?

**Problems without validation:**
- ❌ Consumer crashes on malformed messages
- ❌ Database operations fail with missing fields
- ❌ Hard to debug invalid message issues
- ❌ No visibility into validation failures

**Benefits with validation:**
- ✅ Invalid messages rejected early (fail-fast)
- ✅ Clear error logs with validation details
- ✅ Metrics tracked (`rmq_consumer_error_total` with `validation_error` tag)
- ✅ Prevents cascading failures

### MessageValidator Implementation

**Reference:** [MessageValidator.php:46-92](../src/Validators/MessageValidator.php#L46-L92)

```php
public function validateMessage(AMQPMessage $message): bool
{
    $errors = [];

    // Check type (event name)
    if (!$message->has('type') || empty($message->get('type'))) {
        $errors[] = 'Missing or empty type';
    }

    // Check message_id
    if (!$message->has('message_id') || empty($message->get('message_id'))) {
        $errors[] = 'Missing or empty message_id';
    }

    // Check app_id (publisher name)
    if (!$message->has('app_id') || empty($message->get('app_id'))) {
        $errors[] = 'Missing or empty app_id';
    }

    // Check valid JSON payload
    $body = $message->getBody();
    if (!empty($body)) {
        json_decode($body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = 'Invalid JSON payload: ' . json_last_error_msg();
        }
    }

    if (!empty($errors)) {
        // Track validation error
        $this->statsD->increment('rmq_consumer_error_total', [
            'nano_service_name' => $this->microserviceName,
            'error_type' => ConsumerErrorType::VALIDATION_ERROR->getValue(),
        ]);

        $this->logger->error('[NanoConsumer] Invalid message received, rejecting:', [
            'errors' => $errors,
            'message_id' => $message->has('message_id') ? $message->get('message_id') : 'unknown',
            'type' => $message->has('type') ? $message->get('type') : 'unknown',
            'app_id' => $message->has('app_id') ? $message->get('app_id') : 'unknown',
            'body_preview' => substr($body, 0, 200), // First 200 chars for debugging
        ]);
        return false;
    }

    return true;
}
```

### Required Fields

| Field | Purpose | Example |
|-------|---------|---------|
| `type` | Event name (routing key) | `"invoice.created"` |
| `message_id` | Unique message identifier (UUID) | `"550e8400-e29b-41d4-a716-446655440000"` |
| `app_id` | Publisher service name | `"invoice-service"` |
| `body` | JSON payload | `{"meta":{},"payload":{},"status":{},"system":{}}` |

### Validation in consumeCallback

**Reference:** [NanoConsumer.php:315-322](../src/NanoConsumer.php#L315-L322)

```php
public function consumeCallback(AMQPMessage $message): void
{
    // Validate message structure before processing
    if (!$this->messageValidator->validateMessage($message)) {
        // Invalid message - ACK and skip to prevent reprocessing
        $message->ack();
        return;
    }

    // Continue with processing...
}
```

**Critical behavior:**
- ✅ Invalid messages are **ACK'd** (removed from queue)
- ✅ Prevents infinite reprocessing of broken messages
- ✅ Logs detailed validation errors
- ✅ Tracks metrics for alerting

---

## 2.6 Idempotency with Inbox Pattern

### Introduction

The consumer implements the **Inbox Pattern** for idempotent message processing. This guarantees that each message is processed **exactly once**, even if:
- Message is redelivered by RabbitMQ
- Multiple consumers receive the same message (race condition)
- Consumer crashes and restarts

**Reference:** [EventRepository.php](../src/EventRepository.php)

### Why Inbox Pattern?

**Problems without idempotency:**
- ❌ Duplicate processing (user charged twice, email sent twice)
- ❌ Race conditions (multiple workers process same message)
- ❌ Redelivery causes duplicates (ACK failure, consumer crash)

**Benefits with inbox pattern:**
- ✅ Guaranteed exactly-once processing
- ✅ Race condition safety (database unique constraint)
- ✅ Audit trail (all messages tracked in DB)
- ✅ Retry tracking (retry count persisted)

### Database Schema: inbox Table

```sql
CREATE TABLE inbox (
    id SERIAL PRIMARY KEY,
    consumer_service VARCHAR(255) NOT NULL,
    producer_service VARCHAR(255) NOT NULL,
    event_type VARCHAR(255) NOT NULL,
    message_body JSONB NOT NULL,
    message_id TEXT NOT NULL,
    status VARCHAR(50) NOT NULL, -- 'processing', 'processed', 'failed'
    retry_count INT DEFAULT 1,
    last_error TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    processed_at TIMESTAMP,
    UNIQUE (message_id, consumer_service) -- Idempotency guarantee
);
```

**Key design:**
- **Unique constraint** on `(message_id, consumer_service)` prevents duplicates
- **Status tracking**: `processing` → `processed` or `failed`
- **Retry count**: Tracks how many times message was attempted
- **Audit trail**: All messages logged with timestamps

### Inbox Pattern Flow in consumeCallback

**Reference:** [NanoConsumer.php:348-403](../src/NanoConsumer.php#L348-L403)

```php
public function consumeCallback(AMQPMessage $message): void
{
    // 1. Validate message
    if (!$this->messageValidator->validateMessage($message)) {
        $message->ack();
        return;
    }

    $newMessage = $this->createNanoServiceMessage($message);
    $messageId = $newMessage->getId();
    $consumerService = $_ENV['AMQP_MICROSERVICE_NAME'];
    $schema = $_ENV['DB_BOX_SCHEMA'];
    $repository = EventRepository::getInstance();

    try {
        // 2. Check if message already exists in inbox and already processed
        if ($repository->existsInInboxAndProcessed($messageId, $consumerService, $schema)) {
            // Message already processed - ACK and skip (idempotent behavior)
            $message->ack();
            return;
        }

        // 3. Insert into inbox with status 'processing'
        if (!$this->insertMessageToInbox($repository, $newMessage, $consumerService, $schema, $message->getBody())) {
            // Race condition - another worker inserted it
            // ACK and skip (idempotent behavior)
            $message->ack();
            return;
        }

        // 4. Process message
        $this->executeUserCallback($newMessage);

        // 5. Mark as processed in inbox
        $this->handleSuccessfulProcessing(
            $message,
            $messageId,
            $consumerService,
            $newMessage->getEventName(),
            $schema,
            $eventRetryStatusTag,
            $repository,
            $tags
        );

    } catch (Throwable $exception) {
        // Handle retry or DLX...
    }
}
```

### Step-by-Step: Idempotency Guarantees

#### Step 1: Check if Already Processed

**Reference:** [EventRepository.php:460-487](../src/EventRepository.php#L460-L487)

```php
// Check if message already exists in inbox with 'processed' status
if ($repository->existsInInboxAndProcessed($messageId, $consumerService, $schema)) {
    // Already processed - skip
    $message->ack();
    return;
}
```

**SQL query:**
```sql
SELECT 1
FROM inbox
WHERE message_id = ? AND consumer_service = ? AND status = 'processed'
LIMIT 1
```

**Scenarios:**
- ✅ Message already processed → ACK and skip (fast path)
- ✅ Message not found → Continue to insertion
- ✅ Message exists but not processed → Continue (might be retry)

#### Step 2: Insert into Inbox (Race Condition Safety)

**Reference:** [NanoConsumer.php:458-503](../src/NanoConsumer.php#L458-L503)

```php
private function insertMessageToInbox(...): bool
{
    try {
        // Check if exists
        if ($repository->existsInInbox($messageId, $consumerService, $schema)) {
            return true; // Already in 'processing' state
        }

        // Insert with status 'processing'
        $inserted = $repository->insertInbox(
            $consumerService,
            $message->getPublisherName(),
            $message->getEventName(),
            $messageBody,
            $messageId,
            $schema,
            'processing',
            $initialRetryCount
        );

        return $inserted; // true if successful, false if race condition
    } catch (\RuntimeException $e) {
        // Critical DB error - track metrics and rethrow
        throw $e;
    }
}
```

**SQL insert:**
```sql
INSERT INTO inbox (
    consumer_service,
    producer_service,
    event_type,
    message_body,
    message_id,
    status,
    retry_count
) VALUES (?, ?, ?, ?::jsonb, ?, ?, ?)
```

**Race condition handling:**
```
Time →
Worker A: existsInInbox() = false
Worker B: existsInInbox() = false
Worker A: INSERT → SUCCESS ✅
Worker B: INSERT → DUPLICATE KEY ERROR ❌
Worker A: return true → continues processing
Worker B: return false → ACK and exit (skip)
```

**Database unique constraint protects:**
```sql
UNIQUE (message_id, consumer_service)
```

#### Step 3: Mark as Processed

**Reference:** [NanoConsumer.php:602-654](../src/NanoConsumer.php#L602-L654)

```php
private function handleSuccessfulProcessing(...): void
{
    // 1. ACK message (critical - remove from RabbitMQ)
    try {
        $originalMessage->ack();
    } catch (Throwable $e) {
        $this->statsD->increment('rmq_consumer_ack_failed_total', $tags);
        throw $e; // Critical - don't mark as processed if ACK fails
    }

    // 2. Mark as processed in inbox (best effort - non-blocking)
    try {
        $marked = $repository->markInboxAsProcessed($messageId, $consumerService, $schema);

        if (!$marked) {
            // Event processed and ACKed but not marked in DB
            $this->logger->error("[NanoConsumer] Event processed and ACKed but not marked as processed (duplicate risk)");
        }
    } catch (Throwable $e) {
        // Log but don't fail (message already ACK'd and processed)
    }
}
```

**SQL update:**
```sql
UPDATE inbox
SET status = 'processed',
    processed_at = NOW()
WHERE message_id = ? AND consumer_service = ?
```

**Critical ordering:**
1. ✅ **ACK first** (remove from RabbitMQ) - CRITICAL
2. ✅ **Mark as processed** (update DB) - best effort

**Why this order?**
- If ACK fails → message redelivered → inbox check catches it
- If mark fails → duplicate risk → logged and tracked

### Retry Handling with Inbox Pattern

**Reference:** [NanoConsumer.php:672-725](../src/NanoConsumer.php#L672-L725)

```php
private function handleRetryableFailure(...): void
{
    // 1. Execute user's catch callback
    $this->executeCatchCallback($exception, $message, $messageId, $consumerService);

    // 2. Republish for retry (critical)
    try {
        $this->republishForRetry($message, $originalMessage, $key, $retryCount);
    } catch (Throwable $e) {
        // Republish failed - don't ACK, let RabbitMQ redeliver
        throw $e;
    }

    // 3. Update retry count in inbox (best effort - non-blocking)
    try {
        $this->updateInboxRetryCount($messageId, $consumerService, $retryCount, $schema, ...);
    } catch (Throwable $e) {
        // Log but don't fail (message already republished)
    }
}
```

**Retry count tracking:**
```sql
UPDATE inbox
SET retry_count = ?
WHERE message_id = ? AND consumer_service = ?
```

**Note:** Status stays as `'processing'` during retries, only changes to `'processed'` or `'failed'` at the end.

### Final Failure Handling

**Reference:** [NanoConsumer.php:847-903](../src/NanoConsumer.php#L847-L903)

```php
private function handleFinalFailure(...): void
{
    // 1. Execute user's failed callback
    $this->executeFailedCallback($exception, $message, $messageId, $consumerService);

    // 2. Publish to DLX (critical)
    try {
        $this->publishToDLX($message, $originalMessage, $exception, $retryCount);
    } catch (Throwable $e) {
        // DLX publish failed - don't ACK, let RabbitMQ redeliver
        throw $e;
    }

    // 3. Mark as failed in inbox (best effort - non-blocking)
    try {
        $this->markInboxAsFailed($messageId, $consumerService, $schema, $exception, ...);
    } catch (Throwable $e) {
        // Log but don't fail (message already sent to DLX)
    }
}
```

**Mark as failed:**
```sql
UPDATE inbox
SET status = 'failed',
    last_error = ?
WHERE message_id = ? AND consumer_service = ?
```

### Inbox Pattern Benefits Summary

| Scenario | Without Inbox | With Inbox |
|----------|---------------|------------|
| Duplicate message | ❌ Processed twice | ✅ Detected and skipped |
| Race condition (2 workers) | ❌ Both process | ✅ One processes, one skips |
| ACK failure (redelivery) | ❌ Processed twice | ✅ Detected and skipped |
| Consumer crash | ❌ Unknown state | ✅ Inbox shows 'processing' |
| Audit trail | ❌ No record | ✅ Full history in DB |
| Retry tracking | ❌ Lost in RabbitMQ | ✅ Persisted in DB |

---

## 2.7 Message Processing: `consumeCallback()`

### Method Overview

**Reference:** [NanoConsumer.php:315-404](../src/NanoConsumer.php#L315-L404)

**Signature:**
```php
public function consumeCallback(AMQPMessage $message): void
```

**Purpose:**
- Called by RabbitMQ for EACH incoming message
- Validates message structure (MessageValidator)
- Implements idempotency (Inbox Pattern)
- Wraps message in `NanoServiceMessage`
- Handles system events vs user events
- Implements retry logic with exponential backoff
- Tracks metrics for observability
- ACKs or retries based on success/failure

**This is the heart of the consumer - where all the magic happens!**

### High-Level Flow (Updated 2026-02-09)

```php
consumeCallback(AMQPMessage $message)
├─ 1. Validate message structure (MessageValidator)
│   ├─ Invalid → ACK and skip (log + metrics)
│   └─ Valid → Continue
├─ 2. Wrap message in NanoServiceMessage
├─ 3. Check if system event (system.ping.1)
│   ├─ Yes → Handle system event, ACK, return
│   └─ No → Continue
├─ 4. Inbox Pattern: Check if already processed
│   ├─ Already processed → ACK and skip (idempotency)
│   └─ Not processed → Continue
├─ 5. Inbox Pattern: Insert into inbox table
│   ├─ Race condition (duplicate) → ACK and skip
│   └─ Inserted successfully → Continue
├─ 6. Setup metrics and tracking
│   └─ Track payload size, start timer, build tags
├─ 7. Execute user callback
│   ├─ Success → ACK → Mark as processed in inbox → Track success metrics
│   └─ Failure (exception) ↓
├─ 8. Retry logic
│   ├─ Retries remaining?
│   │   ├─ Yes → Publish to delayed exchange → ACK → Update retry count in inbox → Track failed metrics
│   │   └─ No → Send to DLX → ACK → Mark as failed in inbox → Track DLX metrics
└─ Done
```

### Detailed Implementation (Coming in Section 3)

**Note:** Section 3 will provide a complete line-by-line breakdown of `consumeCallback()` including:
- Message wrapping and preparation
- System event handling
- Retry count tracking
- Metrics collection
- Exception handling
- Retry logic with backoff calculation
- DLX routing for max retries

---

# 3. Message Processing Lifecycle

## 3.1 Overview (Updated 2026-02-09)

This section covers the complete message lifecycle from arrival to completion/failure in the `consumeCallback()` method, including **message validation** and **inbox pattern for idempotency**.

**Reference:** [NanoConsumer.php:315-404](../src/NanoConsumer.php#L315-L404)

### Complete Flow Diagram

```
┌─────────────────────────────────────────────────────────────┐
│ consumeCallback(AMQPMessage $message)                       │
└──────────────┬──────────────────────────────────────────────┘
               │
               ↓
┌──────────────────────────────────────────────────────────────┐
│ 1. MESSAGE VALIDATION (Lines 317-322)                       │
│    - MessageValidator->validateMessage()                    │
│    - Check: type, message_id, app_id, valid JSON            │
│    ├─ INVALID → ACK → Log + Metrics → RETURN               │
│    └─ VALID   → Continue                                    │
└──────────────┬───────────────────────────────────────────────┘
               │
               ↓
┌──────────────────────────────────────────────────────────────┐
│ 2. MESSAGE PREPARATION (Lines 324-338)                      │
│    - Wrap in NanoServiceMessage                             │
│    - Set delivery tag and channel                           │
│    - Extract routing key (event type)                       │
│    - Get environment variables (service, schema)            │
│    - Get EventRepository singleton                          │
└──────────────┬───────────────────────────────────────────────┘
               │
               ↓
┌──────────────────────────────────────────────────────────────┐
│ 3. SYSTEM EVENT CHECK (Lines 327-332)                       │
│    - Is this a system event? (system.ping.1)                │
│    ├─ YES → Handle with system handler → ACK → RETURN       │
│    └─ NO  → Continue to user handler                        │
└──────────────┬───────────────────────────────────────────────┘
               │
               ↓
┌──────────────────────────────────────────────────────────────┐
│ 4. INBOX: Check Already Processed (Lines 348-352)           │
│    - repository->existsInInboxAndProcessed()                │
│    - Query: WHERE message_id=? AND status='processed'       │
│    ├─ YES → ACK → RETURN (idempotency)                     │
│    └─ NO  → Continue                                        │
└──────────────┬───────────────────────────────────────────────┘
               │
               ↓
┌──────────────────────────────────────────────────────────────┐
│ 5. INBOX: Insert with status='processing' (Lines 355-361)   │
│    - insertMessageToInbox() with unique constraint          │
│    ├─ RACE CONDITION → ACK → RETURN                        │
│    └─ INSERTED → Continue                                   │
└──────────────┬───────────────────────────────────────────────┘
               │
               ↓
┌──────────────────────────────────────────────────────────────┐
│ 6. METRICS SETUP (Lines 340-345)                            │
│    - Setup tags (service, event)                            │
│    - Track payload size (rmq_consumer_payload_bytes)        │
│    - Get retry count and retry tag (FIRST/RETRY/LAST)      │
│    - Start processing timer (statsD->start())               │
└──────────────┬───────────────────────────────────────────────┘
               │
               ↓
┌──────────────────────────────────────────────────────────────┐
│ 7. EXECUTE USER CALLBACK (Line 363)                         │
│    - executeUserCallback($newMessage)                       │
│    - Uses debugCallback if debug mode, else regular         │
│    - call_user_func($callback, $message)                    │
└──────────────┬───────────────────────────────────────────────┘
               │
               ├─ SUCCESS ↓
               │
┌──────────────┴───────────────────────────────────────────────┐
│ 8. SUCCESS HANDLING (Lines 365-374)                         │
│    - handleSuccessfulProcessing()                           │
│      ├─ 1. ACK message (RabbitMQ) - CRITICAL               │
│      ├─ 2. Mark as 'processed' in inbox - best effort      │
│      └─ 3. Track SUCCESS metrics                           │
└──────────────┬───────────────────────────────────────────────┘
               │
               └─> END

               ↓ EXCEPTION (Lines 376-403)
               │
┌──────────────┴───────────────────────────────────────────────┐
│ 9. ERROR HANDLING - catch (Throwable $exception)            │
└──────────────┬───────────────────────────────────────────────┘
               │
               ↓
        ┌──────┴──────┐
        │ Retries     │
        │ remaining?  │
        │ (retryCount │
        │  < tries)   │
        └──┬──────┬───┘
           │      │
     YES   │      │   NO
           ↓      ↓
┌──────────────────┐ ┌────────────────────────────┐
│ 10A. RETRY       │ │ 10B. FINAL FAILURE         │
│ (Lines 380-391)  │ │ (Lines 392-402)            │
├──────────────────┤ ├────────────────────────────┤
│ - Execute catch  │ │ - Execute failed callback  │
│   callback       │ │ - Track DLX metric         │
│ - Republish with │ │ - Publish to DLX queue     │
│   x-delay header │ │   (.failed)                │
│ - ACK original   │ │ - ACK original             │
│ - Update retry   │ │ - Mark as 'failed' in inbox│
│   count in inbox │ │   with error message       │
│ - Track FAILED   │ │ - Track FAILED metric      │
│   metric         │ │                            │
└──────────────────┘ └────────────────────────────┘
```

---

## 3.2 Line-by-Line Breakdown: `consumeCallback()`

### Phase 1: Message Preparation (Lines 160-164)

```php
$newMessage = new NanoServiceMessage($message->getBody(), $message->get_properties());
$newMessage->setDeliveryTag($message->getDeliveryTag());
$newMessage->setChannel($message->getChannel());

$key = $message->get('type');
```

**Line 160: Wrap RabbitMQ Message**
- `$message` is raw `AMQPMessage` from php-amqplib
- Wraps it in `NanoServiceMessage` (extends AMQPMessage)
- Passes body (JSON string) and properties (headers, message_id, etc.)

**Lines 161-162: Set Delivery Information**
- `setDeliveryTag()`: Unique identifier for this delivery (used for ACK)
- `setChannel()`: RabbitMQ channel reference (needed to ACK later)

**Line 164: Extract Routing Key**
- `$key = $message->get('type')` - Gets the routing key
- Example: `"invoice.created"`, `"user.updated"`, `"system.ping.1"`
- Used to route to correct handler

---

### Phase 2: System Event Check (Lines 166-171)

```php
// Check system handlers
if (array_key_exists($key, $this->handlers)) {
    (new $this->handlers[$key]())($newMessage);
    $message->ack();
    return;
}
```

**System Handlers:**
```php
protected array $handlers = [
    'system.ping.1' => SystemPing::class,
];
```

**What happens:**
1. Check if event is a system event (`system.ping.1`)
2. If yes:
   - Instantiate system handler class
   - Call it with message
   - ACK immediately
   - Return (skip user callback)

**Why system events?**
- Health checks (ping/pong)
- Internal control messages
- Monitoring/diagnostic events
- Don't require user code to handle

---

### Phase 3: Callback Selection (Line 174)

```php
$callback = $newMessage->getDebug() && is_callable($this->debugCallback)
    ? $this->debugCallback
    : $this->callback;
```

**Decision logic:**
```
Is message.system.is_debug == true?
└─ YES → Use debugCallback (if provided)
└─ NO  → Use regular callback
```

**Debug mode usage:**
```php
// In publisher
$message->setDebug(true);
$publisher->publish('invoice.created');

// In consumer
$consumer->consume(
    function($msg) { /* production handler */ },
    function($msg) { /* debug handler - logs only */ }
);
```

**Use cases:**
- Testing in production without side effects
- Tracing message flow
- Debugging without processing

**How `getDebug()` works:** [NanoServiceMessage.php:283-288](../src/NanoServiceMessage.php#L283-L288)
```php
public function getDebug(): bool
{
    $system = $this->getDataAttribute('system');
    return $system['is_debug'] ?? false;
}
```

---

### Phase 4: Retry Tracking (Lines 176-177)

```php
$retryCount = $newMessage->getRetryCount() + 1;
$eventRetryStatusTag = $this->getRetryTag($retryCount);
```

**Line 176: Get Current Retry Count**

Reads from message headers:
```php
public function getRetryCount(): int
{
    if ($this->has('application_headers')) {
        $headers = $this->get('application_headers')->getNativeData();
        return isset($headers['x-retry-count']) ? (int)$headers['x-retry-count'] : 0;
    }
    return 0;
}
```

**Examples:**
- First attempt: `x-retry-count` not set → returns `0`, so `retryCount = 1`
- After first retry: `x-retry-count = 1` → returns `1`, so `retryCount = 2`
- After second retry: `x-retry-count = 2` → returns `2`, so `retryCount = 3`

---

**Line 177: Determine Retry Tag**

```php
private function getRetryTag(int $retryCount): EventRetryStatusTag
{
    return match ($retryCount) {
        1 => EventRetryStatusTag::FIRST,            // First attempt
        $this->tries => EventRetryStatusTag::LAST,  // Last attempt (max retries)
        default => EventRetryStatusTag::RETRY,      // Middle attempts
    };
}
```

**With `tries(3)` configuration:**
```
retryCount=1 → FIRST  (original attempt)
retryCount=2 → RETRY  (first retry)
retryCount=3 → LAST   (final attempt before DLX)
```

**Why tag retries?**
- Metrics can distinguish first attempts vs retries
- Alerts can trigger on high LAST attempt failures
- Different sampling rates for different retry stages

---

### Phase 5: Metrics Setup (Lines 179-195)

**Lines 180-183: Build Metric Tags**

```php
$tags = [
    'nano_service_name' => $this->getEnv(self::MICROSERVICE_NAME),
    'event_name' => $newMessage->getEventName()
];
```

Example tags:
```php
[
    'nano_service_name' => 'invoice-service',
    'event_name' => 'invoice.created'
]
```

**Why these tags?**
- **nano_service_name**: Which service is consuming (for dashboards per service)
- **event_name**: Which event type (for dashboards per event)
- **Low cardinality**: Only service names and event types (bounded sets)

---

**Lines 186-192: Track Payload Size**

```php
$payloadSize = strlen($message->getBody());
$this->statsD->histogram(
    'rmq_consumer_payload_bytes',
    $payloadSize,
    $tags,
    $this->statsD->getSampleRate('payload')
);
```

**What this tracks:**
- Metric: `rmq_consumer_payload_bytes`
- Type: Histogram (distribution of values)
- Value: Message body size in bytes
- Sample rate: Configurable (default from `STATSD_SAMPLE_PAYLOAD`)

**Why track payload size?**
- Identify large messages causing slowdowns
- Detect payload size growth over time
- Alert on abnormally large messages
- Capacity planning

---

**Line 195: Start Processing Timer**

```php
$this->statsD->start($tags, $eventRetryStatusTag);
```

**What `start()` does:** [StatsDClient.php:64-76](../src/Clients/StatsDClient/StatsDClient.php#L64-L76)
```php
public function start(array $tags, EventRetryStatusTag $eventRetryStatusTag): void
{
    if (!$this->canStartService) {
        return;
    }

    $this->tags = $tags;
    $this->addTags([
        'retry' => $eventRetryStatusTag->value  // 'first', 'retry', or 'last'
    ]);
    $this->start = microtime(true);  // Start timer
    $this->statsd->increment("event_started_count", 1, 1, $this->tags);
}
```

**Metrics emitted:**
1. **event_started_count** - Counter incremented for each message
2. **Timer started** - Stored in `$this->start` for duration calculation
3. **Tags enriched** with `retry` tag

**Final tags:**
```php
[
    'nano_service_name' => 'invoice-service',
    'event_name' => 'invoice.created',
    'retry' => 'first'  // or 'retry' or 'last'
]
```

---

### Phase 6: Process Message (Lines 197-210)

**Lines 197-210: Try Block**

```php
try {
    call_user_func($callback, $newMessage);

    // Try to ACK message
    try {
        $message->ack();
    } catch (Throwable $e) {
        // Track ACK failure
        $this->statsD->increment('rmq_consumer_ack_failed_total', $tags);
        throw $e;
    }

    $this->statsD->end(EventExitStatusTag::SUCCESS, $eventRetryStatusTag);

} catch (Throwable $exception) {
    // Error handling...
}
```

---

**Line 199: Call User Callback**

```php
call_user_func($callback, $newMessage);
```

**This is your business logic:**
```php
$consumer->consume(function (NanoServiceMessage $message) {
    // Your code here
    $payload = $message->getPayload();
    Invoice::create($payload);
});
```

**What can happen:**
1. **Success**: Function completes without exception
2. **Exception**: Any error thrown (database, API, validation, etc.)

---

**Lines 202-208: ACK Message**

```php
try {
    $message->ack();
} catch (Throwable $e) {
    $this->statsD->increment('rmq_consumer_ack_failed_total', $tags);
    throw $e;
}
```

**Why nested try-catch for ACK?**
- ACK can fail (connection closed, channel error)
- Track ACK failures separately from processing failures
- Re-throw to trigger retry logic

**What `ack()` does:**
- Tells RabbitMQ "I successfully processed this message"
- RabbitMQ deletes message from queue
- Message is gone forever (unless republished for retry)

**If ACK fails:**
- Metric `rmq_consumer_ack_failed_total` incremented
- Exception propagates to outer catch block
- Message will be retried

---

**Line 210: Track Success Metrics**

```php
$this->statsD->end(EventExitStatusTag::SUCCESS, $eventRetryStatusTag);
```

**What `end()` does:**
```php
public function end(EventExitStatusTag $exitStatus, EventRetryStatusTag $eventRetryStatusTag): void
{
    if (!$this->canStartService) {
        return;
    }

    $duration = (microtime(true) - $this->start) * 1000; // milliseconds

    $this->addTags([
        'exit_status' => $exitStatus->value,     // 'success' or 'failed'
        'retry' => $eventRetryStatusTag->value
    ]);

    $this->statsd->timing("event_processed_duration", $duration, 1, $this->tags);
}
```

**Metrics emitted:**
```
event_processed_duration{
    nano_service_name="invoice-service",
    event_name="invoice.created",
    exit_status="success",
    retry="first"
} = 123.45 ms
```

**Success path complete!** Message processed and deleted.

---

### Phase 7: Error Handling (Lines 212-259)

**Line 212: Catch Any Exception**

```php
catch (Throwable $exception) {
    // Handles ALL errors: Exception, Error, PDOException, etc.
}
```

**Why `Throwable` not `Exception`?**
- `Throwable` is the base interface for all throwable objects
- Catches both `Exception` and `Error` (PHP 7+)
- Ensures fatal errors are also caught and retried

---

**Line 214: Recalculate Retry Count**

```php
$retryCount = $newMessage->getRetryCount() + 1;
```

**Why recalculate?**
- Same as line 176, but in catch block
- Ensures fresh value (in case callback modified headers)
- Redundant but defensive programming

---

**Line 215: Check Retry Budget**

```php
if ($retryCount < $this->tries) {
    // Retry logic (lines 217-231)
} else {
    // DLX logic (lines 233-256)
}
```

**Decision tree:**
```
tries = 3

retryCount = 1 → 1 < 3 → RETRY
retryCount = 2 → 2 < 3 → RETRY
retryCount = 3 → 3 < 3 → FALSE → DLX
```

---

### Phase 8A: Retry Logic (Lines 217-231)

**Lines 217-221: Call Catch Callback**

```php
try {
    if (is_callable($this->catchCallback)) {
        call_user_func($this->catchCallback, $exception, $newMessage);
    }
} catch (Throwable $e) {}
```

**User-defined catch callback:**
```php
$consumer
    ->events('invoice.created')
    ->catch(function($exception, $message) {
        // Log error
        Log::error("Processing failed", [
            'exception' => $exception->getMessage(),
            'event' => $message->getEventName(),
            'retry_count' => $message->getRetryCount()
        ]);
    })
    ->consume(...);
```

**Why wrapped in try-catch?**
- Catch callback is user code (may throw)
- Mustn't prevent retry logic from running
- Errors in catch callback are silently ignored

---

**Lines 223-226: Build Retry Headers**

```php
$headers = new AMQPTable([
    'x-delay' => $this->getBackoff($retryCount),
    'x-retry-count' => $retryCount
]);
```

**Headers explained:**
1. **x-delay**: Milliseconds to delay before redelivery
2. **x-retry-count**: Current attempt number (1, 2, 3...)

**Example:**
```php
// First retry (retryCount = 1)
[
    'x-delay' => 1000,        // 1 second (from backoff[0])
    'x-retry-count' => 1
]

// Second retry (retryCount = 2)
[
    'x-delay' => 5000,        // 5 seconds (from backoff[1])
    'x-retry-count' => 2
]
```

**See Section 3.3 for `getBackoff()` details**

---

**Line 227: Set Headers on Message**

```php
$newMessage->set('application_headers', $headers);
```

**Replaces existing headers** with retry information.

---

**Line 228: Publish to Delayed Exchange**

```php
$this->getChannel()->basic_publish($newMessage, $this->queue, $key);
```

**Parameters:**
- `$newMessage`: Message with updated headers (`x-delay`, `x-retry-count`)
- `$this->queue`: `"easyweek.invoice-service"` - **EXCHANGE NAME** (not queue!)
- `$key`: `"invoice.created"` - Routing key

**What happens:**
1. Message published to **delayed exchange** (same name as queue)
2. Exchange holds message for `x-delay` milliseconds
3. After delay, routes via binding to queue
4. Consumer receives message again (retry attempt)

**See Section 2.4.7 for exchange vs queue explanation**

---

**Line 229: ACK Original Message**

```php
$message->ack();
```

**Critical: Always ACK even on failure!**

**Why?**
- We've republished to delayed exchange (retry scheduled)
- If we don't ACK, RabbitMQ will redeliver original message
- Result: Duplicate processing (both original + retry)

**Pattern:**
```
Original message → FAIL → Republish with delay → ACK original
                                               ↓
                                    Retry scheduled ✅
                                    Original gone ✅
```

---

**Line 231: Track Failed Metrics**

```php
$this->statsD->end(EventExitStatusTag::FAILED, $eventRetryStatusTag);
```

**Metrics emitted:**
```
event_processed_duration{
    nano_service_name="invoice-service",
    event_name="invoice.created",
    exit_status="failed",
    retry="first"
} = 50.23 ms
```

**Retry scheduled!** Message will return after delay.

---

### Phase 8B: Max Retries Exceeded (Lines 233-256)

**Lines 236-240: Call Failed Callback**

```php
try {
    if (is_callable($this->failedCallback)) {
        call_user_func($this->failedCallback, $exception, $newMessage);
    }
} catch (Throwable $e) {}
```

**User-defined failed callback:**
```php
$consumer
    ->events('invoice.created')
    ->failed(function($exception, $message) {
        // Alert team
        Slack::alert("Message permanently failed", [
            'exception' => $exception->getMessage(),
            'event' => $message->getEventName(),
            'payload' => $message->getPayload()
        ]);
    })
    ->consume(...);
```

**Why separate from catch callback?**
- Different handling for "retrying" vs "permanently failed"
- Catch: Log for investigation
- Failed: Alert ops team, create incident ticket

---

**Lines 243-244: Track DLX Metric**

```php
$dlxTags = array_merge($tags, ['reason' => 'max_retries_exceeded']);
$this->statsD->increment('rmq_consumer_dlx_total', $dlxTags);
```

**DLX metric:**
```
rmq_consumer_dlx_total{
    nano_service_name="invoice-service",
    event_name="invoice.created",
    reason="max_retries_exceeded"
} += 1
```

**Why track DLX events?**
- Alert when DLX queue depth grows
- Dashboard showing failure rates by event type
- SLOs on permanent failure percentage

---

**Lines 246-250: Build DLX Headers**

```php
$headers = new AMQPTable([
    'x-retry-count' => $retryCount
]);
$newMessage->set('application_headers', $headers);
$newMessage->setConsumerError($exception->getMessage());
```

**DLX message includes:**
1. **x-retry-count**: Final retry count (3 in this case)
2. **consumer_error**: Exception message for debugging

**No `x-delay`** - message goes directly to failed queue (no delay needed).

---

**Line 251: Publish to DLX Queue**

```php
$this->getChannel()->basic_publish($newMessage, '', $this->queue . self::FAILED_POSTFIX);
```

**Parameters:**
- `$newMessage`: Message with error details
- `''` (empty string): **Direct to queue** (no exchange routing)
- `$this->queue . self::FAILED_POSTFIX`: `"easyweek.invoice-service.failed"`

**What happens:**
- Message published directly to `.failed` queue
- No exchange routing (empty exchange name)
- Persists in failed queue until manual intervention

**Manual intervention options:**
1. Fix bug → Reprocess messages from failed queue
2. Discard invalid messages
3. Archive for audit/compliance

---

**Line 252: ACK Original Message**

```php
$message->ack();
```

**Why ACK?**
- We've moved message to failed queue
- Original message no longer needed
- Prevents redelivery

---

**Line 255: Track Failed Metrics**

```php
$this->statsD->end(EventExitStatusTag::FAILED, $eventRetryStatusTag);
```

**Metrics emitted:**
```
event_processed_duration{
    nano_service_name="invoice-service",
    event_name="invoice.created",
    exit_status="failed",
    retry="last"
} = 45.67 ms
```

**Message permanently failed!** Now in `.failed` queue.

---

## 3.3 Retry Strategy Deep Dive

### Backoff Calculation: `getBackoff()`

**Reference:** [NanoConsumer.php:272-283](../src/NanoConsumer.php#L272-L283)

```php
private function getBackoff(int $retryCount): int
{
    if (is_array($this->backoff)) {
        $count = $retryCount - 1;
        $lastIndex = count($this->backoff) - 1;
        $index = min($count, $lastIndex);

        return $this->backoff[$index] * 1000;
    }

    return $this->backoff * 1000;
}
```

---

### Array Backoff (Exponential)

**Configuration:**
```php
$consumer->backoff([1, 5, 60])->tries(5);
```

**Calculation:**
```
retryCount = 1 → index = 0 → backoff[0] = 1  → 1000ms  (1 second)
retryCount = 2 → index = 1 → backoff[1] = 5  → 5000ms  (5 seconds)
retryCount = 3 → index = 2 → backoff[2] = 60 → 60000ms (1 minute)
retryCount = 4 → index = 3 → min(3, 2) = 2   → 60000ms (1 minute)
retryCount = 5 → index = 4 → min(4, 2) = 2   → 60000ms (1 minute)
```

**Key insight:** `min($count, $lastIndex)`
- After exhausting array, uses last value
- Allows `tries > backoff.length`
- Example: `backoff([1, 5, 60])->tries(10)` → uses 60s for retries 4-10

---

### Scalar Backoff (Fixed)

**Configuration:**
```php
$consumer->backoff(30)->tries(3);
```

**Calculation:**
```
retryCount = 1 → 30 * 1000 = 30000ms (30 seconds)
retryCount = 2 → 30 * 1000 = 30000ms (30 seconds)
retryCount = 3 → 30 * 1000 = 30000ms (30 seconds)
```

**Use case:** Simple fixed delay between retries.

---

### Retry Tag Mapping

**Reference:** [NanoConsumer.php:285-292](../src/NanoConsumer.php#L285-L292)

```php
private function getRetryTag(int $retryCount): EventRetryStatusTag
{
    return match ($retryCount) {
        1 => EventRetryStatusTag::FIRST,
        $this->tries => EventRetryStatusTag::LAST,
        default => EventRetryStatusTag::RETRY,
    };
}
```

**With `tries(3)`:**
```
Attempt 1 (retryCount=1) → FIRST  → tag: 'first'
Attempt 2 (retryCount=2) → RETRY  → tag: 'retry'
Attempt 3 (retryCount=3) → LAST   → tag: 'last'
```

**With `tries(5)`:**
```
Attempt 1 → FIRST
Attempt 2 → RETRY
Attempt 3 → RETRY
Attempt 4 → RETRY
Attempt 5 → LAST
```

**Why these tags?**
- **Metrics filtering**: Alert on high `retry='last'` failures
- **Sampling rates**: Sample 100% of LAST attempts, 10% of FIRST attempts
- **Dashboards**: Show first-attempt success rate vs retry success rate

---

### Complete Retry Timeline Example

**Configuration:**
```php
$consumer
    ->events('invoice.created')
    ->backoff([1, 5, 60])
    ->tries(3)
    ->consume(function($msg) {
        throw new Exception("External API unavailable");
    });
```

**Timeline:**

```
T=0:00  Attempt 1 (FIRST)
        ├─ Process message
        ├─ Throw exception
        ├─ Publish with x-delay=1000ms, x-retry-count=1
        └─ Metric: exit_status=failed, retry=first

T=0:01  Attempt 2 (RETRY) - after 1 second delay
        ├─ Process message
        ├─ Throw exception
        ├─ Publish with x-delay=5000ms, x-retry-count=2
        └─ Metric: exit_status=failed, retry=retry

T=0:06  Attempt 3 (LAST) - after 5 second delay
        ├─ Process message
        ├─ Throw exception
        ├─ Publish to .failed queue
        └─ Metric: exit_status=failed, retry=last

        Message now in DLX (.failed) queue
```

**Total time:** 6 seconds from first attempt to DLX

---

### Retry Best Practices

#### 1. Choose Backoff Strategy

**Quick retries (transient errors):**
```php
->backoff([0, 1, 5])  // Immediate, 1s, 5s
```
Good for: Network blips, rate limit resets, cache warming

---

**Gradual backoff (external dependencies):**
```php
->backoff([5, 30, 300])  // 5s, 30s, 5min
```
Good for: External API downtime, database recovery, service restarts

---

**Aggressive backoff (long delays):**
```php
->backoff([60, 300, 3600])  // 1min, 5min, 1hour
```
Good for: Manual intervention needed, scheduled maintenance windows

---

#### 2. Match Tries to Problem

**Transient errors:**
```php
->tries(5)  // Many quick retries
->backoff([1, 1, 2, 5, 10])
```

---

**External API with circuit breaker:**
```php
->tries(3)  // Few attempts, fail fast
->backoff([5, 30, 60])
```

---

**Data validation errors:**
```php
->tries(1)  // Don't retry (will fail again)
// In callback: if validation fails, don't throw (log and skip)
```

---

#### 3. Idempotency

**Problem:** Retry means message processed multiple times.

**Solution:** Make processing idempotent.

```php
$consumer->consume(function($message) {
    $payload = $message->getPayload();

    // ✅ Idempotent (check before creating)
    $invoice = Invoice::firstOrCreate(['id' => $payload['id']], $payload);

    // ❌ Not idempotent (creates duplicate on retry)
    $invoice = Invoice::create($payload);
});
```

**Idempotency strategies:**
1. **Unique constraints**: Database prevents duplicates
2. **Check-then-create**: `firstOrCreate()`, `updateOrCreate()`
3. **Deduplication ID**: Store `message_id` in processed table
4. **State machine**: Only allow valid state transitions

---

## 3.4 Metrics Collection

### Metrics Emitted During Processing

#### 1. Event Started Count

**Emitted:** Line 195 (`$this->statsD->start()`)

**Metric:**
```
event_started_count{
    nano_service_name="invoice-service",
    event_name="invoice.created",
    retry="first"
} += 1
```

**Purpose:** Total events received (before processing).

**Use:**
- Event rate dashboard
- Compare started vs completed (detect hangs)

---

#### 2. Payload Size

**Emitted:** Lines 186-192

**Metric:**
```
rmq_consumer_payload_bytes{
    nano_service_name="invoice-service",
    event_name="invoice.created"
} = 1234 (histogram)
```

**Purpose:** Distribution of message sizes.

**Use:**
- Identify large messages
- Detect payload size growth
- Alert on abnormally large messages (DoS, bug)

---

#### 3. Processing Duration (Success)

**Emitted:** Line 210 (`$this->statsD->end(SUCCESS)`)

**Metric:**
```
event_processed_duration{
    nano_service_name="invoice-service",
    event_name="invoice.created",
    exit_status="success",
    retry="first"
} = 123.45 (ms)
```

**Purpose:** How long processing took.

**Use:**
- P95/P99 latency tracking
- Identify slow events
- Detect performance regressions

---

#### 4. Processing Duration (Failed)

**Emitted:** Lines 231 or 255 (`$this->statsD->end(FAILED)`)

**Metric:**
```
event_processed_duration{
    nano_service_name="invoice-service",
    event_name="invoice.created",
    exit_status="failed",
    retry="last"
} = 50.23 (ms)
```

**Purpose:** How long failed processing took.

**Use:**
- Compare success vs failure duration
- Identify fast-failing vs slow-failing
- Timeout analysis

---

#### 5. ACK Failures

**Emitted:** Line 206

**Metric:**
```
rmq_consumer_ack_failed_total{
    nano_service_name="invoice-service",
    event_name="invoice.created"
} += 1
```

**Purpose:** Track ACK failures (connection issues).

**Use:**
- Alert on RabbitMQ connectivity problems
- Detect channel/connection closures
- Correlation with RabbitMQ restarts

---

#### 6. DLX Events

**Emitted:** Line 244

**Metric:**
```
rmq_consumer_dlx_total{
    nano_service_name="invoice-service",
    event_name="invoice.created",
    reason="max_retries_exceeded"
} += 1
```

**Purpose:** Track permanently failed messages.

**Use:**
- Alert on DLX queue growth
- Dashboard: failure rate by event type
- SLO: < 0.1% events to DLX

---

### Metrics Dashboard Example

**Prometheus Queries:**

```promql
# Event processing rate
rate(event_started_count[5m])

# Success rate
rate(event_processed_duration{exit_status="success"}[5m])
/ rate(event_started_count[5m])

# P95 latency
histogram_quantile(0.95,
  rate(event_processed_duration_bucket[5m])
)

# DLX rate (permanent failures)
rate(rmq_consumer_dlx_total[5m])

# Retry rate (first attempt failures)
rate(event_processed_duration{exit_status="failed",retry="first"}[5m])
```

**Grafana Dashboard Panels:**
1. **Event Rate** - Line chart of events/second
2. **Success Rate** - Gauge showing %
3. **Latency** - Heatmap of P50/P95/P99
4. **Retry Breakdown** - Pie chart of first/retry/last
5. **DLX Queue Depth** - Alert if > threshold
6. **Top Failed Events** - Table sorted by DLX count

---

## 3.5 Error Handling Patterns

### Transient vs Permanent Failures

#### Transient Failures (Retry)

**Characteristics:**
- External service temporarily unavailable
- Network timeout
- Rate limit exceeded (will reset)
- Lock contention
- Temporary resource exhaustion

**Examples:**
```php
// ✅ Retry these
- ConnectionException (database, API)
- TimeoutException
- RateLimitException (if 429 with Retry-After header)
- LockTimeoutException
- TemporaryFileSystemException
```

**Strategy:** Throw exception, let consumer retry with backoff.

```php
$consumer->consume(function($message) {
    try {
        $api->call();
    } catch (RateLimitException $e) {
        // Let consumer retry
        throw $e;
    }
});
```

---

#### Permanent Failures (Don't Retry)

**Characteristics:**
- Invalid data (will never be valid)
- Business rule violation
- 404 Not Found (resource doesn't exist)
- 401 Unauthorized (credentials wrong)
- Schema validation failure

**Examples:**
```php
// ❌ Don't retry these
- ValidationException (invalid payload)
- RecordNotFoundException (404)
- UnauthorizedException (401)
- SchemaException (malformed JSON)
- BusinessRuleException (order already paid)
```

**Strategy:** Don't throw exception, log and ACK.

```php
$consumer->consume(function($message) {
    try {
        $validator->validate($message->getPayload());
    } catch (ValidationException $e) {
        // Don't retry (will fail again)
        Log::warning("Invalid payload", [
            'event' => $message->getEventName(),
            'error' => $e->getMessage()
        ]);
        // Don't throw - ACK and skip
        return;
    }

    // Process valid message...
});
```

---

### When to Throw vs When to Handle

#### Throw Exception (Let Consumer Retry)

```php
✅ Throw when:
- External dependency unavailable
- Timeout waiting for resource
- Database connection lost
- API returns 5xx error
- Temporary file system error
```

**Pattern:**
```php
$consumer->consume(function($message) {
    // Throw to trigger retry
    $result = ExternalAPI::post('/endpoint', $data);

    if (!$result->success) {
        throw new Exception("API call failed");
    }
});
```

---

#### Handle Gracefully (No Retry)

```php
✅ Handle when:
- Validation fails (permanent)
- Business rule prevents processing
- Resource not found (404)
- Duplicate processing detected
```

**Pattern:**
```php
$consumer->consume(function($message) {
    $invoice = Invoice::find($message->getPayload()['id']);

    if (!$invoice) {
        // Resource doesn't exist - log and skip
        Log::warning("Invoice not found", ['id' => $message->getPayload()['id']]);
        return; // ACK and skip (no exception)
    }

    // Process invoice...
});
```

---

### Custom Callbacks: `catch()` and `failed()`

#### Catch Callback (Retry Errors)

**When:** Called on each retry (before max attempts).

**Purpose:** Log, alert, or take action during retries.

```php
$consumer
    ->catch(function(Throwable $exception, NanoServiceMessage $message) {
        // Log error for investigation
        Log::error("Processing failed, will retry", [
            'event' => $message->getEventName(),
            'retry_count' => $message->getRetryCount(),
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Send to error tracking (Sentry, Bugsnag)
        Sentry::captureException($exception, [
            'extra' => [
                'event_name' => $message->getEventName(),
                'retry_count' => $message->getRetryCount()
            ]
        ]);
    })
    ->consume(...);
```

**Tip:** Don't throw in catch callback (will be silently ignored).

---

#### Failed Callback (Permanent Failures)

**When:** Called after max retries exceeded.

**Purpose:** Alert ops, create incident, trigger remediation.

```php
$consumer
    ->failed(function(Throwable $exception, NanoServiceMessage $message) {
        // Alert operations team
        Slack::send('#ops-alerts', [
            'title' => '🚨 Message permanently failed',
            'event' => $message->getEventName(),
            'error' => $exception->getMessage(),
            'retry_count' => $message->getRetryCount(),
            'payload' => $message->getPayload()
        ]);

        // Create PagerDuty incident
        PagerDuty::createIncident([
            'title' => "RabbitMQ message failed: {$message->getEventName()}",
            'severity' => 'error',
            'details' => [
                'exception' => $exception->getMessage(),
                'event' => $message->getEventName()
            ]
        ]);

        // Store in error database for analysis
        FailedMessage::create([
            'event_name' => $message->getEventName(),
            'payload' => $message->getPayload(),
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'retry_count' => $message->getRetryCount()
        ]);
    })
    ->consume(...);
```

---

### Logging and Alerting Strategies

#### Log Levels by Scenario

```php
// DEBUG: Successful processing (verbose mode only)
Log::debug("Event processed", ['event' => $message->getEventName()]);

// INFO: Business events (user actions)
Log::info("Invoice created", ['invoice_id' => $invoice->id]);

// WARNING: Validation failures, skipped messages
Log::warning("Invalid payload, skipped", ['event' => $message->getEventName()]);

// ERROR: Retryable failures (will be retried)
Log::error("API call failed, retrying", [
    'event' => $message->getEventName(),
    'retry_count' => $message->getRetryCount()
]);

// CRITICAL: Permanent failures (DLX)
Log::critical("Message permanently failed", [
    'event' => $message->getEventName(),
    'retry_count' => $message->getRetryCount()
]);
```

---

#### Alert Thresholds

**Metrics-based alerts (Prometheus):**

```yaml
# High retry rate
- alert: HighRetryRate
  expr: |
    rate(event_processed_duration{exit_status="failed",retry="first"}[5m])
    / rate(event_started_count[5m]) > 0.1
  annotations:
    summary: "{{ $labels.event_name }} has 10%+ retry rate"

# DLX queue growing
- alert: DLXQueueGrowing
  expr: |
    rate(rmq_consumer_dlx_total[5m]) > 1
  annotations:
    summary: "{{ $labels.event_name }} sending >1/sec to DLX"

# High last-attempt failures
- alert: HighLastAttemptFailures
  expr: |
    rate(event_processed_duration{exit_status="failed",retry="last"}[5m]) > 0.1
  annotations:
    summary: "{{ $labels.event_name }} permanent failures >0.1/sec"
```

---

#### Circuit Breaker Pattern

**Problem:** External service down → all retries fail → waste resources.

**Solution:** Stop retrying after consecutive failures (circuit open).

```php
class ExternalAPIClient
{
    private int $consecutiveFailures = 0;
    private const FAILURE_THRESHOLD = 5;
    private const CIRCUIT_OPEN_DURATION = 60; // seconds
    private ?int $circuitOpenedAt = null;

    public function call()
    {
        // Check if circuit is open
        if ($this->isCircuitOpen()) {
            throw new CircuitOpenException("Circuit breaker open, not attempting call");
        }

        try {
            $result = $this->http->post('/endpoint');
            $this->consecutiveFailures = 0; // Reset on success
            return $result;
        } catch (Exception $e) {
            $this->consecutiveFailures++;

            if ($this->consecutiveFailures >= self::FAILURE_THRESHOLD) {
                $this->circuitOpenedAt = time();
                Log::warning("Circuit breaker opened", [
                    'consecutive_failures' => $this->consecutiveFailures
                ]);
            }

            throw $e;
        }
    }

    private function isCircuitOpen(): bool
    {
        if (!$this->circuitOpenedAt) {
            return false;
        }

        // Check if circuit should close (timeout expired)
        if (time() - $this->circuitOpenedAt > self::CIRCUIT_OPEN_DURATION) {
            $this->circuitOpenedAt = null;
            $this->consecutiveFailures = 0;
            Log::info("Circuit breaker closed (timeout expired)");
            return false;
        }

        return true;
    }
}
```

**Usage in consumer:**
```php
$consumer->consume(function($message) use ($apiClient) {
    try {
        $apiClient->call();
    } catch (CircuitOpenException $e) {
        // Don't retry immediately - wait for circuit to close
        Log::warning("Circuit open, skipping message");
        return; // ACK and skip
    }
});
```

---

### Summary: Error Handling Decision Tree

```
Exception thrown in callback
    │
    ├─ Is it a validation error?
    │   └─ YES → Log warning, return (no throw) → ACK
    │
    ├─ Is resource not found (404)?
    │   └─ YES → Log warning, return (no throw) → ACK
    │
    ├─ Is it idempotency check (already processed)?
    │   └─ YES → Log info, return (no throw) → ACK
    │
    ├─ Is external service unavailable (5xx, timeout)?
    │   └─ YES → Throw → Retry with backoff
    │
    ├─ Is it a database connection error?
    │   └─ YES → Throw → Retry with backoff
    │
    ├─ Is circuit breaker open?
    │   └─ YES → Log warning, return (no throw) → ACK (skip)
    │
    └─ Unknown error
        └─ Throw → Retry with backoff
```

---

## Appendix A: Related Incidents

### 2026-01-16: RabbitMQ Channel Exhaustion (SEV2)

**Summary**: Channel leak caused 17,840 channels on RabbitMQ cluster, 97% reduction after fix.

**Root cause**: Not reusing shared connections/channels across instances.

**Fix**: Static `$sharedConnection` and `$sharedChannel` with pooling.

**Details**: [incidents/2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2](../incidents/2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2)

**Lessons learned:**
- Always use shared connections in long-running workers
- Monitor channel count in production
- Connection pooling is critical for PHP workers

---

## Appendix B: Further Reading

- [RabbitMQ Documentation](https://www.rabbitmq.com/documentation.html)
- [AMQP 0-9-1 Protocol Specification](https://www.rabbitmq.com/amqp-0-9-1-reference.html)
- [php-amqplib Library](https://github.com/php-amqplib/php-amqplib)
- [Delayed Message Plugin](https://github.com/rabbitmq/rabbitmq-delayed-message-exchange)

---

**End of Document**

*This document will be expanded with additional sections as development continues.*
