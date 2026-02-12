# Migration Guide: Upgrading to v7.2.0

> **Critical Security Fix**: This version fixes a race condition that could cause duplicate message processing (Issue 1 - Concurrent Processing).

---

## What Changed?

**Issue**: When RabbitMQ redelivers a message (due to connection drops, pod restarts, heartbeat timeouts), multiple workers could process the same message simultaneously, causing duplicate business logic execution (duplicate invoices, emails, payments, etc.).

**Fix**: Atomic row-level locking using `locked_at` and `locked_by` columns in the inbox table.

---

## Migration Steps

### 1. Update Database Schema

The inbox table requires two new columns for atomic locking:

```sql
-- Add locking columns (nullable for backwards compatibility)
ALTER TABLE pg2event.inbox
ADD COLUMN IF NOT EXISTS locked_at TIMESTAMP DEFAULT NULL;

ALTER TABLE pg2event.inbox
ADD COLUMN IF NOT EXISTS locked_by VARCHAR(255) DEFAULT NULL;

-- Create index for stale lock detection
CREATE INDEX IF NOT EXISTS idx_inbox_locked_at ON pg2event.inbox(locked_at)
WHERE locked_at IS NOT NULL;

-- Create composite index for claim queries
CREATE INDEX IF NOT EXISTS idx_inbox_claim_lookup ON pg2event.inbox(message_id, consumer_service, status, locked_at);

-- Add comments
COMMENT ON COLUMN pg2event.inbox.locked_at IS 'Timestamp when message was claimed for processing. NULL = not locked. Used to detect stale/abandoned processing.';
COMMENT ON COLUMN pg2event.inbox.locked_by IS 'Worker identifier (hostname/pod name) that claimed this message. Used for debugging and monitoring.';
```

**Note**: If your service uses `public.inbox` schema instead of `pg2event.inbox`, replace the schema name accordingly.

### 2. Update Composer Package

```bash
composer require yevhenlisovenko/nano-service:^7.2
```

### 3. (Optional) Configure Stale Lock Threshold

Add to your `.env` or Kubernetes ConfigMap:

```bash
# Default is 300 seconds (5 minutes)
# Set this HIGHER than your longest message processing time
INBOX_LOCK_STALE_THRESHOLD=300
```

**Recommended values:**

| Message processing time | `INBOX_LOCK_STALE_THRESHOLD` |
|------------------------|------------------------------|
| < 1 minute | `300` (5 min) - default |
| 1-5 minutes | `600` (10 min) |
| 5-10 minutes | `900` (15 min) |
| > 10 minutes | Consider async workers |

### 4. (Optional) Set Worker Identifier

If running in Kubernetes, add `POD_NAME` to your deployment:

```yaml
# deployment.yaml
env:
  - name: POD_NAME
    valueFrom:
      fieldRef:
        fieldPath: metadata.name
```

If `POD_NAME` is not set, the library will fallback to `hostname:pid`.

---

## Rollout Strategy

### Option A: Zero-Downtime Rolling Update (Recommended)

Since the new columns are **nullable**, you can do a rolling update:

1. **Run migration** (adds columns, backwards compatible)
2. **Deploy v7.2.0** to a canary pod (10% of pods)
3. **Monitor** for errors, check metrics for duplicate processing reduction
4. **Roll out** to remaining pods

**Safety**: Old pods (v7.1.0) ignore new columns, new pods (v7.2.0) use them. No breaking change.

### Option B: Blue-Green Deployment

1. **Run migration** on database
2. **Deploy v7.2.0** to green environment
3. **Smoke test** green environment
4. **Switch traffic** from blue to green
5. **Decommission** blue environment

---

## What to Monitor

### Before Migration
```bash
# Check for concurrent processing (same message_id processed multiple times)
SELECT message_id, consumer_service, COUNT(*)
FROM pg2event.inbox
WHERE status = 'processed'
GROUP BY message_id, consumer_service
HAVING COUNT(*) > 1;
```

### After Migration
```bash
# Monitor active locks
SELECT locked_by, COUNT(*) as active_locks
FROM pg2event.inbox
WHERE status = 'processing' AND locked_at IS NOT NULL
GROUP BY locked_by;

# Monitor stale locks (potential crashed workers)
SELECT message_id, consumer_service, locked_by, locked_at,
       EXTRACT(EPOCH FROM (NOW() - locked_at)) as lock_age_seconds
FROM pg2event.inbox
WHERE status = 'processing'
  AND locked_at < NOW() - INTERVAL '300 seconds'
ORDER BY locked_at ASC;
```

### Metrics to Watch
- **Duplicate processing rate**: Should drop to near-zero after migration
- **Stale locks**: Should be rare (only after worker crashes)
- **Claim failures**: Workers skipping messages because another worker owns them

---

## Troubleshooting

### Issue: Stale locks accumulating

**Symptom**: Many rows with old `locked_at` timestamps

**Cause**: `INBOX_LOCK_STALE_THRESHOLD` is too high, workers crashing frequently

**Solution**:
1. Lower `INBOX_LOCK_STALE_THRESHOLD` to match your actual processing time
2. Investigate why workers are crashing (OOM, segfaults, etc.)
3. Manually unlock stale rows:
```sql
UPDATE pg2event.inbox
SET locked_at = NULL, locked_by = NULL
WHERE status = 'processing'
  AND locked_at < NOW() - INTERVAL '600 seconds';
```

### Issue: Messages not being processed

**Symptom**: Messages stuck in `status='processing'`

**Cause**: Lock threshold too low, messages legitimately taking longer to process

**Solution**:
1. Increase `INBOX_LOCK_STALE_THRESHOLD`
2. Check `locked_by` to see which worker owns the message
3. Check worker logs for that `locked_by` identifier

### Issue: Migration fails with "column already exists"

**Symptom**: `ERROR: column "locked_at" of relation "inbox" already exists`

**Cause**: Migration already ran (pg2event.inbox was created with these columns from v7.2.0 schema)

**Solution**: This is safe to ignore. The columns already exist, so the fix is already active.

---

## Backwards Compatibility

✅ **Safe to deploy incrementally** - new columns are nullable
✅ **Old consumers (v7.1.0) work** - they ignore new columns
✅ **No API changes** - all changes are internal

⚠️ **Important**: Until ALL consumers are upgraded to v7.2.0, concurrency protection is NOT fully active. Mixed deployments (v7.1.0 + v7.2.0) have partial protection.

---

## Testing the Fix

### Before Migration
```bash
# Simulate concurrent delivery (two workers, same message)
# Expected: Both workers process the message (DUPLICATE)
```

### After Migration
```bash
# Simulate concurrent delivery (two workers, same message)
# Expected: First worker claims and processes, second worker skips (ACK but no processing)
```

### Verify in Logs
```bash
# Worker 1
[NanoConsumer] Inserted new message for processing
[NanoConsumer] Processing message...

# Worker 2 (receives redelivery)
[NanoConsumer] Message is locked by another worker, skipping: {"message_id":"...","consumer_service":"..."}
```

---

## Rollback Plan

If you need to rollback to v7.1.0:

1. **Downgrade package**: `composer require yevhenlisovenko/nano-service:^7.1`
2. **Keep database columns** - they're nullable and safe to leave
3. **Remove indexes** (optional, for cleanup):
```sql
DROP INDEX IF EXISTS pg2event.idx_inbox_locked_at;
DROP INDEX IF EXISTS pg2event.idx_inbox_claim_lookup;
```

**Note**: Downgrading means you lose concurrency protection. Only do this if v7.2.0 causes issues.

---

## Questions?

- **Issue analysis**: See `/Users/begimov/Downloads/CONCURRENCY_ISSUES.md` for detailed explanation of Issue 1
- **Configuration**: See [CONFIGURATION.md](CONFIGURATION.md) for all environment variables
- **Troubleshooting**: See [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for common issues
