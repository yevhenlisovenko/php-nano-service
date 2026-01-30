# Idempotency Strategy for Provider Handlers

**Version:** 1.0 | **Date:** 2026-01-30 | **Status:** Proposal

---

## Problem Statement

### The Duplicate Message Issue

**Scenario:** SMS sent successfully, but error occurs after sending, causing retries and duplicate messages.

**Example Timeline:**
```
10:00:00.000 - Message arrives from RabbitMQ queue
10:00:00.100 - Handler sends SMS to AlphaSMS API ✅ (SMS sent successfully)
10:00:00.200 - Handler calls $notificatorLog->delivered() ✅
10:00:00.250 - ERROR: Network timeout publishing to RabbitMQ ❌
10:00:00.251 - Exception thrown, message NACK'd
10:00:00.252 - RabbitMQ requeues message for retry
10:00:05.000 - [RETRY 1] Handler sends SMS AGAIN ❌ (duplicate!)
10:00:10.000 - [RETRY 2] Handler sends SMS AGAIN ❌ (duplicate!)
10:00:15.000 - [RETRY 3] Handler sends SMS AGAIN ❌ (duplicate!)
```

**Result:** Customer receives 4 SMS messages (1 original + 3 retries), gets charged 4 times.

**Root Cause:** Handlers are **not idempotent** - they don't track which messages have already been processed.

---

## Current State Analysis

### What EXISTS (Webhook Idempotency)

**AlphaSMS-v2** has idempotency for **incoming webhooks**:

✅ **File:** [packages/providers/alphasms-v2/src/Handlers/AlphaSMSWebhook.php](../packages/providers/alphasms-v2/src/Handlers/AlphaSMSWebhook.php:79-92)

```php
// IDEMPOTENCY CHECK: Verify EXACT webhook hasn't been processed
$webhookHash = $this->webhookRepository->calculateWebhookHash($payload);

if ($this->webhookRepository->isDuplicateWebhook($webhookHash)) {
    $this->logger->info("Duplicate webhook detected and ignored");
    return; // Skip duplicate
}

// Store webhook in database
$inserted = $this->webhookRepository->store(
    messageId: $messageId,
    status: $mappedStatus,
    rawPayload: $payload
);
```

**How it works:**
- SHA256 hash of entire webhook payload
- UNIQUE INDEX on `webhook_hash` column
- `ON CONFLICT DO NOTHING` prevents duplicates
- Allows multiple status updates (pending → delivered)
- Blocks network retries of identical payloads

**Documentation:** [packages/providers/alphasms-v2/docs/implementation/2026-01-02-idempotency.md](../packages/providers/alphasms-v2/docs/implementation/2026-01-02-idempotency.md)

### What DOES NOT EXIST (Outgoing Message Idempotency)

❌ **No idempotency tracking for outgoing messages** across **ALL 13 provider handlers**:

**Affected Providers:**
1. `alphasms` - [SendSMS.php](../packages/providers/alphasms/src/Handlers/SendSMS.php:77) - calls `delivered()` after sending
2. `alphasms-v2` - [AlphaSMSSendSMS.php](../packages/providers/alphasms-v2/src/Handlers/AlphaSMSSendSMS.php:57) - calls `processed()` after sending
3. `telegram` - [TelegramSendMessage.php](../packages/providers/telegram/src/Handlers/TelegramSendMessage.php:100) - calls `delivered()` after sending
4. `sendgrid` - SendgridSendEmail.php
5. `twilio` - TwilioSendSms.php, TwilioSendWhatsapp.php
6. `vonage` - VonageSendSMS.php
7. `smsc` - SMSCSendSMS.php
8. `smsto` - SendSmsHandler.php
9. `firebase` - FirebaseSendPush.php
10. `mailgun` - MailgunSendEmail.php
11. `mailhog` - MailhogSend.php
12. `smtp` - SmtpSendEmail.php

**Pattern (all handlers):**
```php
try {
    // 1. Send to external API
    $response = $provider->send($message); ✅

    // 2. Mark as delivered
    $notificatorLog->delivered(); ✅

    // 3. ⚠️ ERROR HAPPENS HERE ⚠️
    //    (RabbitMQ publish fails, network issue, etc.)

} catch (\Throwable $e) {
    $notificatorLog->failed(...)->publishFallback();
    throw $e; // Message NACK'd → Retry → Duplicate send!
}
```

---

## Solution Approaches

### Approach 1: Database-Backed Idempotency (RECOMMENDED)

**Overview:** Track processed messages in PostgreSQL before sending to external APIs.

**Architecture:**
```
Message Arrives
     ↓
Check if message_id already processed (SELECT)
     ↓
  Already processed?
  ├─ YES → Skip (log duplicate, ACK message)
  └─ NO  → Continue
           ↓
       Insert processing record (with status='processing')
           ↓
       Send to external API
           ↓
       Update status='sent'
           ↓
       Publish events
           ↓
       ACK message
```

**Database Schema:**
```sql
CREATE TABLE IF NOT EXISTS public.processed_messages (
    id SERIAL PRIMARY KEY,
    message_id VARCHAR(255) NOT NULL,
    provider VARCHAR(50) NOT NULL,
    status VARCHAR(50) NOT NULL, -- 'processing', 'sent', 'failed'
    attempts INT DEFAULT 1,
    first_processed_at TIMESTAMP NOT NULL DEFAULT NOW(),
    last_processed_at TIMESTAMP NOT NULL DEFAULT NOW(),
    metadata JSONB,

    -- Composite unique constraint: one message_id per provider
    CONSTRAINT uq_message_provider UNIQUE (message_id, provider)
);

CREATE INDEX idx_processed_messages_lookup ON processed_messages (message_id, provider);
CREATE INDEX idx_processed_messages_cleanup ON processed_messages (last_processed_at);
```

**Implementation Pattern:**

```php
final class SendSMS extends BaseHandler
{
    private ProcessedMessageRepository $processedRepo;

    public function __invoke(NanoServiceMessageInterface $message): void
    {
        $messageId = $message->getId();
        $notificatorLog = (new NanoLogger())->setLog($message);

        try {
            // 1. IDEMPOTENCY CHECK
            if ($this->processedRepo->isAlreadyProcessed($messageId, 'alphasms')) {
                $this->logger->info("Message already processed, skipping", [
                    'message_id' => $messageId,
                    'provider' => 'alphasms'
                ]);
                return; // Exit early - don't send duplicate
            }

            // 2. MARK AS PROCESSING (atomic insert)
            $inserted = $this->processedRepo->markProcessing($messageId, 'alphasms', [
                'to' => $smsMessage->to,
                'from' => $smsMessage->from,
            ]);

            if (!$inserted) {
                // Race condition: another process inserted it first
                $this->logger->info("Message being processed by concurrent handler");
                return;
            }

            // 3. SEND TO EXTERNAL API (side effect happens AFTER idempotency check)
            $response = $alpha->sendSMS($smsMessage->to, $smsMessage->message);

            // 4. UPDATE STATUS
            $this->processedRepo->markSent($messageId, 'alphasms');

            // 5. LOG SUCCESS
            $notificatorLog->delivered();

            $this->logger->info('SMS sent successfully');

        } catch (\Throwable $exception) {
            // 6. MARK AS FAILED (but keep record - prevents retry)
            $this->processedRepo->markFailed($messageId, 'alphasms', $exception->getMessage());

            $notificatorLog->failed(
                NanoNotificatorErrorCodes::GENERAL_ERROR(),
                $exception->getMessage()
            )->publishFallback();

            throw $exception;
        }
    }
}
```

**Shared Repository (Place in shared package):**

```php
<?php

namespace ReminderPlatform\SharedIdempotency\Database;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;

final class ProcessedMessageRepository
{
    private PDO $db;
    private LoggerInterface $logger;

    public function __construct(PDO $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Check if message has already been processed
     */
    public function isAlreadyProcessed(string $messageId, string $provider): bool
    {
        try {
            $stmt = $this->db->prepare('
                SELECT status
                FROM processed_messages
                WHERE message_id = :message_id AND provider = :provider
                LIMIT 1
            ');
            $stmt->execute([
                'message_id' => $messageId,
                'provider' => $provider
            ]);

            $result = $stmt->fetch();

            if ($result) {
                $this->logger->info("Found existing processing record", [
                    'message_id' => $messageId,
                    'provider' => $provider,
                    'status' => $result['status']
                ]);
                return true;
            }

            return false;
        } catch (PDOException $e) {
            $this->logger->error('Failed to check processed status', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);

            // On database error, assume NOT processed (fail open)
            // This prevents blocking all messages if database has issues
            return false;
        }
    }

    /**
     * Mark message as processing (idempotent insert)
     * Returns true if inserted, false if already exists
     */
    public function markProcessing(string $messageId, string $provider, array $metadata = []): bool
    {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO processed_messages (
                    message_id,
                    provider,
                    status,
                    attempts,
                    metadata,
                    first_processed_at,
                    last_processed_at
                ) VALUES (
                    :message_id,
                    :provider,
                    :status,
                    1,
                    :metadata::jsonb,
                    NOW(),
                    NOW()
                )
                ON CONFLICT (message_id, provider) DO UPDATE SET
                    attempts = processed_messages.attempts + 1,
                    last_processed_at = NOW()
                RETURNING id, attempts
            ');

            $result = $stmt->execute([
                'message_id' => $messageId,
                'provider' => $provider,
                'status' => 'processing',
                'metadata' => json_encode($metadata)
            ]);

            $row = $stmt->fetch();

            if ($row && $row['attempts'] > 1) {
                // Already existed - this is a retry
                $this->logger->warning("Message retry detected", [
                    'message_id' => $messageId,
                    'provider' => $provider,
                    'attempts' => $row['attempts']
                ]);
                return false;
            }

            return true;
        } catch (PDOException $e) {
            $this->logger->error('Failed to mark message as processing', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update message status to 'sent'
     */
    public function markSent(string $messageId, string $provider): void
    {
        try {
            $stmt = $this->db->prepare('
                UPDATE processed_messages
                SET status = :status, last_processed_at = NOW()
                WHERE message_id = :message_id AND provider = :provider
            ');
            $stmt->execute([
                'status' => 'sent',
                'message_id' => $messageId,
                'provider' => $provider
            ]);
        } catch (PDOException $e) {
            $this->logger->error('Failed to mark message as sent', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            // Don't throw - sending already succeeded
        }
    }

    /**
     * Update message status to 'failed'
     */
    public function markFailed(string $messageId, string $provider, string $errorMessage): void
    {
        try {
            $stmt = $this->db->prepare('
                UPDATE processed_messages
                SET
                    status = :status,
                    last_processed_at = NOW(),
                    metadata = jsonb_set(
                        COALESCE(metadata, \'{}\'::jsonb),
                        \'{error}\',
                        to_jsonb(:error::text)
                    )
                WHERE message_id = :message_id AND provider = :provider
            ');
            $stmt->execute([
                'status' => 'failed',
                'message_id' => $messageId,
                'provider' => $provider,
                'error' => $errorMessage
            ]);
        } catch (PDOException $e) {
            $this->logger->error('Failed to mark message as failed', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            // Don't throw - failure already logged
        }
    }

    /**
     * Cleanup old processed messages (run via cronjob)
     */
    public function cleanup(int $days = 30): int
    {
        try {
            $sql = sprintf(
                "DELETE FROM processed_messages WHERE last_processed_at < NOW() - INTERVAL '%d days'",
                $days
            );

            $stmt = $this->db->query($sql);
            $deletedCount = $stmt->rowCount();

            $this->logger->info('Cleaned up old processed messages', [
                'days' => $days,
                'deleted_count' => $deletedCount
            ]);

            return $deletedCount;
        } catch (PDOException $e) {
            $this->logger->error('Failed to cleanup old messages', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
```

**Pros:**
- ✅ Prevents duplicates at the database level (UNIQUE constraint)
- ✅ Race condition safe (ON CONFLICT)
- ✅ Centralized tracking (single source of truth)
- ✅ Debugging support (can query processing history)
- ✅ Audit trail (when message was processed, how many attempts)
- ✅ Works across all providers (standardized)
- ✅ Survives service restarts

**Cons:**
- ❌ Requires database migration for each provider
- ❌ Extra database queries (2-3 per message)
- ❌ Need cleanup cronjob to prevent table growth
- ❌ Database becomes dependency (if DB down, processing blocks)

**Performance:**
- Indexed lookup: < 1ms
- Insert: < 5ms
- Total overhead: ~5-10ms per message
- Negligible for notification workloads

---

### Approach 2: Redis-Backed Idempotency

**Overview:** Use Redis SET with TTL to track processed messages.

**Architecture:**
```
Message Arrives
     ↓
Check Redis: EXISTS "processed:{provider}:{message_id}"
     ↓
  Key exists?
  ├─ YES → Skip (duplicate)
  └─ NO  → SETNX "processed:{provider}:{message_id}" 1 EX 3600
           ↓
       Success?
       ├─ YES → Send to external API
       └─ NO  → Skip (race condition)
```

**Implementation:**

```php
final class SendSMS extends BaseHandler
{
    private \Predis\Client $redis;

    public function __invoke(NanoServiceMessageInterface $message): void
    {
        $messageId = $message->getId();
        $key = "processed:alphasms:{$messageId}";

        // 1. CHECK + SET atomically (SETNX)
        $inserted = $this->redis->set($key, '1', 'EX', 3600, 'NX');

        if (!$inserted) {
            $this->logger->info("Message already processed (Redis)");
            return;
        }

        try {
            // 2. SEND TO EXTERNAL API
            $response = $alpha->sendSMS($smsMessage->to, $smsMessage->message);

            // 3. LOG SUCCESS
            $notificatorLog->delivered();

        } catch (\Throwable $exception) {
            // 4. DELETE key on failure to allow retry
            $this->redis->del($key);

            $notificatorLog->failed(...)->publishFallback();
            throw $exception;
        }
    }
}
```

**Pros:**
- ✅ Fast (Redis is in-memory)
- ✅ Automatic expiration (TTL handles cleanup)
- ✅ Simple implementation
- ✅ No database migration needed
- ✅ Atomic SET NX operation (race-safe)

**Cons:**
- ❌ Data loss on Redis restart (unless persistence enabled)
- ❌ No audit trail
- ❌ TTL must be tuned correctly
- ❌ Memory usage grows with message volume
- ❌ Redis becomes critical dependency

**When to use:**
- High-throughput scenarios (1000+ msg/sec)
- Short retention needed (< 24 hours)
- Redis already deployed and monitored

---

### Approach 3: Nano-Service Package Middleware (IDEAL but requires package modification)

**Overview:** Add idempotency tracking directly in `yevhenlisovenko/nano-service` package as middleware.

**Architecture:**
```php
// In nano-service package
class NanoConsumer
{
    public function idempotent(ProcessedMessageRepository $repo, string $provider): self
    {
        $this->middleware[] = function ($message, $next) use ($repo, $provider) {
            $messageId = $message->getId();

            // Check if already processed
            if ($repo->isAlreadyProcessed($messageId, $provider)) {
                $this->logger->info("Skipping duplicate message");
                return; // Don't call $next()
            }

            // Mark as processing
            if (!$repo->markProcessing($messageId, $provider)) {
                return; // Race condition
            }

            try {
                // Call actual handler
                $next($message);

                // Mark as sent
                $repo->markSent($messageId, $provider);
            } catch (\Throwable $e) {
                // Mark as failed
                $repo->markFailed($messageId, $provider, $e->getMessage());
                throw $e;
            }
        };

        return $this;
    }
}
```

**Usage in ConsumerCommand:**

```php
$this->consumer
    ->events(...$events)
    ->idempotent($processedRepo, 'alphasms') // ✨ One line!
    ->backoff(5)
    ->catch(...)
    ->consume(function (NanoServiceMessage $message) {
        // Handler code runs ONLY if not duplicate
        $handler($message);
    });
```

**Pros:**
- ✅ Centralized logic (DRY)
- ✅ Automatic for all consumers
- ✅ Transparent to handlers
- ✅ Easy to enable/disable
- ✅ Consistent across all providers

**Cons:**
- ❌ Requires modifying external package
- ❌ Package maintenance burden
- ❌ May not be accepted upstream
- ❌ Fork divergence risk

**Recommendation:** Propose this upstream to `yevhenlisovenko/nano-service` as a feature. If accepted, migrate all providers. If rejected, use Approach 1.

---

### Approach 4: Manual ACK Before Side Effects (NOT RECOMMENDED)

**Overview:** Acknowledge message BEFORE sending to external API.

```php
try {
    // 1. ACK message FIRST
    $this->consumer->ack($message);

    // 2. THEN send (no retry if this fails)
    $response = $alpha->sendSMS(...);

    $notificatorLog->delivered();
} catch (\Throwable $e) {
    // Message already ACK'd - lost!
    $notificatorLog->failed(...);
}
```

**Pros:**
- ✅ No duplicates (message never retried)
- ✅ Simple implementation

**Cons:**
- ❌ **CRITICAL:** Message loss on failure (violates zero data loss principle)
- ❌ Network errors = lost notifications
- ❌ No retry on transient failures
- ❌ Violates at-least-once delivery guarantee

**Verdict:** ❌ **DO NOT USE** - Unacceptable data loss risk

---

## Recommended Solution

### Primary: **Approach 1 (Database-Backed Idempotency)**

**Why:**
1. ✅ Production-proven (AlphaSMS-v2 webhook pattern)
2. ✅ Reliable (survives restarts, race-safe)
3. ✅ Debuggable (audit trail)
4. ✅ Standard PostgreSQL (no new dependencies)
5. ✅ Performance acceptable for notification workload

### Long-term: **Approach 3 (Nano-Service Middleware)**

**Path Forward:**
1. Implement Approach 1 immediately (solve duplicate problem)
2. Propose middleware to `yevhenlisovenko/nano-service` package
3. If accepted upstream, migrate to middleware pattern
4. If rejected, keep Approach 1 (works perfectly)

---

## Implementation Plan

### Phase 1: Shared Package (Week 1)

1. **Create shared idempotency package:**
   ```
   shared/idempotency/
   ├── src/
   │   ├── Database/
   │   │   ├── ProcessedMessageRepository.php
   │   │   └── DatabaseManager.php
   │   └── Console/
   │       └── CleanupCommand.php
   ├── db/
   │   └── migrations/
   │       └── 20260130000001_create_processed_messages_table.php
   ├── composer.json
   └── README.md
   ```

2. **Migration SQL:**
   ```sql
   CREATE TABLE IF NOT EXISTS public.processed_messages (
       id SERIAL PRIMARY KEY,
       message_id VARCHAR(255) NOT NULL,
       provider VARCHAR(50) NOT NULL,
       status VARCHAR(50) NOT NULL DEFAULT 'processing',
       attempts INT DEFAULT 1,
       first_processed_at TIMESTAMP NOT NULL DEFAULT NOW(),
       last_processed_at TIMESTAMP NOT NULL DEFAULT NOW(),
       metadata JSONB,
       CONSTRAINT uq_message_provider UNIQUE (message_id, provider)
   );

   CREATE INDEX idx_processed_messages_lookup ON processed_messages (message_id, provider);
   CREATE INDEX idx_processed_messages_cleanup ON processed_messages (last_processed_at);

   COMMENT ON TABLE processed_messages IS 'Idempotency tracking for outgoing provider messages';
   COMMENT ON COLUMN processed_messages.message_id IS 'UUID from RabbitMQ message';
   COMMENT ON COLUMN processed_messages.provider IS 'Provider name (alphasms, telegram, etc)';
   COMMENT ON COLUMN processed_messages.status IS 'processing, sent, or failed';
   ```

3. **Cleanup CronJob:**
   ```bash
   # Run daily at 2:00 AM
   php bin/console idempotency:cleanup --days=30
   ```

### Phase 2: Pilot Provider (Week 1-2)

**Choose AlphaSMS (old v1) as pilot:**

**Why AlphaSMS:**
- Problem originated here (line 778)
- Synchronous API (easier to test)
- High volume provider
- Success metrics clear

**Changes:**
1. Add `shared/idempotency` to composer.json
2. Update `SendSMS.php` handler
3. Add database migration
4. Deploy to E2E environment
5. Monitor for 1 week

**Validation:**
- Simulate errors after SMS send
- Verify no duplicates sent
- Check audit trail in database
- Confirm RabbitMQ retries don't cause duplicates

### Phase 3: Rollout to All Providers (Week 3-4)

**Priority 1 (High Volume):**
- telegram
- sendgrid
- twilio (SMS + WhatsApp)

**Priority 2 (Medium Volume):**
- alphasms-v2
- vonage
- smsc

**Priority 3 (Low Volume):**
- firebase
- mailgun
- mailhog
- smtp
- smsto

**Rollout Strategy:**
- One provider per day
- Monitor metrics after each deployment
- Rollback plan ready

### Phase 4: Monitoring & Alerting (Week 4)

**Metrics to add:**
```
reminder_platform_{provider}_duplicate_messages_blocked_total
reminder_platform_{provider}_idempotency_check_duration_seconds
reminder_platform_{provider}_idempotency_check_errors_total
```

**Grafana Dashboard:**
- Duplicate detection rate by provider
- Processing attempts histogram
- Failed idempotency checks

**Alerts:**
- High duplicate rate (> 5% of messages)
- Idempotency check failures (> 1% of messages)
- Database connection errors

---

## Database Considerations

### Schema Location

**Option A: Shared `public` schema (RECOMMENDED)**
```sql
-- All providers use public.processed_messages
CREATE TABLE public.processed_messages (...);
```

**Pros:**
- ✅ Single migration
- ✅ Centralized data
- ✅ Easy cross-provider queries
- ✅ One cleanup cronjob

**Cons:**
- ❌ Requires public schema access for all providers

**Option B: Per-provider schema**
```sql
-- Each provider has own table
CREATE TABLE alphasms.processed_messages (...);
CREATE TABLE telegram.processed_messages (...);
```

**Pros:**
- ✅ Schema isolation
- ✅ Independent migrations

**Cons:**
- ❌ 13 separate migrations
- ❌ 13 cleanup cronjobs
- ❌ Harder to get platform-wide stats

**Recommendation:** Use **Option A (public schema)** - simpler and sufficient.

### Migration Strategy

**Core service migration:**
```bash
# Run once in core service (has public schema access)
kubectl exec -it core-pod -- php vendor/bin/phinx migrate
```

**Provider services:**
- No schema creation needed (use existing table)
- Just add dependency to shared/idempotency package

### Connection Reuse

**All providers already connect to PostgreSQL:**
- AlphaSMS-v2: Has DatabaseManager
- Core: Has database connection
- Router: Has database access

**Reuse existing connections:**
```php
// In handler __construct
$pdo = DatabaseManager::getPdo();
$this->processedRepo = new ProcessedMessageRepository($pdo, $logger);
```

---

## Testing Strategy

### Unit Tests

```php
class ProcessedMessageRepositoryTest extends TestCase
{
    public function test_marks_message_as_processing(): void
    {
        $repo = new ProcessedMessageRepository($this->pdo, $this->logger);

        $inserted = $repo->markProcessing('msg-123', 'alphasms');

        $this->assertTrue($inserted);
        $this->assertTrue($repo->isAlreadyProcessed('msg-123', 'alphasms'));
    }

    public function test_prevents_duplicate_processing(): void
    {
        $repo = new ProcessedMessageRepository($this->pdo, $this->logger);

        $repo->markProcessing('msg-123', 'alphasms');
        $inserted = $repo->markProcessing('msg-123', 'alphasms'); // Retry

        $this->assertFalse($inserted); // Should not insert duplicate
    }

    public function test_allows_same_message_different_provider(): void
    {
        $repo = new ProcessedMessageRepository($this->pdo, $this->logger);

        $repo->markProcessing('msg-123', 'alphasms');
        $inserted = $repo->markProcessing('msg-123', 'telegram');

        $this->assertTrue($inserted); // Different provider = allowed
    }
}
```

### Integration Tests

**Test Scenario 1: Normal Flow**
```bash
# 1. Send message to queue
# 2. Handler processes successfully
# 3. Verify DB record created
# 4. Verify SMS sent
# 5. Verify status = 'sent'
```

**Test Scenario 2: Retry After Error**
```bash
# 1. Send message to queue
# 2. Handler sends SMS successfully
# 3. Simulate error (kill process)
# 4. Message requeued by RabbitMQ
# 5. Verify second attempt blocked
# 6. Verify SMS NOT sent twice
```

**Test Scenario 3: Race Condition**
```bash
# 1. Send same message to queue twice (rapidly)
# 2. Both handlers start simultaneously
# 3. Verify only ONE processes message
# 4. Verify only ONE SMS sent
```

### E2E Test

```php
// Test double-delivery prevention
public function test_prevents_duplicate_sms_on_retry()
{
    $messageId = Uuid::uuid4()->toString();

    // Mock SMS provider to count API calls
    $apiCallCount = 0;
    $mockProvider = $this->createMock(AlphaSMSAPI::class);
    $mockProvider->method('sendSMS')->willReturnCallback(function() use (&$apiCallCount) {
        $apiCallCount++;
        return $this->createSuccessResponse();
    });

    // Process message
    $handler = new SendSMS($mockProvider, $processedRepo, $logger, $jsonMapper);
    $message = $this->createMessage($messageId);

    $handler($message); // First processing

    // Simulate retry (same message ID)
    $handler($message); // Should skip

    $this->assertEquals(1, $apiCallCount, 'API should be called only once');
}
```

---

## Monitoring & Observability

### Metrics

**Add to shared/metrics package:**

```php
class IdempotencyMetrics
{
    private MetricsCollector $metrics;

    public function recordDuplicateBlocked(string $provider): void
    {
        $this->metrics->increment('duplicate_messages_blocked_total', [
            'provider' => $provider
        ]);
    }

    public function recordIdempotencyCheckDuration(float $duration, string $provider): void
    {
        $this->metrics->histogram('idempotency_check_duration_seconds', $duration, [
            'provider' => $provider
        ]);
    }

    public function recordIdempotencyCheckError(string $provider): void
    {
        $this->metrics->increment('idempotency_check_errors_total', [
            'provider' => $provider
        ]);
    }
}
```

**Usage in handler:**

```php
$startTime = microtime(true);
try {
    $isDuplicate = $this->processedRepo->isAlreadyProcessed($messageId, 'alphasms');

    $this->idempotencyMetrics->recordIdempotencyCheckDuration(
        microtime(true) - $startTime,
        'alphasms'
    );

    if ($isDuplicate) {
        $this->idempotencyMetrics->recordDuplicateBlocked('alphasms');
        return;
    }
} catch (\Throwable $e) {
    $this->idempotencyMetrics->recordIdempotencyCheckError('alphasms');
    throw $e;
}
```

### Grafana Dashboard

**Panel 1: Duplicate Detection Rate**
```promql
rate(reminder_platform_duplicate_messages_blocked_total[5m])
```

**Panel 2: Idempotency Check Latency (p95)**
```promql
histogram_quantile(0.95,
  rate(reminder_platform_idempotency_check_duration_seconds_bucket[5m])
)
```

**Panel 3: Failed Idempotency Checks**
```promql
rate(reminder_platform_idempotency_check_errors_total[5m])
```

### Logging

**Log duplicate detection:**
```php
$this->logger->info('Duplicate message blocked by idempotency check', [
    'message_id' => $messageId,
    'provider' => 'alphasms',
    'first_processed_at' => $record['first_processed_at'],
    'attempts' => $record['attempts']
]);
```

**Log successful processing:**
```php
$this->logger->info('Message processed successfully', [
    'message_id' => $messageId,
    'provider' => 'alphasms',
    'status' => 'sent',
    'duration_ms' => $duration * 1000
]);
```

---

## Trade-offs & Considerations

### Performance Impact

**Overhead per message:**
- Idempotency check (SELECT): ~1ms
- Insert processing record: ~5ms
- Update status: ~2ms
- **Total: ~8ms per message**

**For notification workload:** Negligible (notifications are not latency-sensitive)

**Comparison:**
- External API call: 100-500ms
- RabbitMQ publish: 5-10ms
- Idempotency check: 1-8ms (< 10% overhead)

### Database Growth

**Estimates:**
- 1 million messages/day
- 30 days retention
- **Total: 30 million rows**

**Storage:**
- ~500 bytes per row
- 30M rows × 500 bytes = 15GB

**Mitigation:**
- Daily cleanup cronjob (DELETE old records)
- Partitioning by `last_processed_at` (if volume increases)
- Index-only scans (covering indexes)

### Failure Modes

**1. Database unavailable:**
```php
try {
    $isDuplicate = $repo->isAlreadyProcessed(...);
} catch (PDOException $e) {
    // FAIL OPEN: Assume not processed
    // Allow message through (risk: duplicate)
    // Log error for investigation
    return false;
}
```

**Trade-off:** Availability over perfect deduplication

**2. Redis unavailable (if using Approach 2):**
- Similar fail-open strategy
- Monitor Redis health proactively

**3. Race condition between check and insert:**
- Handled by UNIQUE constraint + ON CONFLICT
- PostgreSQL guarantees atomicity
- Second insert returns 0 rows affected

---

## Migration Checklist

### Per-Provider Migration

- [ ] Add `shared/idempotency` to composer.json
- [ ] Update handler to use `ProcessedMessageRepository`
- [ ] Add database migration (if schema doesn't exist)
- [ ] Deploy to E2E environment
- [ ] Test duplicate detection (simulate retries)
- [ ] Monitor metrics for 48 hours
- [ ] Deploy to production
- [ ] Verify no duplicates in production logs
- [ ] Update provider documentation

### Platform-Wide Migration

- [ ] Create shared/idempotency package
- [ ] Add database migration to core service
- [ ] Deploy core service with migration
- [ ] Migrate pilot provider (AlphaSMS)
- [ ] Validate pilot (1 week)
- [ ] Migrate remaining 12 providers (1/day)
- [ ] Add Grafana dashboard
- [ ] Set up alerts
- [ ] Document lessons learned
- [ ] Update CLAUDE.md with new pattern

---

## Success Criteria

### Immediate (Post-Migration)

✅ Zero duplicate messages sent (verified in provider API dashboards)
✅ All 13 providers using idempotency tracking
✅ Database table created and populated
✅ Metrics showing duplicate detection rate
✅ No increase in message processing latency (< 10ms overhead)

### Long-term (3 months)

✅ 99.99% duplicate prevention rate
✅ < 0.1% idempotency check errors
✅ Database growth within expected bounds (< 20GB)
✅ No customer complaints about duplicates
✅ Grafana dashboard showing idempotency health

---

## Documentation Updates

After implementation, update:

1. **docs/PATTERNS_COMPLIANCE.md** - Add idempotency pattern
2. **CLAUDE.md** - Add "Always use idempotency for handlers" rule
3. **packages/{provider}/README.md** - Document idempotency setup
4. **docs/ARCHITECTURE.md** - Add idempotency layer to diagram
5. **docs/DEVELOPMENT.md** - Add testing duplicate scenarios

---

## References

- [AlphaSMS-v2 Idempotency Implementation](../packages/providers/alphasms-v2/docs/implementation/2026-01-02-idempotency.md)
- [PostgreSQL ON CONFLICT Documentation](https://www.postgresql.org/docs/current/sql-insert.html#SQL-ON-CONFLICT)
- [At-Least-Once Delivery Pattern](https://www.enterpriseintegrationpatterns.com/patterns/messaging/GuaranteedMessaging.html)
- [Idempotency Best Practices](https://stripe.com/docs/api/idempotent_requests)

---

## Questions & Answers

### Q: Why not ACK before sending?
**A:** Violates zero data loss principle. Network errors would cause message loss without retry opportunity.

### Q: Why database instead of Redis?
**A:** Reliability (survives restarts), audit trail, debugging capability. Redis is good for high-throughput scenarios but adds operational complexity.

### Q: What if database is slow?
**A:** Index-only scans are < 1ms. If slowness occurs, investigate with EXPLAIN ANALYZE. Consider read replicas if needed.

### Q: Can we use message body hash instead of message ID?
**A:** No. Message ID uniquely identifies the delivery attempt. Same reminder can be sent multiple times legitimately (scheduled, manual retry).

### Q: What about webhook idempotency?
**A:** Already implemented in AlphaSMS-v2 using webhook payload hash. This document covers **outgoing** message idempotency.

### Q: Should we deduplicate in router instead?
**A:** No. Router doesn't know if provider successfully processed message. Idempotency must be at provider level (after external API call).

---

## Next Steps

1. **Review this document** with team
2. **Choose approach** (Recommended: Approach 1)
3. **Create shared/idempotency package**
4. **Migrate pilot provider** (AlphaSMS)
5. **Validate and iterate**
6. **Roll out platform-wide**

---

**Status:** Awaiting team review and approval
**Owner:** Platform Team
**Target Completion:** 4 weeks from approval
