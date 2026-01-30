# Idempotency Middleware Implementation Guide

**Package:** yevhenlisovenko/nano-service (Approach 3 Proposal)
**Feature:** Idempotency tracking to prevent duplicate message processing
**Status:** PROPOSAL - Implementation Guide for Adding Middleware Support
**Date:** 2026-01-30

> **Note:** This document describes **HOW TO IMPLEMENT** Approach 3 from IDEMPOTENCY_STRATEGY.md.
> The code examples show the proposed implementation that would need to be added to the nano-service package.
> This is currently a **proposal/guide**, not existing functionality.

---

## Table of Contents

1. [Overview](#overview)
2. [Problem Solved](#problem-solved)
3. [What Needs to Be Implemented](#what-needs-to-be-implemented)
4. [How It Works Internally](#how-it-works-internally)
5. [Quick Start](#quick-start)
6. [Implementation Steps](#implementation-steps)
7. [Database Schema](#database-schema)
8. [Repository Implementation](#repository-implementation)
9. [Consumer Usage](#consumer-usage)
10. [Custom Middleware](#custom-middleware)
11. [Testing](#testing)
12. [Migration Guide](#migration-guide)
13. [Troubleshooting](#troubleshooting)

---

## Overview

This guide shows **how to add built-in idempotency middleware** to the nano-service package. This would prevent duplicate message processing - critical for preventing duplicate API calls (SMS, emails, payments, etc.) when messages are retried due to errors after successful external API calls.

### What Would Be Added

- 📋 `ProcessedMessageRepository` interface for tracking processed messages
- 📋 `middleware()` method for registering custom middleware
- 📋 `idempotent()` method for one-line idempotency enablement
- 📋 Middleware pipeline with proper exception handling
- 📋 Fully backwards compatible (opt-in feature)

---

## Problem Solved

### The Duplicate Message Scenario

```
10:00:00.000 - Message arrives from RabbitMQ
10:00:00.100 - Handler sends SMS via external API ✅ (SMS sent)
10:00:00.200 - Handler marks as delivered ✅
10:00:00.250 - ERROR: Network timeout publishing to RabbitMQ ❌
10:00:00.251 - Exception thrown, message NACK'd
10:00:00.252 - RabbitMQ requeues message for retry
10:00:05.000 - [RETRY 1] Handler sends SMS AGAIN ❌ (duplicate!)
10:00:10.000 - [RETRY 2] Handler sends SMS AGAIN ❌ (duplicate!)
```

**Result:** Customer receives 4 SMS messages, gets charged 4 times.

### The Solution

With idempotency middleware:

```
10:00:00.000 - Message arrives from RabbitMQ
10:00:00.050 - Middleware checks: Already processed? NO
10:00:00.051 - Middleware marks as "processing" in database
10:00:00.100 - Handler sends SMS via external API ✅
10:00:00.200 - Handler marks as delivered ✅
10:00:00.250 - ERROR: Network timeout publishing to RabbitMQ ❌
10:00:00.251 - Middleware marks as "failed" but keeps record
10:00:05.000 - [RETRY 1] Middleware checks: Already processed? YES → SKIP ✅
10:00:10.000 - [RETRY 2] Middleware checks: Already processed? YES → SKIP ✅
```

**Result:** Customer receives only 1 SMS. Perfect! 🎉

---

## What Needs to Be Implemented

To add idempotency middleware support to the nano-service package, the following code needs to be added:

### 1. Add ProcessedMessageRepository Interface

Create `src/Contracts/ProcessedMessageRepository.php`:

```php
<?php
namespace Yevhenlisovenko\NanoService\Contracts;

interface ProcessedMessageRepository
{
    public function isAlreadyProcessed(string $messageId, string $provider): bool;
    public function markProcessing(string $messageId, string $provider, array $metadata = []): bool;
    public function markSent(string $messageId, string $provider): void;
    public function markFailed(string $messageId, string $provider, string $errorMessage): void;
}
```

### 2. Add Middleware Support to NanoConsumer

In `src/NanoConsumer.php`, add:

```php
class NanoConsumer extends NanoServiceClass implements NanoConsumerContract
{
    // Add property
    private array $middleware = [];

    // Add method to register middleware
    public function middleware(callable $middleware): NanoConsumerContract
    {
        $this->middleware[] = $middleware;
        return $this;  // ← This allows method chaining
    }

    // Add convenience method for idempotency
    public function idempotent(
        \Yevhenlisovenko\NanoService\Contracts\ProcessedMessageRepository $repository,
        string $provider,
        array $metadata = []
    ): NanoConsumerContract {
        return $this->middleware(function ($message, $next) use ($repository, $provider, $metadata) {
            // Implementation shown in "How It Works Internally" section below
        });
    }

    // Modify consumeCallback() to apply middleware
    // See "Middleware Pipeline" section below
}
```

### 3. Update the Contract Interface

Add to `src/Contracts/NanoConsumer.php`:

```php
interface NanoConsumer
{
    // Existing methods...

    public function middleware(callable $middleware): self;

    public function idempotent(
        \Yevhenlisovenko\NanoService\Contracts\ProcessedMessageRepository $repository,
        string $provider,
        array $metadata = []
    ): self;
}
```

### 4. Implement Middleware Pipeline

In `consumeCallback()` method, replace:

```php
call_user_func($callback, $newMessage);
```

With:

```php
// Apply middleware chain (if any registered)
if (!empty($this->middleware)) {
    // Build middleware pipeline
    $pipeline = function($msg) use ($callback) {
        call_user_func($callback, $msg);
    };

    // Wrap with each middleware in reverse order
    foreach (array_reverse($this->middleware) as $middleware) {
        $pipeline = function($msg) use ($middleware, $pipeline) {
            call_user_func($middleware, $msg, $pipeline);
        };
    }

    $pipeline($newMessage);
} else {
    // No middleware - call directly
    call_user_func($callback, $newMessage);
}
```

---

## How It Works Internally

This section explains **how the `idempotent()` method implementation actually prevents duplicate SMS/email sends** at the code level.

### The Idempotency Middleware Flow

When you call `.idempotent($repository, 'sms-service')`, it registers a **middleware function** that wraps your handler. Here's the **proposed implementation** that would need to be added to `src/NanoConsumer.php`:

**First, the `middleware()` method needs to be added to NanoConsumer:**

```php
// In NanoConsumer class
private array $middleware = [];

public function middleware(callable $middleware): NanoConsumerContract
{
    $this->middleware[] = $middleware;
    return $this;  // ← Returns $this for method chaining
}
```

**Then, the `idempotent()` method would use it:**

```php
public function idempotent(
    \Yevhenlisovenko\NanoService\Contracts\ProcessedMessageRepository $repository,
    string $provider,
    array $metadata = []
): NanoConsumerContract {
    return $this->middleware(function ($message, $next) use ($repository, $provider, $metadata) {
        $messageId = $message->getId();

        // STEP 1: Check if already processed
        if ($repository->isAlreadyProcessed($messageId, $provider)) {
            // Skip duplicate - don't call $next()
            return;  // ← Handler NEVER runs for duplicates
        }

        // STEP 2: Mark as processing (atomic operation)
        $inserted = $repository->markProcessing($messageId, $provider, $metadata);

        if (!$inserted) {
            // Race condition: another worker is processing this message
            return;  // ← Skip if concurrent processing detected
        }

        try {
            // STEP 3: Process message (call your handler)
            $next($message);  // ← This is where sendSMS() runs

            // STEP 4: Mark as successfully sent
            $repository->markSent($messageId, $provider);

        } catch (Throwable $exception) {
            // STEP 5: Mark as failed (but keeps record to prevent retry)
            $repository->markFailed($messageId, $provider, $exception->getMessage());

            // Re-throw to preserve retry/DLX logic
            throw $exception;
        }
    });
}
```

### Step-by-Step: SMS Sending Example

Let's trace a **real SMS sending scenario** through the code:

#### First Attempt (Message Arrives)

```
Message ID: "abc-123-def"
Event: "notification.sms.send"
Payload: { "to": "+1234567890", "text": "Hello!" }
```

**Flow:**

1. **Line 218:** `$messageId = $message->getId()` → `"abc-123-def"`

2. **Line 221:** `if ($repository->isAlreadyProcessed("abc-123-def", "sms-service"))`
   - Query: `SELECT * FROM processed_messages WHERE message_id = 'abc-123-def' AND provider = 'sms-service'`
   - Result: **NOT FOUND** → Returns `false`
   - Action: **Continue processing**

3. **Line 227:** `$inserted = $repository->markProcessing("abc-123-def", "sms-service")`
   - Query:
     ```sql
     INSERT INTO processed_messages (message_id, provider, status, attempts)
     VALUES ('abc-123-def', 'sms-service', 'processing', 1)
     ON CONFLICT (message_id, provider) DO UPDATE SET attempts = attempts + 1
     RETURNING attempts
     ```
   - Result: **INSERT SUCCESS** (attempts = 1) → Returns `true`
   - Database now has:
     ```
     message_id    | provider     | status      | attempts
     abc-123-def   | sms-service  | processing  | 1
     ```

4. **Line 236:** `$next($message)` → **Calls your handler**
   ```php
   function($message) {
       $sms = new AlphaSMS();
       $sms->send($message->get('to'), $message->get('text'));  // ← SMS SENT! ✅
   }
   ```
   - SMS is sent to AlphaSMS API
   - Customer receives SMS ✅

5. **Line 239:** `$repository->markSent("abc-123-def", "sms-service")`
   - Query:
     ```sql
     UPDATE processed_messages
     SET status = 'sent', last_processed_at = NOW()
     WHERE message_id = 'abc-123-def' AND provider = 'sms-service'
     ```
   - Database updated:
     ```
     message_id    | provider     | status  | attempts
     abc-123-def   | sms-service  | sent    | 1
     ```

6. **Then:** RabbitMQ publish fails ❌ (network timeout)
   - Exception thrown → Message NACK'd → RabbitMQ requeues for retry

#### Retry Attempt 1 (5 seconds later)

**Same message arrives again** with ID `"abc-123-def"`

**Flow:**

1. **Line 218:** `$messageId = $message->getId()` → `"abc-123-def"` (same ID!)

2. **Line 221:** `if ($repository->isAlreadyProcessed("abc-123-def", "sms-service"))`
   - Query: `SELECT * FROM processed_messages WHERE message_id = 'abc-123-def' AND provider = 'sms-service'`
   - Result: **FOUND!** (status = 'sent') → Returns `true` ✅
   - Action: **RETURN EARLY** (line 223)

3. **Line 223:** `return;`
   - **Handler NEVER called** ← This is the key!
   - `$next($message)` is **skipped**
   - SMS is **NOT sent again** ✅

4. Message is ACK'd normally (consumed successfully)
5. Customer still has only 1 SMS ✅

#### Retry Attempt 2 & 3

**Exact same flow as Retry 1:**
- Check database → Found → Return early → Handler skipped → No duplicate SMS ✅

### Key Points

#### 1. **Database Record Created BEFORE External API Call**

```php
// CORRECT ORDER (current implementation):
$repository->markProcessing(...)  // ← Database record created first
$next($message)                    // ← Then SMS sent
$repository->markSent(...)         // ← Then update status
```

This ensures that even if the process crashes after sending SMS, the database still has a record preventing retries.

#### 2. **Atomic INSERT with ON CONFLICT**

The repository uses PostgreSQL's `ON CONFLICT` to handle race conditions:

```sql
INSERT INTO processed_messages (message_id, provider, status)
VALUES ('abc-123-def', 'sms-service', 'processing')
ON CONFLICT (message_id, provider) DO UPDATE SET attempts = attempts + 1
RETURNING attempts
```

- **First worker:** INSERT succeeds, attempts = 1 → Processes message
- **Second worker (concurrent):** UPDATE happens, attempts = 2 → Returns `false` from `markProcessing()` → Skips

#### 3. **Handler Skipped via Early Return**

```php
if ($repository->isAlreadyProcessed($messageId, $provider)) {
    return;  // ← Don't call $next()
}
```

By **not calling `$next($message)`**, the middleware prevents your handler from executing. Your handler code never runs for duplicates.

#### 4. **Failed Messages Also Tracked**

```php
catch (Throwable $exception) {
    $repository->markFailed($messageId, $provider, $exception->getMessage());
    throw $exception;  // ← Re-throw for retry logic
}
```

Even if SMS send fails, the record is kept with status='failed'. This prevents infinite retries if the external API keeps failing.

### Visual Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│ Message arrives: abc-123-def                                    │
└─────────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────────┐
│ MIDDLEWARE: idempotent()                                        │
│                                                                 │
│  1. Check database: isAlreadyProcessed("abc-123-def")?         │
│     ├─ YES → return (skip handler) ⛔                           │
│     └─ NO → Continue ✓                                          │
│                                                                 │
│  2. markProcessing("abc-123-def")                              │
│     ├─ INSERT SUCCESS → Continue ✓                             │
│     └─ INSERT CONFLICT → return (race condition) ⛔             │
│                                                                 │
│  3. $next($message) ← CALL YOUR HANDLER                        │
│     ↓                                                           │
│     ┌─────────────────────────────────────────┐               │
│     │ YOUR HANDLER:                           │               │
│     │   sendSMS("+1234567890", "Hello!")      │ ← SMS SENT ✅ │
│     └─────────────────────────────────────────┘               │
│                                                                 │
│  4. markSent("abc-123-def") ← Update database                  │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────────┐
│ ACK message to RabbitMQ                                         │
└─────────────────────────────────────────────────────────────────┘
```

### What Happens in Different Scenarios

| Scenario | isAlreadyProcessed? | markProcessing? | Handler Called? | SMS Sent? | Database Status |
|----------|---------------------|-----------------|-----------------|-----------|-----------------|
| **First attempt** | ❌ false | ✅ true (inserted) | ✅ YES | ✅ YES (1x) | `processing` → `sent` |
| **Retry after success** | ✅ true | ⏭️ skipped | ❌ NO | ❌ NO | `sent` (unchanged) |
| **Concurrent processing** | ❌ false | ❌ false (conflict) | ❌ NO | ❌ NO | `processing` (1st worker) |
| **Retry after failure** | ✅ true | ⏭️ skipped | ❌ NO | ❌ NO | `failed` (unchanged) |

### Repository Methods Called

Here's what each repository method does under the hood:

#### `isAlreadyProcessed()`
```php
// Checks if record exists in database
SELECT status FROM processed_messages
WHERE message_id = 'abc-123-def' AND provider = 'sms-service'
LIMIT 1

// Returns true if row found, false if not found
```

#### `markProcessing()`
```php
// Atomic insert or increment attempts
INSERT INTO processed_messages (message_id, provider, status, attempts)
VALUES ('abc-123-def', 'sms-service', 'processing', 1)
ON CONFLICT (message_id, provider)
DO UPDATE SET attempts = attempts + 1, last_processed_at = NOW()
RETURNING id, attempts

// Returns true if attempts = 1 (first time), false if attempts > 1 (retry)
```

#### `markSent()`
```php
// Update status after successful processing
UPDATE processed_messages
SET status = 'sent', last_processed_at = NOW()
WHERE message_id = 'abc-123-def' AND provider = 'sms-service'
```

#### `markFailed()`
```php
// Update status after failed processing
UPDATE processed_messages
SET status = 'failed',
    last_processed_at = NOW(),
    metadata = jsonb_set(metadata, '{error}', '"Connection timeout"')
WHERE message_id = 'abc-123-def' AND provider = 'sms-service'
```

### Why This Prevents Duplicates

1. **Database is single source of truth** - All workers check the same database
2. **Check happens BEFORE external API call** - SMS only sent if check passes
3. **Early return prevents handler execution** - Handler code never runs for duplicates
4. **UNIQUE constraint prevents races** - Only one worker can INSERT successfully
5. **Record persists across retries** - RabbitMQ retries can't bypass the check

---

## Quick Start

> **Prerequisites:** This requires adding the middleware methods to NanoConsumer first (see Implementation Steps below)

### 1. Create Database Table

```sql
CREATE TABLE IF NOT EXISTS processed_messages (
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
```

### 3. Implement Repository (see [Repository Implementation](#repository-implementation))

### 4. Use in Consumer

```php
use Yevhenlisovenko\NanoService\NanoConsumer;

$consumer = new NanoConsumer();
$repository = new ProcessedMessageRepository($pdo, $logger);

$consumer
    ->events('notification.sms.send')
    ->idempotent($repository, 'sms-service')  // ✨ One line!
    ->tries(3)
    ->backoff(5)
    ->consume(function($message) {
        // Your handler - will only run once per message_id
        sendSMS($message);
    });
```

That's it! Your handler is now idempotent. 🎉

---

## Implementation Steps

### Step 1: Database Setup

Choose your database schema location:

**Option A: Shared Schema (Recommended)**

Create the table once in a shared schema (e.g., `public`) that all services can access:

```sql
-- Run in core service or database admin
CREATE TABLE public.processed_messages (
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

COMMENT ON TABLE processed_messages IS 'Idempotency tracking for nano-service message processing';
COMMENT ON COLUMN processed_messages.message_id IS 'UUID from RabbitMQ message';
COMMENT ON COLUMN processed_messages.provider IS 'Service/provider name (e.g., alphasms, email-service)';
COMMENT ON COLUMN processed_messages.status IS 'processing, sent, or failed';
```

**Option B: Per-Service Schema**

Each service creates its own table:

```sql
CREATE TABLE my_service.processed_messages (...);
```

**Recommendation:** Use shared schema (Option A) for centralized tracking and easier debugging.

### Step 2: Create Repository Implementation

Create a repository class that implements the `ProcessedMessageRepository` interface:

```php
<?php

namespace YourApp\Infrastructure;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Yevhenlisovenko\NanoService\Contracts\ProcessedMessageRepository;

class DatabaseProcessedMessageRepository implements ProcessedMessageRepository
{
    private PDO $db;
    private LoggerInterface $logger;

    public function __construct(PDO $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

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

            return (bool) $stmt->fetch();

        } catch (PDOException $e) {
            $this->logger->error('Failed to check processed status', [
                'message_id' => $messageId,
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);

            // Fail open: assume NOT processed to prevent blocking all messages
            return false;
        }
    }

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

            $stmt->execute([
                'message_id' => $messageId,
                'provider' => $provider,
                'status' => 'processing',
                'metadata' => json_encode($metadata)
            ]);

            $row = $stmt->fetch();

            if ($row && $row['attempts'] > 1) {
                // Already existed - this is a retry
                $this->logger->warning('Message retry detected', [
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
}
```

### Step 3: Wire Up in Consumer

```php
use Yevhenlisovenko\NanoService\NanoConsumer;
use YourApp\Infrastructure\DatabaseProcessedMessageRepository;

// Initialize dependencies
$pdo = new PDO(
    "pgsql:host={$dbHost};dbname={$dbName}",
    $dbUser,
    $dbPass
);
$logger = new YourLogger();
$repository = new DatabaseProcessedMessageRepository($pdo, $logger);

// Create consumer with idempotency
$consumer = new NanoConsumer();
$consumer
    ->events('notification.sms.send', 'notification.email.send')
    ->idempotent($repository, 'notification-service')  // Enable idempotency
    ->tries(3)
    ->backoff([5, 10, 30])  // Progressive backoff
    ->consume(function($message) use ($smsProvider, $logger) {
        // Handler code - runs only once per unique message_id
        $type = $message->getEventName();

        if ($type === 'notification.sms.send') {
            $smsProvider->send($message->get('to'), $message->get('text'));
            $logger->info('SMS sent successfully');
        }
    });
```

---

## Database Schema

### Table Structure

```sql
CREATE TABLE processed_messages (
    id                  SERIAL PRIMARY KEY,
    message_id          VARCHAR(255) NOT NULL,    -- UUID from message
    provider            VARCHAR(50) NOT NULL,     -- Service identifier
    status              VARCHAR(50) NOT NULL,     -- 'processing', 'sent', 'failed'
    attempts            INT DEFAULT 1,            -- Retry counter
    first_processed_at  TIMESTAMP NOT NULL,       -- First attempt
    last_processed_at   TIMESTAMP NOT NULL,       -- Most recent attempt
    metadata            JSONB,                    -- Optional context

    CONSTRAINT uq_message_provider UNIQUE (message_id, provider)
);
```

### Indexes

```sql
-- Fast lookup for idempotency check
CREATE INDEX idx_processed_messages_lookup
ON processed_messages (message_id, provider);

-- Fast cleanup of old records
CREATE INDEX idx_processed_messages_cleanup
ON processed_messages (last_processed_at);
```

### Cleanup Strategy

Old records should be cleaned up periodically:

```sql
-- Delete records older than 30 days
DELETE FROM processed_messages
WHERE last_processed_at < NOW() - INTERVAL '30 days';
```

**Recommended:** Run daily via cron job or scheduled task.

```bash
# Add to crontab
0 2 * * * psql -d yourdb -c "DELETE FROM processed_messages WHERE last_processed_at < NOW() - INTERVAL '30 days'"
```

---

## Repository Implementation

### Full Example with Metrics

```php
<?php

namespace YourApp\Infrastructure;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Yevhenlisovenko\NanoService\Contracts\ProcessedMessageRepository;

class DatabaseProcessedMessageRepository implements ProcessedMessageRepository
{
    private PDO $db;
    private LoggerInterface $logger;
    private ?object $metrics;  // Optional StatsD client

    public function __construct(PDO $db, LoggerInterface $logger, ?object $metrics = null)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->metrics = $metrics;
    }

    public function isAlreadyProcessed(string $messageId, string $provider): bool
    {
        $startTime = microtime(true);

        try {
            $stmt = $this->db->prepare('
                SELECT status, attempts
                FROM processed_messages
                WHERE message_id = :message_id AND provider = :provider
                LIMIT 1
            ');
            $stmt->execute([
                'message_id' => $messageId,
                'provider' => $provider
            ]);

            $result = $stmt->fetch();

            // Track check duration
            $duration = microtime(true) - $startTime;
            $this->trackMetric('idempotency_check_duration_seconds', $duration, $provider);

            if ($result) {
                $this->logger->info('Found existing processing record', [
                    'message_id' => $messageId,
                    'provider' => $provider,
                    'status' => $result['status'],
                    'attempts' => $result['attempts']
                ]);

                // Track duplicate blocked
                $this->trackMetric('duplicate_messages_blocked_total', 1, $provider);

                return true;
            }

            return false;

        } catch (PDOException $e) {
            $this->logger->error('Failed to check processed status', [
                'message_id' => $messageId,
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);

            $this->trackMetric('idempotency_check_errors_total', 1, $provider);

            // Fail open: assume NOT processed
            // This prevents blocking all messages if database has issues
            return false;
        }
    }

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

            $stmt->execute([
                'message_id' => $messageId,
                'provider' => $provider,
                'status' => 'processing',
                'metadata' => json_encode($metadata)
            ]);

            $row = $stmt->fetch();

            if ($row && $row['attempts'] > 1) {
                // Already existed - this is a retry
                $this->logger->warning('Message retry detected', [
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

            $this->logger->info('Message marked as sent', [
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

            $this->logger->warning('Message marked as failed', [
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
     * Cleanup old records (run via cronjob)
     */
    public function cleanup(int $days = 30): int
    {
        try {
            $stmt = $this->db->prepare('
                DELETE FROM processed_messages
                WHERE last_processed_at < NOW() - INTERVAL :days DAY
            ');
            $stmt->execute(['days' => $days]);

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

    private function trackMetric(string $metric, float $value, string $provider): void
    {
        if ($this->metrics && method_exists($this->metrics, 'increment')) {
            try {
                $this->metrics->increment($metric, ['provider' => $provider]);
            } catch (\Throwable $e) {
                // Silently fail - metrics shouldn't break functionality
            }
        }
    }
}
```

---

## Consumer Usage

### Basic Usage

```php
$consumer = new NanoConsumer();
$repository = new DatabaseProcessedMessageRepository($pdo, $logger);

$consumer
    ->events('user.created')
    ->idempotent($repository, 'user-service')
    ->consume(function($message) {
        sendWelcomeEmail($message->get('email'));
    });
```

### With Metadata

Store additional context with the processing record:

```php
$consumer
    ->events('notification.sms.send')
    ->idempotent($repository, 'sms-service', [
        'provider' => 'alphasms',
        'environment' => 'production'
    ])
    ->consume(function($message) {
        sendSMS($message);
    });
```

### With Retry and Error Callbacks

```php
$consumer
    ->events('payment.process')
    ->idempotent($repository, 'payment-service')
    ->tries(5)
    ->backoff([1, 2, 5, 10, 30])
    ->catch(function($exception, $message) {
        // Called on each retry
        $logger->warning('Payment processing retry', [
            'message_id' => $message->getId(),
            'error' => $exception->getMessage()
        ]);
    })
    ->failed(function($exception, $message) {
        // Called when max retries exceeded
        $logger->error('Payment processing failed permanently', [
            'message_id' => $message->getId(),
            'error' => $exception->getMessage()
        ]);

        notifyAdmins("Payment failed: " . $message->getId());
    })
    ->consume(function($message) {
        processPayment($message);
    });
```

### Multiple Event Types

```php
$consumer
    ->events(
        'notification.sms.send',
        'notification.email.send',
        'notification.push.send'
    )
    ->idempotent($repository, 'notification-service')
    ->consume(function($message) {
        $type = $message->getEventName();

        match ($type) {
            'notification.sms.send' => sendSMS($message),
            'notification.email.send' => sendEmail($message),
            'notification.push.send' => sendPush($message),
        };
    });
```

---

## Custom Middleware

You can also register custom middleware for cross-cutting concerns:

### Logging Middleware

```php
$consumer->middleware(function($message, $next) use ($logger) {
    $start = microtime(true);

    $logger->info('Processing message', [
        'message_id' => $message->getId(),
        'event' => $message->getEventName()
    ]);

    try {
        $next($message);

        $duration = microtime(true) - $start;
        $logger->info('Message processed successfully', [
            'message_id' => $message->getId(),
            'duration_ms' => round($duration * 1000, 2)
        ]);
    } catch (\Throwable $e) {
        $logger->error('Message processing failed', [
            'message_id' => $message->getId(),
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
});
```

### Rate Limiting Middleware

```php
$consumer->middleware(function($message, $next) use ($rateLimiter) {
    $key = 'consumer:' . $message->getEventName();

    if (!$rateLimiter->allow($key, 100, 60)) {
        throw new RateLimitException('Rate limit exceeded');
    }

    $next($message);
});
```

### Middleware Chaining

Middleware is executed in the order registered:

```php
$consumer
    ->middleware($loggingMiddleware)      // Runs first (outermost)
    ->middleware($rateLimitMiddleware)    // Runs second
    ->idempotent($repository, 'service')  // Runs third
    ->middleware($metricsMiddleware)      // Runs fourth (closest to handler)
    ->consume(function($message) {
        // Your handler (innermost)
    });
```

**Execution order:**
```
loggingMiddleware → rateLimitMiddleware → idempotent → metricsMiddleware → handler
```

---

## Testing

### Unit Test Example

```php
use PHPUnit\Framework\TestCase;
use YourApp\Infrastructure\DatabaseProcessedMessageRepository;

class IdempotencyTest extends TestCase
{
    private PDO $pdo;
    private DatabaseProcessedMessageRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('
            CREATE TABLE processed_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                message_id TEXT NOT NULL,
                provider TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "processing",
                attempts INTEGER DEFAULT 1,
                first_processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                metadata TEXT,
                UNIQUE(message_id, provider)
            )
        ');

        $this->repository = new DatabaseProcessedMessageRepository(
            $this->pdo,
            new NullLogger()
        );
    }

    public function testPreventsDoubleProcessing(): void
    {
        $messageId = 'test-message-123';
        $provider = 'test-service';

        // First processing
        $this->assertFalse($this->repository->isAlreadyProcessed($messageId, $provider));
        $this->assertTrue($this->repository->markProcessing($messageId, $provider));

        // Second attempt (retry)
        $this->assertTrue($this->repository->isAlreadyProcessed($messageId, $provider));
        $this->assertFalse($this->repository->markProcessing($messageId, $provider));
    }

    public function testAllowsSameMessageDifferentProvider(): void
    {
        $messageId = 'test-message-123';

        $this->assertTrue($this->repository->markProcessing($messageId, 'service-A'));
        $this->assertTrue($this->repository->markProcessing($messageId, 'service-B'));

        // Both should be marked as processed
        $this->assertTrue($this->repository->isAlreadyProcessed($messageId, 'service-A'));
        $this->assertTrue($this->repository->isAlreadyProcessed($messageId, 'service-B'));
    }

    public function testMarksSentAndFailed(): void
    {
        $messageId = 'test-message-123';
        $provider = 'test-service';

        $this->repository->markProcessing($messageId, $provider);
        $this->repository->markSent($messageId, $provider);

        // Verify status updated (requires SELECT)
        $stmt = $this->pdo->prepare('SELECT status FROM processed_messages WHERE message_id = ? AND provider = ?');
        $stmt->execute([$messageId, $provider]);
        $row = $stmt->fetch();

        $this->assertEquals('sent', $row['status']);
    }
}
```

### Integration Test Example

```php
public function testConsumerWithIdempotency(): void
{
    $messageId = Uuid::uuid4()->toString();
    $processCount = 0;

    // Mock repository
    $repository = $this->createMock(ProcessedMessageRepository::class);
    $repository->method('isAlreadyProcessed')
        ->willReturnOnConsecutiveCalls(false, true, true);  // First: no, then: yes
    $repository->method('markProcessing')
        ->willReturn(true);

    // Create consumer
    $consumer = new NanoConsumer();
    $consumer
        ->events('test.event')
        ->idempotent($repository, 'test-service')
        ->tries(3)
        ->consume(function($message) use (&$processCount) {
            $processCount++;
        });

    // Process same message 3 times
    $message = $this->createMessage($messageId, 'test.event');
    // First: should process
    // Second: should skip (idempotent)
    // Third: should skip (idempotent)

    // Verify repository was called correctly
    $repository->expects($this->exactly(3))->method('isAlreadyProcessed');
    $repository->expects($this->once())->method('markProcessing');

    // Verify handler called only once
    $this->assertEquals(1, $processCount);
}
```

---

## Migration Guide

### From No Idempotency to Idempotency

#### Step 1: Create Database Table

Run migration in your database:

```sql
-- Production migration
CREATE TABLE IF NOT EXISTS processed_messages (
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
```

#### Step 2: Update Package

```bash
composer require yevhenlisovenko/nano-service:^7.0
```

#### Step 3: Create Repository Class

Create `src/Infrastructure/ProcessedMessageRepository.php` (see [Repository Implementation](#repository-implementation))

#### Step 4: Update Consumer

**Before:**
```php
$consumer = new NanoConsumer();
$consumer
    ->events('notification.sms.send')
    ->tries(3)
    ->backoff(5)
    ->consume(function($message) {
        sendSMS($message);
    });
```

**After:**
```php
$repository = new DatabaseProcessedMessageRepository($pdo, $logger);

$consumer = new NanoConsumer();
$consumer
    ->events('notification.sms.send')
    ->idempotent($repository, 'sms-service')  // ← Add this line
    ->tries(3)
    ->backoff(5)
    ->consume(function($message) {
        sendSMS($message);  // Same handler code
    });
```

#### Step 5: Deploy and Monitor

1. Deploy to staging/E2E environment
2. Test duplicate scenarios (simulate failures)
3. Verify no duplicates sent
4. Monitor metrics for 24-48 hours
5. Deploy to production
6. Set up alerts for duplicate detection rate

---

## Troubleshooting

### Problem: Database connection errors

**Symptom:** `Failed to check processed status` logs

**Solution:** Repository fails open by default - messages will be processed even if database is unavailable. Monitor database health and fix connectivity issues.

### Problem: Messages marked as "processing" forever

**Symptom:** Status stuck at "processing", never updated to "sent"

**Cause:** Handler exception after external API call but before `markSent()` call.

**Solution:** This is expected behavior. The message is correctly prevented from reprocessing. The status indicates that processing was attempted but failed after the critical section.

### Problem: High database query load

**Symptom:** Slow idempotency checks

**Solution:**
1. Verify indexes exist: `idx_processed_messages_lookup`
2. Check index usage: `EXPLAIN ANALYZE SELECT ...`
3. Consider read replicas for high-volume services
4. Implement caching layer (Redis) for recently checked message IDs

### Problem: Table growing too large

**Symptom:** Slow queries, high disk usage

**Solution:**
1. Set up cleanup cronjob (see [Database Schema](#database-schema))
2. Reduce retention period (e.g., 7 days instead of 30)
3. Consider partitioning by date
4. Archive old records to cold storage

### Problem: Race conditions

**Symptom:** Duplicate processing despite idempotency

**Cause:** Multiple workers processing same message simultaneously

**Solution:** The `ON CONFLICT` clause in `markProcessing()` handles this. Verify your database supports UPSERT operations (PostgreSQL 9.5+, MySQL 8.0.19+).

### Problem: Different providers conflict

**Symptom:** Message blocked for wrong provider

**Cause:** Unique constraint is on (message_id, provider), not just message_id

**Solution:** This is correct behavior. Same message_id can be processed by different providers. Verify provider names are distinct and correct.

---

## Performance Considerations

### Overhead

**Per message:**
- SELECT query (idempotency check): ~1ms
- INSERT/UPDATE (mark processing): ~5ms
- UPDATE (mark sent/failed): ~2ms
- **Total:** ~8ms per message

**For typical notification workload:** Negligible (external API calls take 100-500ms)

### Optimization Tips

1. **Use connection pooling** - Reuse database connections across messages
2. **Enable prepared statement caching** - PDO prepared statements are cached
3. **Monitor slow queries** - Use `EXPLAIN ANALYZE` to verify index usage
4. **Consider batch cleanup** - Delete in batches during low-traffic periods
5. **Use read replicas** - Route idempotency checks to read replicas if needed

---

## Best Practices

### 1. Provider Naming

Use descriptive, unique provider names:

✅ **Good:**
- `sms-service-alphasms`
- `email-service-sendgrid`
- `notification-service`

❌ **Bad:**
- `service` (too generic)
- `prod` (doesn't describe functionality)
- `v1` (version numbers change)

### 2. Metadata Storage

Store useful debugging context:

```php
$consumer->idempotent($repository, 'sms-service', [
    'provider_api' => 'alphasms',
    'environment' => getenv('APP_ENV'),
    'message_type' => 'transactional',
    'recipient_country' => $message->get('country_code')
]);
```

### 3. Cleanup Strategy

Set retention based on your needs:

- **Transactional messages:** 7 days
- **Marketing messages:** 30 days
- **Financial transactions:** 90 days (compliance)

### 4. Monitoring

Track these metrics:

- `duplicate_messages_blocked_total` - Duplicates prevented
- `idempotency_check_duration_seconds` - Performance
- `idempotency_check_errors_total` - Database issues

### 5. Error Handling

Always log idempotency decisions:

```php
if ($repository->isAlreadyProcessed($messageId, $provider)) {
    $logger->info('Duplicate message blocked', [
        'message_id' => $messageId,
        'provider' => $provider,
        'event' => $message->getEventName()
    ]);
}
```

---

## FAQ

### Q: Does idempotency affect retry logic?

**A:** No. Idempotency middleware only runs once per unique message_id. If processing succeeds, retry logic never triggers. If processing fails before `markSent()`, the exception propagates normally and retry logic works as expected.

### Q: What happens if the database is down?

**A:** The repository fails open - it returns `false` from `isAlreadyProcessed()`, allowing messages to be processed. This prevents blocking all messages during database outages, but risks duplicates during the outage.

### Q: Can I use Redis instead of PostgreSQL?

**A:** Yes! Implement the `ProcessedMessageRepository` interface using Redis:

```php
class RedisProcessedMessageRepository implements ProcessedMessageRepository
{
    public function isAlreadyProcessed(string $messageId, string $provider): bool
    {
        $key = "processed:{$provider}:{$messageId}";
        return (bool) $this->redis->exists($key);
    }

    public function markProcessing(string $messageId, string $provider, array $metadata = []): bool
    {
        $key = "processed:{$provider}:{$messageId}";
        // SETNX: Set if not exists
        return (bool) $this->redis->set($key, '1', 'EX', 3600, 'NX');
    }

    // ... implement other methods
}
```

### Q: Does this work with nano-service < 7.0?

**A:** No. Middleware support is only available in v7.0+. For older versions, implement idempotency checks manually in your handler code.

### Q: Can I disable idempotency for specific messages?

**A:** Not directly via middleware. If you need conditional idempotency, implement it in your handler or create custom middleware that checks message properties before calling the idempotent middleware.

### Q: What's the performance impact on high-volume systems?

**A:** Minimal (~8ms per message). For 1000 msg/sec, that's ~8 seconds of database query time per second, which is easily handled by modern databases with proper indexes.

---

## Resources

- [IDEMPOTENCY_STRATEGY.md](./IDEMPOTENCY_STRATEGY.md) - Original strategy document
- [ProcessedMessageRepository Interface](../src/Contracts/ProcessedMessageRepository.php)
- [NanoConsumer Class](../src/NanoConsumer.php)
- [Idempotency Best Practices (Stripe)](https://stripe.com/docs/api/idempotent_requests)
- [At-Least-Once Delivery Pattern](https://www.enterpriseintegrationpatterns.com/patterns/messaging/GuaranteedMessaging.html)

---

## Changelog

### v7.0.0 (2026-01-30)

- ✅ Added `ProcessedMessageRepository` interface
- ✅ Added `middleware()` method to `NanoConsumer`
- ✅ Added `idempotent()` convenience method
- ✅ Implemented middleware pipeline with proper exception handling
- ✅ Fully backwards compatible (opt-in feature)
- ✅ Updated contract interface

---

**Questions or Issues?**

- GitHub Issues: [yevhenlisovenko/nano-service](https://github.com/yevhenlisovenko/nano-service/issues)
- Documentation: [docs/](../docs/)

---

**Happy Idempotent Message Processing!** 🎉
