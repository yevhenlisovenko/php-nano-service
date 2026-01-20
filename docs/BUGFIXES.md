# Bug Fixes and Known Issues

This document tracks critical bug fixes and known issues in the nano-service package.

---

## Critical Bug: Duplicate Property Visibility in NanoConsumer

**Date**: 2026-01-20
**Severity**: CRITICAL
**Status**: FIXED

### Problem

The `NanoConsumer` class contained a duplicate property declaration that violated PHP inheritance rules:

```php
// In NanoServiceClass (parent)
protected ?StatsDClient $statsD = null;  // Line 54

// In NanoConsumer (child) - INCORRECT
private StatsDClient $statsD;  // Line 22 - REMOVED
```

### Error Message

```
Fatal error: Access level to AlexFN\NanoService\NanoConsumer::$statsD must be
protected (as in class AlexFN\NanoService\NanoServiceClass) or weaker in
/shared/nano-service-main/src/NanoConsumer.php on line 15
```

### Root Cause

PHP inheritance rules state that:
- **Child class properties CANNOT have stricter visibility than parent class properties**
- Allowed visibility changes: `protected` → `public` (weaker)
- Forbidden visibility changes: `protected` → `private` (stricter)

The `NanoConsumer` class attempted to redeclare the inherited `protected $statsD` property as `private`, which is forbidden.

### Solution

**Remove the duplicate property declaration from NanoConsumer:**

```php
// ❌ WRONG - Before fix
class NanoConsumer extends NanoServiceClass implements NanoConsumerContract
{
    protected array $handlers = [...];

    private StatsDClient $statsD;  // ← REMOVE THIS LINE

    private $callback;
    // ...
}

// ✅ CORRECT - After fix
class NanoConsumer extends NanoServiceClass implements NanoConsumerContract
{
    protected array $handlers = [...];

    // Removed: inherited from parent NanoServiceClass as protected
    // private StatsDClient $statsD;

    private $callback;
    // ...
}
```

### Impact

This bug prevented the entire application from starting when using Symfony Console 7 or newer versions that strictly enforce property visibility rules.

**Affected versions**: All versions prior to this fix

### Prevention

**Code Review Checklist:**
- ✅ Check for duplicate property declarations in child classes
- ✅ Verify property visibility matches or is weaker than parent class
- ✅ Use static analysis tools (PHPStan, Psalm) to detect inheritance violations
- ✅ Test with multiple PHP versions (8.1, 8.2, 8.3+)

**PHPStan Rule:**
```neon
# phpstan.neon
parameters:
    level: 8
    checkMissingOverrideMethodAttribute: true
```

### Related Files

- `src/NanoConsumer.php` (line 22-23 removed)
- `src/NanoServiceClass.php` (line 54 - parent property definition)

### Testing

To verify the fix:

```bash
# Test that NanoConsumer can be instantiated
php -r "
require 'vendor/autoload.php';
use AlexFN\NanoService\NanoConsumer;
\$consumer = new NanoConsumer();
echo 'NanoConsumer instantiated successfully';
"
```

Expected output: `NanoConsumer instantiated successfully`

### Lessons Learned

1. **Avoid redeclaring inherited properties** unless you need to change type or visibility
2. **Use inheritance properly** - trust parent class property definitions
3. **Run CI/CD tests with strict PHP settings** to catch visibility violations early
4. **Document inheritance relationships** in class docblocks

### References

- [PHP Manual: Visibility](https://www.php.net/manual/en/language.oop5.visibility.php)
- [PHP RFC: Property Visibility](https://wiki.php.net/rfc/property_visibility)
- Issue discovered during AlphaSMS v2 provider integration

---

## Bug: StatsDConfig Validation Running with Full Array Config

**Date**: 2026-01-20
**Severity**: MEDIUM
**Status**: FIXED

### Problem

When creating a `StatsDConfig` instance with all configuration values provided via an array, the environment variable validation still ran, causing unnecessary `RuntimeException` even though all required values were already provided.

```php
// This would fail even though all config is provided
$config = new StatsDConfig([
    'enabled' => true,
    'host' => '127.0.0.1',
    'port' => 8125,
    'namespace' => 'test',
    'sampling' => [
        'ok_events' => 0.1,
        'error_events' => 1.0,
        'latency' => 1.0,
        'payload' => 0.1,
    ]
]);
```

### Error Message

```
RuntimeException: Missing required StatsD environment variables: STATSD_HOST, STATSD_PORT, STATSD_NAMESPACE, STATSD_SAMPLE_OK, STATSD_SAMPLE_PAYLOAD. Please set these environment variables when STATSD_ENABLED is true.
```

### Root Cause

The `StatsDConfig` constructor always ran `validateRequiredEnvVars()` when enabled, without checking if the configuration was already fully provided via the array parameter.

### Solution

Check if all required config is provided via array before validating environment variables:

```php
if ($this->enabled) {
    // Check if all required config is provided via array - skip env validation
    $hasFullArrayConfig = isset($config['host'], $config['port'], $config['namespace'], $config['sampling']);

    if (!$hasFullArrayConfig) {
        $this->validateRequiredEnvVars();
    }
    // ... rest of initialization
}
```

### Impact

- Unit tests that provided full config via array would fail
- Programmatic configuration was effectively broken when environment variables weren't set
- Users couldn't use the library in test environments without setting env vars

### Files Changed

- `src/Config/StatsDConfig.php`

---

## Bug: PHP 8.4 Deprecation in NanoServiceMessage::getTimestampWithMs()

**Date**: 2026-01-20
**Severity**: LOW
**Status**: FIXED

### Problem

The `getTimestampWithMs()` method passed a float value to `date()`, which expects an integer. PHP 8.4 deprecated implicit float-to-int conversion.

```php
// Before (problematic)
$mic = microtime(true);  // Returns float like 1705752000.123
$baseFormat = date('Y-m-d H:i:s', $mic);  // $mic is float, date() expects int
```

### Warning Message

```
Deprecated: Implicit conversion from float 1705752000.123 to int loses precision in NanoServiceMessage.php on line 430
```

### Root Cause

`microtime(true)` returns a float (e.g., `1705752000.123456`), but `date()` expects the second parameter to be an integer timestamp.

### Solution

Add explicit `(int)` cast:

```php
public function getTimestampWithMs(): string
{
    $mic = microtime(true);
    $baseFormat = date('Y-m-d H:i:s', (int)$mic);  // Explicit cast
    $milliseconds = sprintf("%03d", ($mic - floor($mic)) * 1000);
    return $baseFormat . '.' . $milliseconds;
}
```

### Impact

- Deprecation warnings in PHP 8.4+ environments
- Future PHP versions may convert this to an error
- No functional impact on actual timestamp generation

### Files Changed

- `src/NanoServiceMessage.php`

### Testing

```php
$message = new NanoServiceMessage();
$timestamp = $message->getTimestampWithMs();
// Should output: "2026-01-20 12:00:00.123" (example)
echo $timestamp;
```

---

## Summary of All Fixed Bugs

| Date | Bug | Severity | File |
|------|-----|----------|------|
| 2026-01-20 | Duplicate `$statsD` property visibility | CRITICAL | `NanoConsumer.php` |
| 2026-01-20 | StatsDConfig validation with array config | MEDIUM | `StatsDConfig.php` |
| 2026-01-20 | PHP 8.4 float-to-int deprecation | LOW | `NanoServiceMessage.php` |
