# Code Review Checklist

Use this checklist when reviewing code changes.

---

## PHP Inheritance & OOP

- [ ] No duplicate property declarations in child classes
- [ ] Property visibility same or weaker than parent class
- [ ] Check parent class before adding properties
- [ ] Use `@inheritDoc` when overriding methods

**Common Issue:**
```php
// WRONG - Fatal error
class Child extends Parent {
    private StatsDClient $statsD;  // Parent has protected $statsD
}

// CORRECT - Remove duplicate
class Child extends Parent {
    // Inherited from parent
}
```

---

## Type Safety

- [ ] All properties have type declarations
- [ ] All methods have return types
- [ ] Nullable types use `?Type` syntax
- [ ] Array shapes documented with PHPDoc

---

## Error Handling

- [ ] Exceptions caught and logged appropriately
- [ ] Try-finally blocks for cleanup
- [ ] Error context included in logs

---

## Testing

- [ ] Unit tests cover new functionality
- [ ] Test with PHP 8.1, 8.2, 8.3
- [ ] PHPStan level 8 passes

---

## Documentation

- [ ] PHPDoc on public methods
- [ ] README updated if API changes
- [ ] CHANGELOG.md updated

---

## RabbitMQ

- [ ] Connection reuse (don't create per-request)
- [ ] Idempotency in handlers
- [ ] Exponential backoff for retries
- [ ] DLX configured

---

## Metrics

- [ ] StatsD metrics for key operations
- [ ] Bounded tags (no user_id, request_id)
- [ ] Try-finally pattern for timing

```php
// WRONG - Metrics lost on exception
$start = microtime(true);
$result = $this->process();
$this->statsD->histogram('duration', microtime(true) - $start);

// CORRECT - Always recorded
$start = microtime(true);
try {
    return $this->process();
} finally {
    $this->statsD->histogram('duration', microtime(true) - $start);
}
```

---

## Security

- [ ] Environment variables for credentials
- [ ] Input validation on public methods
- [ ] `composer audit` clean

---

## Tools

```bash
# Static analysis
composer require --dev phpstan/phpstan
./vendor/bin/phpstan analyse -l 8 src/

# Tests
./vendor/bin/phpunit

# Code style
composer require --dev friendsofphp/php-cs-fixer
```

---

## References

- [BUGFIXES.md](BUGFIXES.md)
- [PHP Visibility](https://www.php.net/manual/en/language.oop5.visibility.php)
