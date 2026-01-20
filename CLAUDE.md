# Claude Code Guidelines for nano-service

> **Audience**: Claude Code (LLM) working on nano-service repository
> **Purpose**: Ensure safe, consistent development of this critical library

---

## 🚨 ABSOLUTE RULE: NEVER COMMIT OR PUSH

**Claude NEVER runs git commands that modify the repository.**

**Forbidden commands:**
- ❌ `git add`
- ❌ `git commit`
- ❌ `git push`
- ❌ `git tag`
- ❌ Any git command that modifies history

**Claude's role:**
- ✅ Make code changes
- ✅ Run tests
- ✅ Suggest commit messages in text
- ✅ Review diffs

**User's role:**
- ✅ Review changes
- ✅ Commit manually
- ✅ Push manually
- ✅ Tag releases

---

## Critical Rules

### 1. NEVER Break Backwards Compatibility

**This is a library used by production services. Breaking changes cause outages.**

**Rules:**
- ❌ NEVER change method signatures of public methods
- ❌ NEVER remove public methods or properties
- ❌ NEVER change default behavior of existing functionality
- ✅ ALWAYS add new features as opt-in (disabled by default)
- ✅ ALWAYS maintain existing interfaces and contracts
- ✅ ALWAYS test with existing consumer code before deploying

**Examples:**

❌ **Breaking change:**
```php
// OLD
public function publish(string $event): void

// NEW (BREAKS EXISTING CODE!)
public function publish(string $event, array $options): void
```

✅ **Non-breaking change:**
```php
// OLD
public function publish(string $event): void

// NEW (BACKWARDS COMPATIBLE)
public function publish(string $event, array $options = []): void
```

---

### 2. NEVER Commit Code - User Commits Only

**🚨 ABSOLUTE RULE: Claude NEVER runs git add/commit/push. User ALWAYS commits. 🚨**

**What Claude DOES:**
- ✅ Create files
- ✅ Edit files
- ✅ Write code
- ✅ Run `git diff` to show changes

**What Claude NEVER DOES:**
- ❌ `git add` - NEVER
- ❌ `git commit` - NEVER
- ❌ `git push` - NEVER
- ❌ Any git operation that modifies state

**Workflow:**
1. Claude creates/edits code
2. Claude shows `git diff`
3. Claude provides commit commands for user
4. **USER reviews and commits**
5. USER pushes to remote

---

### 3. New Features Must Be Opt-In

**Default behavior MUST NOT change for existing users.**

**Pattern: Feature flags with safe defaults:**

```php
// Example: Metrics (v6.0)
$this->enabled = $this->envBool('STATSD_ENABLED', false);  // ✅ OFF by default
```

**Why:**
- Services update library via `composer update`
- Auto-updates should never break production
- Users must consciously enable new features
- Allows gradual rollout and validation

---

### 4. Error Handling Must Be Safe

**Never throw exceptions that existing code doesn't expect.**

**Rules:**
- ✅ Catch and handle new exception types internally
- ✅ Log errors instead of throwing (where appropriate)
- ✅ Use fire-and-forget for non-critical operations (e.g., metrics)
- ❌ Don't add new required dependencies without major version bump

**Example:**
```php
// Metrics collection (safe)
try {
    $this->statsD->increment('metric');
} catch (\Exception $e) {
    // Silently fail - metrics are non-critical
    // Don't break the application for metrics issues
}
```

---

### 5. Connection Pooling Is Critical

**Background:** Jan 16, 2026 incident - channel leak caused 17,840 channels, 97% reduction after fix.

**Rules:**
- ❌ NEVER create new connections/channels unnecessarily
- ✅ ALWAYS use static `$sharedConnection` and `$sharedChannel`
- ✅ ALWAYS reuse channels across instances in same worker
- ❌ NEVER close shared connections/channels (except shutdown)

**Code pattern:**
```php
// ✅ Correct: Use shared connection
protected static ?AMQPStreamConnection $sharedConnection = null;

if (self::$sharedConnection && self::$sharedConnection->isConnected()) {
    return self::$sharedConnection;
}

// ❌ Wrong: Create new connection every time
$this->connection = new AMQPStreamConnection(...);  // LEAK!
```

**See:** `incidents/2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2` for context

---

### 6. Metrics Must Not Impact Core Functionality

**Metrics are for observability - they must never break the application.**

**Rules:**
- ✅ All metric calls wrapped in `if ($this->statsD && $this->statsD->isEnabled())`
- ✅ Use UDP (fire-and-forget, non-blocking)
- ✅ No exceptions thrown if metrics fail
- ✅ Disabled by default (`STATSD_ENABLED=false`)
- ✅ Configurable sampling to control overhead

**Example:**
```php
// Safe metrics pattern
if ($this->statsD && $this->statsD->isEnabled()) {
    $this->statsD->increment('metric', $tags, $sampleRate);
}
// Application continues regardless of metrics success/failure
```

---

### 7. Tag Cardinality Must Be Bounded

**High-cardinality tags cause Prometheus cardinality explosion and performance issues.**

**Rules:**
- ✅ Use bounded sets: event names, error types, retry status
- ❌ NEVER use: user_id, invoice_id, request_id, UUID, timestamp
- ✅ Document allowed values for tags
- ✅ Use enums for tag values (PublishErrorType, EventRetryStatusTag, etc.)

**Example:**

✅ **Low cardinality (safe):**
```php
$tags = [
    'service' => 'myservice',        // Bounded (~10-50 services)
    'event' => 'user.created',       // Bounded (~100-500 event types)
    'error_type' => 'channel_error', // Bounded (6 types via enum)
    'retry' => 'first',              // Bounded (3 values via enum)
];
```

❌ **High cardinality (dangerous):**
```php
$tags = [
    'user_id' => '12345',           // Unbounded (millions)
    'invoice_id' => 'INV-98765',    // Unbounded (millions)
    'request_id' => 'uuid...',      // Unbounded (infinite)
];
```

---

### 8. Documentation Must Be Updated

**When adding features:**
1. ✅ Update [README.md](README.md) with feature summary
2. ✅ Update [METRICS.md](METRICS.md) if adding metrics
3. ✅ Update [CONFIGURATION.md](CONFIGURATION.md) if adding config options
4. ✅ Update [CHANGELOG.md](CHANGELOG.md) with version and changes
5. ✅ Add examples showing usage
6. ✅ Document migration path from previous version

**Never leave undocumented features.**

---

### 9. Testing Before Release

**Before marking code as ready:**
1. ✅ Test with existing v5.x consumer code (backwards compatibility)
2. ✅ Test with metrics disabled (STATSD_ENABLED=false)
3. ✅ Test with metrics enabled (STATSD_ENABLED=true)
4. ✅ Test with sampling (STATSD_SAMPLE_OK=0.1)
5. ✅ Test error scenarios (connection failure, channel error, etc.)
6. ✅ Check for memory leaks in long-running processes
7. ✅ Verify no new exceptions thrown

---

### 10. Version Management

**Semantic versioning:**
- **Major version (7.0)**: Breaking changes (avoid if possible)
- **Minor version (6.1)**: New features, backwards compatible
- **Patch version (6.0.1)**: Bug fixes only

**Current version:** 6.0 (metrics added, backwards compatible)

**Next expected:**
- 6.0.1 - Bug fixes
- 6.1.0 - New features (backwards compatible)
- 7.0.0 - Breaking changes (only if absolutely necessary)

---

## Common Pitfalls

1. **Don't change existing metric names** - breaks dashboards and alerts
2. **Don't add required ENV variables** - breaks existing deployments
3. **Don't assume statsd-exporter is always available** - graceful degradation
4. **Don't create channels in loops** - causes channel exhaustion
5. **Don't use high-cardinality tags** - explodes Prometheus
6. **Don't enable features by default** - opt-in for safety
7. **Don't throw new exception types** - breaks error handling

---

## Questions Before Making Changes

1. Is this change backwards compatible?
2. Will existing v5.x code still work?
3. Are new features opt-in (disabled by default)?
4. Have I updated documentation?
5. Have I tested with real consumer code?
6. Am I about to commit? (STOP - user commits!)
7. Does this create channels/connections unnecessarily?
8. Are metric tags bounded (low cardinality)?

---

## Emergency Contacts

**If introducing breaking changes:**
- Coordinate with all teams using nano-service
- Create migration guide
- Provide deprecation timeline
- Update all consuming services before removing old code

**Services using nano-service:**
- easyweek-service-backend
- nanoservice-elasticsearch
- nanoservice-event2clickhouse
- (check devops/catalog/ownership.yaml for complete list)

---

## Reference

- **DevOps Hub:** `/Users/yevhenlisovenko/www/devops`
- **Task:** `tasks/2026-01-19_RABBIMQ_EVENT_METRICS`
- **Incident:** `incidents/2026-01-16_RABBITMQ_CHANNEL_EXHAUSTION_SEV2`
- **GitOps:** `repos/gitops-apps` (for service deployments)

---

**Remember:** This library is used in production by critical services. Every change must be safe, backwards compatible, and well-tested.
