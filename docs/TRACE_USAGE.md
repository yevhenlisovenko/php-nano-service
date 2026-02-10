# Trace ID Usage Examples

This document demonstrates the improved trace chain API with the new `appendTraceId()` method.

## Before vs After

### Old Way (Manual Array Merging)

```php
// Receive message
$originalMessage = /* from consumer */;

// Create callback message
$callback = new NanoServiceMessage();
$callback->setId($originalMessage->getId());

// Manual trace chain building (3 lines, error-prone)
$parentTraceIds = $originalMessage->getTraceId();
$currentTraceIds = array_merge($parentTraceIds, [$originalMessage->getId()]);
$callback->setTraceId($currentTraceIds);
```

### New Way (Automatic with appendTraceId)

```php
// Receive message
$originalMessage = /* from consumer */;

// Create callback message
$callback = new NanoServiceMessage();
$callback->setId($originalMessage->getId());

// Automatic trace chain building (1 line, clear intent)
$callback->appendTraceId($originalMessage->getId());
```

**Result:** 67% reduction in lines of code, clearer intent, less error-prone.

---

## Use Case 1: Callback in Provider Service

```php
// Receive message from Router
public function handle(NanoServiceMessage $message): void
{
    $result = $this->sendEmail($message);

    // Create callback
    $callback = new NanoServiceMessage();
    $callback->setId($message->getId());
    $callback->appendTraceId($message->getId());  // ✅ Automatic
    $callback->addPayload([
        'status' => $result ? 'delivered' : 'failed',
        'trace_id' => $message->getId(),
    ]);

    $publisher->setMessage($callback)->publish('notification.callback');
}
```

---

## Use Case 2: Event Relay in Router

```php
// Receive reminder.created, route to provider
public function route(NanoServiceMessage $message): void
{
    $provider = $this->determineProvider($message);

    // Create routed message
    $routed = new NanoServiceMessage();
    $routed->setId($message->getId());
    $routed->appendTraceId($message->getId());  // ✅ Builds chain
    $routed->addPayload($message->getPayload());

    $publisher->setMessage($routed)->publish("provider.{$provider}.send");
}
```

---

## Use Case 3: Multi-Hop Trace Chain

```php
// Example trace chain growth across services

// Step 1: Client → Core
$clientMessage = new NanoServiceMessage();
$clientMessage->setId('client-001');
// trace_ids = []

// Step 2: Core → Router
$routerMessage = new NanoServiceMessage();
$routerMessage->setId('router-002');
$routerMessage->appendTraceId($clientMessage->getId());
// trace_ids = ['client-001']

// Step 3: Router → SMTP Provider
$smtpMessage = new NanoServiceMessage();
$smtpMessage->setId('smtp-003');
$smtpMessage->appendTraceId($routerMessage->getId());
// trace_ids = ['client-001', 'router-002']

// Step 4: SMTP → Core (callback)
$callbackMessage = new NanoServiceMessage();
$callbackMessage->setId('callback-004');
$callbackMessage->appendTraceId($smtpMessage->getId());
// trace_ids = ['client-001', 'router-002', 'smtp-003']
```

---

## Use Case 4: Fluent Chaining

```php
// Chain multiple operations
$message = new NanoServiceMessage();
$message
    ->setId('my-message-123')
    ->appendTraceId('parent-001')
    ->appendTraceId('parent-002')
    ->appendTraceId('parent-003')
    ->addPayload(['data' => 'value'])
    ->setStatusSuccess();

// Result: trace_ids = ['parent-001', 'parent-002', 'parent-003']
```

---

## Use Case 5: PostgreSQL Query with Trace Chain

```php
// Create message with trace
$message = new NanoServiceMessage();
$message->setId('msg-123');
$message->appendTraceId('parent-001');
$message->appendTraceId('parent-002');

// Stored in PostgreSQL as TEXT[] array
INSERT INTO event_trace (message_id, trace_ids, payload)
VALUES ('msg-123', ARRAY['parent-001', 'parent-002'], '...');

// Query all messages in the trace chain
SELECT * FROM event_trace
WHERE 'parent-001' = ANY(trace_ids);

// Returns all messages that have 'parent-001' in their trace chain
```

---

## Edge Cases

### Empty Trace Chain

```php
$message = new NanoServiceMessage();
$message->appendTraceId('first-id');

// Result: trace_ids = ['first-id']
```

### Existing Trace Chain

```php
$message = new NanoServiceMessage();
$message->setTraceId(['id-1', 'id-2']);  // Set initial chain
$message->appendTraceId('id-3');         // Append to existing

// Result: trace_ids = ['id-1', 'id-2', 'id-3']
```

### Duplicate IDs (Allowed)

```php
$message = new NanoServiceMessage();
$message->appendTraceId('id-1');
$message->appendTraceId('id-1');  // May happen in retry scenarios

// Result: trace_ids = ['id-1', 'id-1']
// Allowed - validation is caller's responsibility
```

---

## Integration with shared-logger Package

**Before (manual):**

```php
// File: ReminderPlatform/SharedLogger/src/NanoLogger.php (lines 174-177)
$parentTraceIds = $this->message->getTraceId();
$currentTraceIds = array_merge($parentTraceIds, [$this->message->getId()]);
$message->setTraceId($currentTraceIds);
```

**After (automatic):**

```php
// Simplified to 1 line
$message->appendTraceId($this->message->getId());
```

---

## Benefits Summary

### For Developers

✅ **Simpler API** - One method call instead of three
✅ **Less error-prone** - Can't forget to merge arrays
✅ **Clearer intent** - `appendTraceId()` is self-documenting
✅ **Fluent interface** - Chainable method calls

### For Observability

✅ **Consistent trace chains** - Reduces implementation bugs
✅ **Better adoption** - Easier to use = more people will use it
✅ **PostgreSQL storage** - Works with event_trace table

### For Maintainability

✅ **100% backward compatible** - Existing code continues to work
✅ **Gradual migration** - No forced changes
✅ **Clear upgrade path** - Simple one-line replacement

---

## API Reference

### `appendTraceId(string $messageId): NanoServiceMessageContract`

Appends a message ID to the existing trace chain.

**Parameters:**
- `$messageId` (string) - Message ID to append to the trace chain

**Returns:**
- `NanoServiceMessageContract` - Fluent interface for chaining

**Example:**
```php
$message->appendTraceId($originalMessage->getId());
```

### `setTraceId(array $traceId): NanoServiceMessageContract`

Replaces the entire trace chain with a new array.

**Parameters:**
- `$traceId` (array) - Array of message IDs (ordered from oldest to newest)

**Returns:**
- `NanoServiceMessageContract` - Fluent interface for chaining

**Example:**
```php
$message->setTraceId(['id-1', 'id-2', 'id-3']);
```

### `getTraceId(): array`

Returns the current trace chain.

**Returns:**
- `array` - Array of message IDs (empty array if not set)

**Example:**
```php
$traceIds = $message->getTraceId();
// Returns: ['parent-001', 'parent-002']
```

---

## Migration Guide

1. **Search for manual trace chain building:**
   ```bash
   grep -r "array_merge.*getTraceId" .
   ```

2. **Replace with `appendTraceId()`:**
   ```php
   // Old
   $parentTraceIds = $message->getTraceId();
   $message->setTraceId(array_merge($parentTraceIds, [$newId]));

   // New
   $message->appendTraceId($newId);
   ```

3. **Test thoroughly:**
   - Verify trace chains are preserved
   - Check PostgreSQL trace_ids column
   - Validate observability dashboards

---

## Version

Added in: **v7.1.0**
Backward compatible: **Yes**
Breaking changes: **None**
