# Channel Leak Fix - CHANGELOG

**Date:** 2026-01-16
**Version:** 1.x.x → 1.x.x+1 (patch version bump recommended)
**Incident:** [2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2](../../../incidents/live/2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2/INCIDENT.md)

---

## Critical Bug Fix: Channel Leak Prevention

### Problem

**Symptom:** RabbitMQ connections accumulating thousands of channels, eventually hitting the 2,047 channel limit per connection and causing "No free channel ids" errors.

**Root Cause:** The `getChannel()` method was creating new channels for each instance but never storing them in the shared channel pool (`self::$sharedChannel`), defeating the intended connection pooling mechanism.

**Impact in Production:**
- provider-easyweek-live: 2,047 channels per connection (maxed out)
- provider-sendgrid-live: 2,047 channels per connection (maxed out)
- supervisor-rabbitmq: 300-400 channels per connection
- Total cluster: 17,840 channels across 135 connections
- Result: Message publishing failures, service degradation

### Changes Made

#### 1. Fixed `NanoServiceClass::getChannel()` (lines 114-131)

**Before (BUGGY):**
```php
public function getChannel()
{
    if (self::$sharedChannel && self::$sharedChannel->is_open()) {
        return self::$sharedChannel;
    }

    if (! $this->channel) {
        $this->channel = $this->getConnection()->channel();
        // ❌ BUG: Never stored in self::$sharedChannel
    }

    return $this->channel;
}
```

**After (FIXED):**
```php
public function getChannel()
{
    if (self::$sharedChannel && self::$sharedChannel->is_open()) {
        return self::$sharedChannel;
    }

    if (! $this->channel || !$this->channel->is_open()) {
        $this->channel = $this->getConnection()->channel();

        // ✅ FIX: Store in shared pool for reuse
        self::$sharedChannel = $this->channel;
    }

    return $this->channel;
}
```

**What changed:**
- Added `!$this->channel->is_open()` check for robustness (line 122)
- **CRITICAL:** Added `self::$sharedChannel = $this->channel;` (line 127)
- Added explanatory comment (lines 125-126)

#### 2. Added Safety Net Destructor (lines 170-185)

**New code:**
```php
public function __destruct()
{
    // Only close instance channel if it's different from the shared one
    if ($this->channel
        && $this->channel !== self::$sharedChannel
        && method_exists($this->channel, 'is_open')
        && $this->channel->is_open()
    ) {
        try {
            $this->channel->close();
        } catch (\Throwable $e) {
            // Suppress errors during shutdown
        }
    }
}
```

**Why this helps:**
- Cleans up any orphaned instance channels during object destruction
- Safety net for edge cases
- No impact on normal operation (shared channel is preserved)

### Expected Impact

**Before Fix:**
- Every `new NanoPublisher()` created a new leaked channel
- 1,000 messages = 1,000 leaked channels
- Channels never closed until pod restart
- Hit 2,047 limit in 12-37 hours

**After Fix:**
- All instances share ONE channel per worker process
- 1,000 messages = 1 channel reused 1,000 times
- Channel closed only on shutdown
- **97% reduction in channel count** (17,840 → ~500 expected)

### Backward Compatibility

✅ **100% backward compatible:**
- No API changes
- No configuration changes
- No breaking changes
- Same public interface
- Only difference: channels are reused as originally intended

### Testing

**Unit Test Example:**
```php
public function test_channel_reuse_across_instances()
{
    $pub1 = new NanoPublisher();
    $channel1 = $pub1->getChannel();
    $channelId1 = $channel1->getChannelId();

    $pub2 = new NanoPublisher();
    $channel2 = $pub2->getChannel();
    $channelId2 = $channel2->getChannelId();

    // Should be SAME channel
    $this->assertEquals($channelId1, $channelId2);
    $this->assertSame($channel1, $channel2);
}
```

**Integration Test:**
```bash
# Before fix: 1000 messages = ~1000 new channels
# After fix:  1000 messages = ~1 channel reused

# Monitor channel count
watch -n 1 'kubectl exec -n rabbitmq rabbitmq-0 -- rabbitmqctl list_channels | wc -l'

# Send 1000 messages
for i in {1..1000}; do
  php artisan publish:test
done

# Expected: Channel count increases by 1-3 (not 1000!)
```

### Deployment Recommendations

**Priority: HIGH (Critical production issue)**

**Rollout Strategy:**
1. Deploy to staging/E2E first
2. Monitor for 1 hour
3. Canary deploy to production (10% traffic)
4. Monitor for 1 hour
5. Full production rollout

**Monitoring During Rollout:**
```bash
# Check channel count trend
kubectl exec -n rabbitmq rabbitmq-0 -- rabbitmqctl list_channels | wc -l

# Check channels per connection
kubectl exec -n rabbitmq rabbitmq-0 -- rabbitmqctl list_connections name channels

# Check for "No free channel ids" errors
kubectl logs -n live --selector=app=provider-easyweek-live --since=10m | grep -i "no free channel"
```

**Success Criteria:**
- ✅ Channel count stable at < 1,000 (down from 17,840)
- ✅ Channels per connection < 10 (down from 56-2,047)
- ✅ No "No free channel ids" errors
- ✅ Normal message throughput
- ✅ No increase in error rates

### Files Modified

1. **src/NanoServiceClass.php**
   - Fixed `getChannel()` method (lines 114-131)
   - Added `__destruct()` method (lines 170-185)

### Migration Guide

**For library consumers (applications using nano-service):**

1. Update composer dependency:
   ```bash
   composer update yevhenlisovenko/nano-service
   ```

2. No code changes required in your application

3. Deploy updated application

4. Monitor RabbitMQ channel count (should decrease significantly)

**For library maintainers:**

1. Review and merge this fix
2. Bump patch version (e.g., 1.2.3 → 1.2.4)
3. Tag release: `git tag v1.2.4`
4. Update CHANGELOG.md
5. Publish to Packagist

### Additional Recommendations

**Add monitoring alert:**
```yaml
# Prometheus alert
- alert: RabbitMQHighChannelsPerConnection
  expr: sum(rabbitmq_channels) / sum(rabbitmq_connections) > 20
  for: 10m
  annotations:
    summary: "High channel/connection ratio detected"
```

**Add health check:**
```php
// In your application
Route::get('/health', function() {
    return [
        'rabbitmq' => [
            'connection_alive' => NanoServiceClass::$sharedConnection?->isConnected() ?? false,
            'channel_open' => NanoServiceClass::$sharedChannel?->is_open() ?? false,
        ]
    ];
});
```

### References

- **Incident Report:** `incidents/live/2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2/INCIDENT.md`
- **Code Analysis:** `incidents/live/2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2/CODE_ANALYSIS.md`
- **RabbitMQ Channel Docs:** https://www.rabbitmq.com/channels.html
- **AMQP 0-9-1 Spec:** https://www.rabbitmq.com/resources/specs/amqp0-9-1.pdf

### Credits

**Reported by:** DevOps team via production monitoring
**Analyzed by:** Claude Code (AI Assistant)
**Fixed by:** [Your name here]
**Reviewed by:** [Reviewers]

### License

Same license as the parent project.
