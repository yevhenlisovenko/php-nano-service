# Code Review Checklist

Use this checklist when reviewing code changes to avoid common pitfalls.

---

## ✅ PHP Inheritance & OOP

- [ ] **No duplicate property declarations** in child classes
- [ ] **Property visibility** is same or weaker than parent class
  - ✅ Allowed: `protected` → `public` (weaker)
  - ❌ Forbidden: `protected` → `private` (stricter)
- [ ] **Check parent class** before adding properties to child class
- [ ] **Use `@inheritDoc`** when overriding methods from parent/interface

**Example Issue:**
```php
// ❌ WRONG - Fatal error
class Child extends Parent {
    private StatsDClient $statsD;  // Parent has protected $statsD
}

// ✅ CORRECT - Remove duplicate or use weaker visibility
class Child extends Parent {
    // Inherited from parent as protected
    // No need to redeclare
}
```

**Reference:** [docs/BUGFIXES.md](BUGFIXES.md) - Duplicate Property Visibility

---

## ✅ Type Safety

- [ ] **All properties have type declarations** (PHP 7.4+)
- [ ] **All methods have return types**
- [ ] **Nullable types** use `?Type` syntax
- [ ] **Array shapes** are documented with PHPDoc

**Example:**
```php
// ✅ GOOD
protected ?StatsDClient $statsD = null;

/** @var array<string, class-string> */
protected array $handlers = [];

public function getStats(): array { }
```

---

## ✅ Error Handling

- [ ] **Exceptions are caught** and logged appropriately
- [ ] **Try-finally blocks** are used for cleanup (close connections, release resources)
- [ ] **Circuit breaker pattern** is used for external service calls
- [ ] **Error context** is included in logs (message ID, user ID, etc.)

---

## ✅ Testing

- [ ] **Unit tests** cover new functionality
- [ ] **Integration tests** verify RabbitMQ communication
- [ ] **Test with multiple PHP versions** (8.1, 8.2, 8.3)
- [ ] **Static analysis passes** (PHPStan level 8)

---

## ✅ Documentation

- [ ] **PHPDoc comments** on all public methods
- [ ] **README updated** if API changes
- [ ] **CHANGELOG.md updated** with breaking changes
- [ ] **BUGFIXES.md updated** if fixing a critical issue

---

## ✅ RabbitMQ Best Practices

- [ ] **Message signing** is enabled for security
- [ ] **Idempotency checks** in consumer handlers
- [ ] **Exponential backoff** for retries
- [ ] **Dead letter queue** configured for failed messages
- [ ] **Connection health monitoring** with StatsD metrics

---

## ✅ Metrics & Observability

- [ ] **StatsD metrics** are emitted for key operations
- [ ] **Metric names follow convention**: `rmq_consumer_*`, `rmq_publisher_*`
- [ ] **Labels/tags** are added for dimensions (event_name, status, retry_status)
- [ ] **Histogram buckets** are appropriate for latency measurements

---

## ✅ Performance

- [ ] **Database queries** are optimized (no N+1 queries)
- [ ] **Connection pooling** is used for external services
- [ ] **Batch processing** for high-volume operations
- [ ] **Memory usage** is monitored in long-running consumers

---

## ✅ Security

- [ ] **Environment variables** used for credentials (no hardcoded secrets)
- [ ] **Input validation** on all public methods
- [ ] **Message signature verification** for RabbitMQ messages
- [ ] **Dependencies** are up to date (run `composer audit`)

---

## 🔍 Common Issues Caught

### 1. Duplicate Property Declaration (CRITICAL)
```php
// ❌ This will fail in child class
class Parent {
    protected ?StatsDClient $statsD = null;
}

class Child extends Parent {
    private StatsDClient $statsD;  // FATAL ERROR!
}
```

**Prevention:** Check parent class before adding properties

### 2. Missing Required Env Vars
```php
// ❌ Silent failure with fallback
$host = $_ENV['AMQP_HOST'] ?? 'localhost';

// ✅ Fail fast with clear error
if (!isset($_ENV['AMQP_HOST'])) {
    throw new RuntimeException('Missing AMQP_HOST');
}
```

### 3. Forgotten Try-Finally for Metrics
```php
// ❌ Metrics not recorded on exception
$startTime = microtime(true);
$result = $this->process();
$this->statsD->histogram('duration', microtime(true) - $startTime);

// ✅ Always record metrics
$startTime = microtime(true);
try {
    return $this->process();
} finally {
    $this->statsD->histogram('duration', microtime(true) - $startTime);
}
```

---

## 🚀 Tools

- **PHPStan**: `composer require --dev phpstan/phpstan`
- **Psalm**: `composer require --dev vimeo/psalm`
- **PHP-CS-Fixer**: `composer require --dev friendsofphp/php-cs-fixer`
- **PHPUnit**: `composer require --dev phpunit/phpunit`

---

## 📚 References

- [PHP Manual: OOP Visibility](https://www.php.net/manual/en/language.oop5.visibility.php)
- [docs/BUGFIXES.md](BUGFIXES.md) - Known issues and fixes
- [docs/TROUBLESHOOTING.md](TROUBLESHOOTING.md) - Common problems
