# Bug Fixes and Known Issues

Critical bug fixes in nano-service.

---

## Summary

| Date | Bug | Severity | File |
|------|-----|----------|------|
| 2026-01-22 | PHP 8.4 nullable parameter deprecation | LOW | `NanoConsumer.php` |
| 2026-01-20 | Duplicate `$statsD` property visibility | CRITICAL | `NanoConsumer.php` |
| 2026-01-20 | StatsDConfig validation with array config | MEDIUM | `StatsDConfig.php` |
| 2026-01-20 | PHP 8.4 float-to-int deprecation | LOW | `NanoServiceMessage.php` |
| 2026-01-16 | Channel leak in getChannel() | CRITICAL | `NanoServiceClass.php` |

---

## Critical: Duplicate Property Visibility

**Date:** 2026-01-20
**Status:** FIXED

### Problem

```php
// Parent
protected ?StatsDClient $statsD = null;

// Child - WRONG
private StatsDClient $statsD;  // Fatal error!
```

### Error

```
Fatal error: Access level to NanoConsumer::$statsD must be protected
```

### Fix

Remove duplicate property declaration from child class.

### Prevention

- Check parent before adding properties
- Run PHPStan level 8
- Test with multiple PHP versions

---

## Critical: Channel Leak

**Date:** 2026-01-16
**Status:** FIXED

See [CHANGELOG.md](../CHANGELOG.md) for full details.

### Problem

`getChannel()` created new channels without storing in shared pool.

### Impact

- 17,840 channels (97% reduction after fix)
- "No free channel ids" errors

### Fix

```php
if (! $this->channel || !$this->channel->is_open()) {
    $this->channel = $this->getConnection()->channel();
    self::$sharedChannel = $this->channel;  // Store for reuse
}
```

---

## Medium: StatsDConfig Array Validation

**Date:** 2026-01-20
**Status:** FIXED

### Problem

Environment validation ran even when full config provided via array.

### Fix

Skip env validation when all required config provided programmatically.

---

## Low: PHP 8.4 Deprecations

**Date:** 2026-01-20
**Status:** FIXED

### float-to-int in date()

```php
// Before
date('Y-m-d H:i:s', $mic);

// After
date('Y-m-d H:i:s', (int)$mic);
```

### Nullable parameter

```php
// Before
function consume(?callable $callback)

// After
function consume(?callable $callback = null)
```

---

## References

- [PHP Visibility](https://www.php.net/manual/en/language.oop5.visibility.php)
- [CODE_REVIEW.md](CODE_REVIEW.md)
