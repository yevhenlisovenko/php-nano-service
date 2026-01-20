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
