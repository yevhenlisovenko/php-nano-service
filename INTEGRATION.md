# NanoService Integration Rules

**Version:** 6.6+
**Last Updated:** 2026-01-28
**Status:** Production Ready

---

## Table of Contents

1. [Architecture Requirements](#architecture-requirements)
2. [Development Rules](#development-rules)
3. [Environment Variables](#environment-variables)
4. [Event Consumption & Idempotency](#event-consumption--idempotency)
5. [Logging](#logging)
6. [RabbitMQ Resilience](#rabbitmq-resilience)
7. [Database Architecture](#database-architecture)
8. [Metrics Integration](#metrics-integration)
9. [Testing Requirements](#testing-requirements)
10. [Deployment Checklist](#deployment-checklist)

---

## Architecture Requirements

### 1. Service Types

NanoService is designed for **event-driven microservices**, NOT for:
- ❌ Large monoliths with CRUD interfaces
- ❌ Laravel applications (use Laravel's native patterns instead)
- ✅ Slim-based microservices
- ✅ Event consumers/publishers
- ✅ Background workers

### 2. Component Structure

Every NanoService MUST follow this structure:

```
src/
├── Model/              # Eloquent models (Illuminate/Database)
├── Repository/         # All database queries (ONLY place for DB access)
├── Services/           # Business logic
├── Console/            # CLI commands (workers)
├── Action/             # Controller actions (single responsibility)
├── Controller/         # Thin controllers (delegate to Actions)
├── Request/            # Request validation (FormRequest pattern)
├── Response/           # Response DTOs
└── Factory/            # Object creation (Logger, connections)
```

#### ✅ Correct: DTO Pattern

```php
// Request DTO
final class CreateInvoiceRequest
{
    public function __construct(
        public readonly string $customerId,
        public readonly int $amountCents,
        public readonly string $currency
    ) {}
}

// Response DTO
final class InvoiceResource
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly int $amountCents
    ) {}

    public static function fromModel(Invoice $invoice): self
    {
        return new self(
            id: $invoice->getId(),
            status: $invoice->getStatus(),
            amountCents: $invoice->getAmountCents()
        );
    }
}
```

#### ❌ Wrong: No DTOs

```php
// BAD - Array everywhere, no type safety
public function create(array $data): array {
    $invoice = $this->repository->create($data);
    return $invoice->toArray();
}
```

---

## Development Rules

### 1. Composer Usage (CRITICAL)

**❌ NEVER run composer on your host machine!**

Always run inside Docker to ensure consistent PHP versions:

```bash
# ✅ CORRECT
docker run --rm -v "$(pwd):/app" -w /app composer:latest composer update
docker compose exec service-php composer require package/name

# ❌ WRONG
composer update  # DON'T DO THIS!
```

**Why?** PHP version consistency, platform requirements, reproducible builds.

### 2. Docker Compose Commands

```bash
# ✅ CORRECT - Modern syntax
docker compose up -d
docker compose exec service bash

# ❌ WRONG - Deprecated
docker-compose up -d
```

### 3. strict_types Declaration

**Every PHP file MUST have:**

```php
<?php

declare(strict_types=1);

namespace App\...;
```

### 4. Use \Throwable, Not \Exception

```php
// ✅ GOOD - Catches both Exception and Error (TypeError, etc.)
} catch (\Throwable $e) {

// ❌ BAD - Misses PHP Errors
} catch (\Exception $e) {
```

### 5. Final Classes & Interfaces

**Pattern:** Classes are `final` (best practice), but you need interfaces for mocking in tests:

```php
// ✅ GOOD - Interface for testing
interface HookJobRepositoryInterface {
    public function createJob(...): string;
}

final class HookJobRepository implements HookJobRepositoryInterface {
    // Implementation
}

// In Actions - depend on interface
public function __construct(
    private HookJobRepositoryInterface $repository
) {}

// In tests - mock the interface
$repo = $this->createMock(HookJobRepositoryInterface::class);
```

### 6. Request-DTO-Action-Resource Pattern

**Flow:**
```
HTTP Request → FormRequest (validate) → DTO → Action (execute) → Resource → JSON
```

**Example:**

```php
// 1. FormRequest (src/Request/CreateOrderRequest.php)
final class CreateOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'customer_id' => 'required|string',
            'amount_cents' => 'required|int|min:0',
        ];
    }

    public function getCustomerId(): string {
        return $this->get('customer_id');
    }
}

// 2. DTO (src/DTO/CreateOrderDTO.php)
final class CreateOrderDTO
{
    public function __construct(
        private readonly string $customerId,
        private readonly int $amountCents
    ) {}

    public function getCustomerId(): string { return $this->customerId; }
    public function getAmountCents(): int { return $this->amountCents; }
}

// 3. Action (src/Action/CreateOrderAction.php)
final class CreateOrderAction
{
    public function __construct(
        private OrderRepositoryInterface $repository
    ) {}

    public function execute(CreateOrderDTO $dto): OrderResultDTO
    {
        $orderId = $this->repository->create(
            $dto->getCustomerId(),
            $dto->getAmountCents()
        );

        return new OrderResultDTO($orderId, 'created');
    }
}

// 4. Resource (src/Resource/OrderResource.php)
final class OrderResource extends JsonResource
{
    public function toArray(): array
    {
        return [
            'order_id' => $this->resource->getId(),
            'status' => 'created',
        ];
    }
}

// 5. Controller (src/Controller/OrderController.php)
public function create(Request $request, Response $response): Response
{
    try {
        $formRequest = new CreateOrderRequest($request);
        $formRequest->validate();

        $dto = new CreateOrderDTO(
            customerId: $formRequest->getCustomerId(),
            amountCents: $formRequest->getAmountCents()
        );

        $result = $this->createAction->execute($dto);
        $resource = new OrderResource($result);

        return $this->renderer->resourcedJson($response, $resource);

    } catch (ValidationException $e) {
        return $this->renderer->error($response, 'validation_failed', $e->getMessage(), 422);
    }
}
```

### 7. Try-Finally Pattern for Metrics

```php
$startTime = microtime(true);
$status = 'success';

try {
    // Your code
    return $response;
} catch (\Throwable $e) {
    $status = 'failed';
    throw $e;
} finally {
    // ALWAYS executed - even on return or exception
    $duration = microtime(true) - $startTime;
    $this->statsd->timing('operation_ms', $duration, ['status' => $status]);
}
```

### 8. Code Formatting

```bash
# Run Laravel Pint before committing
docker compose exec service-php vendor/bin/pint
```

---

## Environment Variables

### Critical Rule: NO FALLBACK VALUES

**❌ NEVER DO THIS:**

```php
// BAD - Hides configuration errors
$host = $_ENV['DB_BOX_HOST'] ?? 'localhost';
$enabled = $_ENV['FEATURE_FLAG'] ?? true;
```

**✅ ALWAYS DO THIS:**

```php
// GOOD - Fails fast with clear error
$requiredVars = ['DB_BOX_HOST', 'DB_BOX_PORT', 'DB_BOX_NAME', 'DB_BOX_USER', 'DB_BOX_PASS'];
foreach ($requiredVars as $var) {
    if (!isset($_ENV[$var])) {
        throw new \RuntimeException("Missing required environment variable: {$var}");
    }
}
$host = $_ENV['DB_BOX_HOST'];
```

### Why No Fallbacks?

1. **Production debugging becomes impossible** — Is the service using the config you think it's using?
2. **Silent failures** — Service starts but uses wrong database/cache/queue
3. **Security risk** — Fallback to `localhost` might connect to wrong infrastructure
4. **Clear errors are better** — Fail fast during deployment, not at 3am in production

### Configuration via DI Container

```php
// config/container.php
'database.config' => function () {
    $requiredVars = ['DB_BOX_HOST', 'DB_BOX_PORT', 'DB_BOX_NAME', 'DB_BOX_USER', 'DB_BOX_PASS', 'DB_BOX_SCHEMA'];
    foreach ($requiredVars as $var) {
        if (!isset($_ENV[$var])) {
            throw new \RuntimeException("Missing required: {$var}");
        }
    }

    return [
        'host' => $_ENV['DB_BOX_HOST'],
        'port' => (int) $_ENV['DB_BOX_PORT'],
        'database' => $_ENV['DB_BOX_NAME'],
        'username' => $_ENV['DB_BOX_USER'],
        'password' => $_ENV['DB_BOX_PASS'],
        'schema' => $_ENV['DB_BOX_SCHEMA'],
    ];
},
```

---

## Event Consumption & Idempotency

### Critical: Duplicate Event Prevention

**Every event consumer MUST implement idempotency** to prevent duplicate processing.

### Pattern: Idempotency Key Table

```sql
CREATE TABLE {schema}.event_log (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    event_id UUID NOT NULL UNIQUE,  -- Idempotency key from message
    event_name VARCHAR(255) NOT NULL,
    payload JSONB NOT NULL,
    status VARCHAR(50) NOT NULL,  -- pending, processing, completed, failed
    received_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    processed_at TIMESTAMPTZ,
    error_message TEXT,
    attempts INTEGER NOT NULL DEFAULT 0,

    INDEX idx_event_log_status (status),
    INDEX idx_event_log_received_at (received_at)
);
```

### Eloquent Model

```php
namespace App\Model;

use Illuminate\Database\Eloquent\Model;

final class EventLog extends Model
{
    protected $table = 'myservice.event_log';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'event_id', 'event_name', 'payload', 'status',
        'received_at', 'processed_at', 'error_message', 'attempts'
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function getId(): string { return $this->id; }
    public function getEventId(): string { return $this->event_id; }
    public function isPending(): bool { return $this->status === 'pending'; }
    public function isCompleted(): bool { return $this->status === 'completed'; }
}
```

### Consumer with Idempotency Check

```php
namespace App\Console;

use AlexFN\NanoService\NanoConsumer;
use AlexFN\NanoService\NanoServiceMessage;
use App\Repository\EventLogRepository;

final class EventConsumerCommand extends Command
{
    protected static $defaultName = 'consumer:run';

    private EventLogRepository $eventLog;
    private NanoConsumer $consumer;

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->eventLog = new EventLogRepository();

        $this->consumer = new NanoConsumer();
        $this->consumer
            ->init('myservice.events', ['order.created', 'order.updated'])
            ->tries(3)
            ->consume(function (NanoServiceMessage $message) {
                $this->handleEvent($message);
            });
    }

    private function handleEvent(NanoServiceMessage $message): void
    {
        $eventId = $message->getId();  // UUID from message
        $eventName = $message->getEvent();
        $payload = $message->getPayload();

        // CRITICAL: Check idempotency key FIRST
        if ($this->eventLog->exists($eventId)) {
            $this->logger->info('Event already processed (duplicate), skipping', [
                'event_id' => $eventId,
                'event_name' => $eventName,
            ]);
            return;  // Skip duplicate
        }

        // Store event with 'pending' status (within transaction)
        $logId = $this->eventLog->createPending($eventId, $eventName, $payload);

        try {
            // Update to 'processing'
            $this->eventLog->markAsProcessing($logId);

            // Process business logic
            $this->processBusinessLogic($payload);

            // Mark as 'completed'
            $this->eventLog->markAsCompleted($logId);

        } catch (\Throwable $e) {
            // Mark as 'failed' with error message
            $this->eventLog->markAsFailed($logId, $e->getMessage());
            throw $e;  // Re-throw for retry logic
        }
    }
}
```

### Repository Implementation

```php
namespace App\Repository;

use App\Model\EventLog;
use App\Database\DatabaseManager;

final class EventLogRepository
{
    public function exists(string $eventId): bool
    {
        return EventLog::query()
            ->where('event_id', $eventId)
            ->exists();
    }

    public function createPending(string $eventId, string $eventName, array $payload): string
    {
        return DatabaseManager::transaction(function () use ($eventId, $eventName, $payload) {
            $log = new EventLog();
            $log->event_id = $eventId;
            $log->event_name = $eventName;
            $log->payload = $payload;
            $log->status = 'pending';
            $log->received_at = now();
            $log->save();
            return $log->getId();
        });
    }

    public function markAsProcessing(string $logId): void
    {
        EventLog::query()
            ->where('id', $logId)
            ->update(['status' => 'processing']);
    }

    public function markAsCompleted(string $logId): void
    {
        EventLog::query()
            ->where('id', $logId)
            ->update([
                'status' => 'completed',
                'processed_at' => now(),
            ]);
    }

    public function markAsFailed(string $logId, string $error): void
    {
        EventLog::query()
            ->where('id', $logId)
            ->update([
                'status' => 'failed',
                'error_message' => $error,
                'processed_at' => now(),
            ]);
    }
}
```

---

## Logging

### Use NanoService LoggerFactory

```php
use AlexFN\NanoService\Clients\LoggerFactory;

// config/container.php
LoggerInterface::class => function (ContainerInterface $container) {
    return (new LoggerFactory())
        ->addJsonConsoleHandler()  // Loki-compatible JSON
        ->createLogger('myservice');
},
```

### JSON Output Format (Loki Compatible)

```json
{
  "message": "Order created successfully",
  "context": {
    "order_id": "123e4567-e89b-12d3-a456-426614174000",
    "customer_id": "cust_abc123",
    "amount_cents": 5000
  },
  "level": 200,
  "level_name": "INFO",
  "channel": "myservice",
  "datetime": "2026-01-28T10:30:45.123456+00:00"
}
```

### Structured Logging Pattern

```php
// ✅ GOOD - Structured context
$this->logger->info('Order created', [
    'order_id' => $order->getId(),
    'customer_id' => $order->getCustomerId(),
    'amount_cents' => $order->getAmountCents(),
    'currency' => $order->getCurrency(),
]);

// ❌ BAD - String concatenation
$this->logger->info("Order {$order->getId()} created for customer {$customerId}");
```

### Log Levels

- **DEBUG**: Development-only details (SQL queries, cache hits)
- **INFO**: Normal operation events (order created, payment processed)
- **WARNING**: Recoverable issues (RabbitMQ connection lost, retry scheduled)
- **ERROR**: Failed operations (payment declined, external API timeout)
- **CRITICAL**: Service-wide failures (database unreachable, all workers down)

---

## RabbitMQ Resilience

### Critical: Handle RabbitMQ Outages

**The circuit breaker pattern is MANDATORY** for all publishers.

### Pattern: Outage Circuit Breaker

```php
namespace App\Console;

use AlexFN\NanoService\NanoPublisher;

final class PublisherCommand extends Command
{
    private NanoPublisher $publisher;
    private int $outageSleepSeconds;

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->outageSleepSeconds = (int) $_ENV['OUTAGE_SLEEP_SECONDS'];

        $this->publisher = new NanoPublisher();

        // Set outage callbacks for logging
        $this->publisher->setOutageCallbacks(
            fn(int $sleep) => $this->logger->warning('RabbitMQ connection lost, entering outage mode', [
                'sleep_seconds' => $sleep,
            ]),
            fn() => $this->logger->info('RabbitMQ connection restored, resuming operation'),
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        while (true) {
            try {
                // Check RabbitMQ health with circuit breaker
                if (!$this->publisher->ensureConnectionOrSleep($this->outageSleepSeconds)) {
                    continue;  // Skip processing during outage
                }

                // Process batch of jobs
                $jobs = $this->fetchPendingJobs();
                foreach ($jobs as $job) {
                    $this->publishJob($job);
                }

                usleep(100000);  // 100ms between batches

            } catch (\Throwable $e) {
                $this->logger->error('Publisher loop error', [
                    'error' => $e->getMessage(),
                ]);
                sleep(5);
            }
        }

        return Command::SUCCESS;
    }
}
```

### Why Circuit Breaker is Critical

**Without circuit breaker:**
- ❌ Service hammers database every 100ms trying to publish
- ❌ Database write IOPS spike to 10,000+ during outage
- ❌ Database becomes overloaded
- ❌ Other services affected

**With circuit breaker:**
- ✅ Service enters "outage mode" on first failure
- ✅ Sleeps 30 seconds before next attempt
- ✅ Database write IOPS stay normal (2 writes per minute)
- ✅ Automatic recovery when RabbitMQ restores

### Environment Variables

```bash
OUTAGE_SLEEP_SECONDS=30  # Sleep duration during outage
BATCH_SIZE=100           # Jobs per batch
```

---

## Database Architecture

### Each NanoService MUST Have Its Own Database

```sql
-- Local development
CREATE DATABASE myservice;
CREATE SCHEMA myservice;
CREATE USER myservice WITH PASSWORD 'secret';
GRANT ALL ON SCHEMA myservice TO myservice;

-- Production (managed by GitLab CI)
-- Database name: nanoservice-myservice-{namespace}
-- Schema: myservice
```

### Event Status Tracking

Every event-driven service MUST store:
- ✅ Which events were received (idempotency)
- ✅ Current processing status (pending/processing/completed/failed)
- ✅ Timestamps (received_at, processed_at)
- ✅ Error messages (for debugging)
- ✅ Retry attempts

### Cleanup: 30-Day Retention

```php
namespace App\Console;

final class CleanupCommand extends Command
{
    protected static $defaultName = 'cleanup:old-events';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdo = DatabaseManager::getPdo();

        // Call PostgreSQL function for atomic cleanup
        $stmt = $pdo->query('SELECT myservice.cleanup_old_events()');
        $deletedCount = $stmt->fetchColumn();

        $this->logger->info('Cleanup completed', [
            'deleted_count' => (int) $deletedCount,
            'retention_days' => 30,
        ]);

        return Command::SUCCESS;
    }
}
```

### PostgreSQL Cleanup Function

```sql
CREATE OR REPLACE FUNCTION myservice.cleanup_old_events()
RETURNS INTEGER
LANGUAGE plpgsql
AS $$
DECLARE
    deleted_count INTEGER;
BEGIN
    DELETE FROM myservice.event_log
    WHERE received_at < NOW() - INTERVAL '30 days'
      AND status IN ('completed', 'failed');

    GET DIAGNOSTICS deleted_count = ROW_COUNT;
    RETURN deleted_count;
END;
$$;
```

### Kubernetes CronJob

```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: myservice-cleanup
spec:
  schedule: "0 2 * * *"  # Daily at 2 AM UTC
  jobTemplate:
    spec:
      template:
        spec:
          containers:
          - name: cleanup
            image: registry.gitlab.com/mycompany/myservice:latest
            command: ["php", "bin/console", "cleanup:old-events"]
            envFrom:
            - configMapRef:
                name: myservice-config
            - secretRef:
                name: myservice-secrets
```

---

## Metrics Integration

### StatsD Configuration (Already in GitLab CI)

```bash
# Environment variables (auto-injected by deployment)
STATSD_ENABLED=true
STATSD_HOST=statsd-exporter.monitoring.svc.cluster.local
STATSD_PORT=8125
STATSD_NAMESPACE=myservice
STATSD_SAMPLE_OK=0.1      # 10% sampling for success events
STATSD_SAMPLE_PAYLOAD=0.05  # 5% sampling for payload size
```

### Required Metrics

#### 1. RabbitMQ Metrics (Automatic)

Provided by `NanoPublisher` and `NanoConsumer`:

```
rmq_connection_total{service, status}
rmq_connection_active{service}
rmq_connection_errors_total{service, error_type}

rmq_channel_total{service, status}
rmq_channel_active{service}
rmq_channel_errors_total{service, error_type}

rmq_publish_total{service, event, env}
rmq_publish_success_total{service, event, env}
rmq_publish_error_total{service, event, env, error_type}
rmq_publish_duration_ms{service, event, env}
rmq_payload_bytes{service, event, env}

rmq_consumer_dlx_total{service, event}
rmq_consumer_ack_failed_total{service, event}
```

#### 2. Business Metrics (Custom)

```php
use AlexFN\NanoService\Clients\StatsDClient\StatsDClient;

$statsd = new StatsDClient();

// Order processing
$statsd->increment('orders_created_total', [
    'service' => 'myservice',
    'status' => 'success',
]);

// Payment processing
$statsd->histogram('payment_amount_cents', $amountCents, [
    'service' => 'myservice',
    'currency' => $currency,
    'provider' => $provider,
]);

// Database backlog
$statsd->gauge('db_backlog_jobs', $count, [
    'service' => 'myservice',
    'status' => 'pending',
]);
```

#### 3. Event Processing Metrics

```php
use AlexFN\NanoService\Clients\StatsDClient\PublishMetrics;

// Start tracking
$metrics = new PublishMetrics($statsd, 'myservice', 'order.created');
$metrics->start();

try {
    // Process event
    $this->processOrder($message);
    $metrics->recordSuccess();
} catch (\Throwable $e) {
    $metrics->recordFailure($attempts);
    throw $e;
} finally {
    // ALWAYS record metrics (even on exception)
    $metrics->finish();
}
```

### Grafana Dashboards

Monitor these key metrics:

1. **RabbitMQ Health**
   - `rmq_connection_active` (should be 1)
   - `rmq_publish_error_total` (should be near 0)
   - `rmq_publish_duration_ms` P95 (should be < 100ms)

2. **Event Processing**
   - `db_backlog_jobs{status="pending"}` (should be < 1000)
   - Event processing rate (events/sec)
   - Error rate (errors/sec)

3. **Business Metrics**
   - Orders created per minute
   - Revenue per hour
   - Payment success rate

---

## Testing Requirements

### 1. Unit Tests (Mandatory)

```php
namespace Tests\Unit;

use App\Repository\EventLogRepository;
use PHPUnit\Framework\TestCase;

final class EventLogRepositoryTest extends TestCase
{
    public function testCreatePendingEvent(): void
    {
        $repo = new EventLogRepository();

        $logId = $repo->createPending(
            'evt_123',
            'order.created',
            ['order_id' => 'ord_456']
        );

        $this->assertNotEmpty($logId);
    }

    public function testIdempotencyCheckReturnsTrueForDuplicate(): void
    {
        $repo = new EventLogRepository();

        $repo->createPending('evt_123', 'order.created', []);

        $this->assertTrue($repo->exists('evt_123'));
    }
}
```

### 2. Infrastructure Resilience Tests

```php
public function testPublisherHandlesConnectionLoss(): void
{
    $publisher = $this->createPublisherWithFailingConnection();

    $result = $publisher->ensureConnectionOrSleep(0);

    $this->assertFalse($result);
    $this->assertTrue($publisher->isInOutage());
}

public function testPublisherRecoverFromOutage(): void
{
    $publisher = $this->createPublisher();

    // Simulate outage
    $publisher->ensureConnectionOrSleep(0);
    $this->assertTrue($publisher->isInOutage());

    // Simulate recovery
    $publisher->ensureConnectionOrSleep(0);
    $this->assertFalse($publisher->isInOutage());
}
```

### 3. Run Tests in CI

```yaml
# .gitlab-ci.yml
test:
  stage: test
  script:
    - composer install
    - vendor/bin/phpunit --coverage-text
  coverage: '/^\s*Lines:\s*\d+.\d+\%/'
```

---

## Deployment Checklist

### Before Deploying a New NanoService

- [ ] **Architecture**
  - [ ] DTOs implemented for all requests/responses
  - [ ] Repository pattern used (no DB queries in controllers)
  - [ ] All database queries in Repository classes
  - [ ] Models use Eloquent (Illuminate/Database)

- [ ] **Environment Variables**
  - [ ] NO fallback values (all required vars throw on missing)
  - [ ] Database config validated on startup
  - [ ] RabbitMQ config validated on startup
  - [ ] StatsD config validated on startup

- [ ] **Idempotency**
  - [ ] Event log table created
  - [ ] Duplicate events filtered via `event_id`
  - [ ] Status tracking implemented (pending/processing/completed/failed)

- [ ] **Logging**
  - [ ] LoggerFactory used (JSON format)
  - [ ] Structured logging (context arrays, not string concat)
  - [ ] Log levels appropriate (INFO for success, WARNING for retries, ERROR for failures)

- [ ] **RabbitMQ Resilience**
  - [ ] Circuit breaker implemented (`ensureConnectionOrSleep`)
  - [ ] Outage callbacks configured
  - [ ] `OUTAGE_SLEEP_SECONDS` environment variable set

- [ ] **Database**
  - [ ] Own database/schema created
  - [ ] Event log table with indexes
  - [ ] Cleanup function implemented (30-day retention)
  - [ ] Cleanup CronJob deployed

- [ ] **Metrics**
  - [ ] StatsD enabled (`STATSD_ENABLED=true`)
  - [ ] Business metrics instrumented
  - [ ] Grafana dashboard created

- [ ] **Testing**
  - [ ] Unit tests written (>80% coverage)
  - [ ] Infrastructure resilience tests pass
  - [ ] Tests run in CI pipeline

- [ ] **Documentation**
  - [ ] README updated with service purpose
  - [ ] Runbook created (troubleshooting common issues)
  - [ ] Metrics documented (what's "normal" for this service)

---

## Common Mistakes

### ❌ Using Fallback Values

```php
$host = $_ENV['DB_BOX_HOST'] ?? 'localhost';  // DON'T
```

### ❌ DB Queries in Controllers

```php
public function getOrders() {
    $orders = Order::query()->where(...)->get();  // DON'T
}
```

### ❌ No Idempotency Check

```php
public function handleEvent($message) {
    // Missing: Check if event_id already processed
    $this->createOrder($message->getPayload());  // DON'T
}
```

### ❌ No Circuit Breaker

```php
while (true) {
    $jobs = $this->getJobs();
    foreach ($jobs as $job) {
        $this->publisher->publish($job);  // DON'T - hammers DB during outage
    }
}
```

### ❌ String Logging

```php
$this->logger->info("Order $orderId created");  // DON'T
```

---

## Support

For questions or issues, check:
- This documentation
- Example services (hook2event, provider_alphasms_v2, awes.io)
- Test suite in nano-service-main (`tests/Unit/`)

**Production issues:** Check Grafana dashboards first, then logs in Loki.
