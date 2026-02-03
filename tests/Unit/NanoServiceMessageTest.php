<?php

namespace AlexFN\NanoService\Tests\Unit;

use AlexFN\NanoService\NanoServiceMessage;
use AlexFN\NanoService\Enums\NanoServiceMessageStatuses;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NanoServiceMessage
 *
 * Tests message creation, payload manipulation, status handling,
 * meta data, debug mode, and various getters/setters.
 */
class NanoServiceMessageTest extends TestCase
{
    // ==========================================
    // Constructor Tests
    // ==========================================

    public function testConstructorWithEmptyData(): void
    {
        $message = new NanoServiceMessage();

        $this->assertNotEmpty($message->getBody());
        $body = json_decode($message->getBody(), true);

        $this->assertArrayHasKey('meta', $body);
        $this->assertArrayHasKey('status', $body);
        $this->assertArrayHasKey('payload', $body);
        $this->assertArrayHasKey('system', $body);
    }

    public function testConstructorWithArrayData(): void
    {
        $data = ['payload' => ['key' => 'value']];
        $message = new NanoServiceMessage($data);

        $payload = $message->getPayload();
        $this->assertEquals('value', $payload['key']);
    }

    public function testConstructorWithStringData(): void
    {
        $jsonData = json_encode(['payload' => ['test' => 123]]);
        $message = new NanoServiceMessage($jsonData);

        $this->assertEquals($jsonData, $message->getBody());
    }

    public function testConstructorWithProperties(): void
    {
        $properties = ['type' => 'test.event'];
        $message = new NanoServiceMessage([], $properties);

        $this->assertEquals('test.event', $message->get('type'));
    }

    public function testConstructorSetsMessageId(): void
    {
        $message = new NanoServiceMessage();

        $this->assertNotEmpty($message->get('message_id'));
    }

    public function testConstructorSetsDeliveryModePersistent(): void
    {
        $message = new NanoServiceMessage();

        $this->assertEquals(2, $message->get('delivery_mode'));
    }

    // ==========================================
    // Payload Tests
    // ==========================================

    public function testAddPayload(): void
    {
        $message = new NanoServiceMessage();
        $message->addPayload(['key1' => 'value1']);

        $payload = $message->getPayload();
        $this->assertEquals('value1', $payload['key1']);
    }

    public function testAddPayloadMergesData(): void
    {
        $message = new NanoServiceMessage(['payload' => ['existing' => 'data']]);
        $message->addPayload(['new' => 'value']);

        $payload = $message->getPayload();
        $this->assertEquals('data', $payload['existing']);
        $this->assertEquals('value', $payload['new']);
    }

    public function testAddPayloadWithReplace(): void
    {
        $message = new NanoServiceMessage(['payload' => ['existing' => 'data']]);
        $message->addPayload(['new' => 'value'], true);

        $payload = $message->getPayload();
        $this->assertEquals('value', $payload['new']);
    }

    public function testAddPayloadAttribute(): void
    {
        $message = new NanoServiceMessage();
        $message->addPayloadAttribute('nested', ['key' => 'value']);

        $payload = $message->getPayload();
        $this->assertEquals(['key' => 'value'], $payload['nested']);
    }

    public function testGetPayloadAttribute(): void
    {
        $message = new NanoServiceMessage(['payload' => ['attr' => 'attrValue']]);

        $this->assertEquals('attrValue', $message->getPayloadAttribute('attr'));
    }

    public function testGetPayloadAttributeWithDefault(): void
    {
        $message = new NanoServiceMessage();

        $this->assertEquals('default', $message->getPayloadAttribute('nonexistent', 'default'));
        $this->assertNull($message->getPayloadAttribute('nonexistent'));
    }

    // ==========================================
    // Status Tests
    // ==========================================

    public function testSetStatus(): void
    {
        $message = new NanoServiceMessage();
        $message->setStatus(['code' => 'custom', 'data' => ['key' => 'value']]);

        $this->assertEquals('custom', $message->getStatusCode());
        $this->assertEquals(['key' => 'value'], $message->getStatusData());
    }

    public function testSetStatusCode(): void
    {
        $message = new NanoServiceMessage();
        $message->setStatusCode('test_code');

        $this->assertEquals('test_code', $message->getStatusCode());
    }

    public function testSetStatusData(): void
    {
        $message = new NanoServiceMessage();
        $message->setStatusData(['info' => 'test']);

        $this->assertEquals(['info' => 'test'], $message->getStatusData());
    }

    public function testSetStatusSuccess(): void
    {
        $message = new NanoServiceMessage();
        $message->setStatusSuccess();

        $this->assertEquals(NanoServiceMessageStatuses::SUCCESS(), $message->getStatusCode());
    }

    public function testSetStatusError(): void
    {
        $message = new NanoServiceMessage();
        $message->setStatusError();

        $this->assertEquals(NanoServiceMessageStatuses::ERROR(), $message->getStatusCode());
    }

    public function testIsStatusSuccess(): void
    {
        $message = new NanoServiceMessage();
        $message->setStatusSuccess();

        $this->assertTrue($message->isStatusSuccess());
    }

    public function testIsStatusSuccessReturnsFalseForError(): void
    {
        $message = new NanoServiceMessage();
        $message->setStatusError();

        $this->assertFalse($message->isStatusSuccess());
    }

    public function testGetStatusDebug(): void
    {
        $message = new NanoServiceMessage();
        $message->setStatus(['code' => 'test', 'debug' => 'debug info']);

        $this->assertEquals('debug info', $message->getStatusDebug());
    }

    public function testGetStatusError(): void
    {
        $message = new NanoServiceMessage();
        $message->setStatus(['code' => 'error', 'error' => 'error message']);

        $this->assertEquals('error message', $message->getStatusError());
    }

    public function testGetStatusDebugReturnsEmptyStringWhenNotSet(): void
    {
        $message = new NanoServiceMessage();

        $this->assertEquals('', $message->getStatusDebug());
    }

    public function testGetStatusErrorReturnsEmptyStringWhenNotSet(): void
    {
        $message = new NanoServiceMessage();

        $this->assertEquals('', $message->getStatusError());
    }

    // ==========================================
    // Meta Tests
    // ==========================================

    public function testAddMeta(): void
    {
        $message = new NanoServiceMessage();
        $message->addMeta(['tenant' => 'test-tenant']);

        $meta = $message->getMeta();
        $this->assertEquals('test-tenant', $meta['tenant']);
    }

    public function testAddMetaMergesData(): void
    {
        $message = new NanoServiceMessage(['meta' => ['existing' => 'value']]);
        $message->addMeta(['new' => 'data']);

        $meta = $message->getMeta();
        $this->assertEquals('value', $meta['existing']);
        $this->assertEquals('data', $meta['new']);
    }

    public function testAddMetaAttribute(): void
    {
        $message = new NanoServiceMessage();
        $message->addMetaAttribute('nested', ['key' => 'value']);

        $meta = $message->getMeta();
        $this->assertEquals(['key' => 'value'], $meta['nested']);
    }

    public function testGetMetaAttribute(): void
    {
        $message = new NanoServiceMessage(['meta' => ['attr' => 'attrValue']]);

        $this->assertEquals('attrValue', $message->getMetaAttribute('attr'));
    }

    public function testGetMetaAttributeWithDefault(): void
    {
        $message = new NanoServiceMessage();

        $this->assertEquals('default', $message->getMetaAttribute('nonexistent', 'default'));
        $this->assertNull($message->getMetaAttribute('nonexistent'));
    }

    // ==========================================
    // Tenant Tests
    // ==========================================

    public function testGetTenantProduct(): void
    {
        $message = new NanoServiceMessage(['meta' => ['product' => 'test-product']]);

        $this->assertEquals('test-product', $message->getTenantProduct());
    }

    public function testGetTenantEnv(): void
    {
        $message = new NanoServiceMessage(['meta' => ['env' => 'staging']]);

        $this->assertEquals('staging', $message->getTenantEnv());
    }

    public function testGetTenantSlug(): void
    {
        $message = new NanoServiceMessage(['meta' => ['tenant' => 'my-tenant']]);

        $this->assertEquals('my-tenant', $message->getTenantSlug());
    }

    public function testGetTenantAttributesReturnNullWhenNotSet(): void
    {
        $message = new NanoServiceMessage();

        $this->assertNull($message->getTenantProduct());
        $this->assertNull($message->getTenantEnv());
        $this->assertNull($message->getTenantSlug());
    }

    // ==========================================
    // Event/Message Property Tests
    // ==========================================

    public function testSetAndGetId(): void
    {
        $message = new NanoServiceMessage();
        $message->setId('custom-id-123');

        $this->assertEquals('custom-id-123', $message->getId());
    }

    public function testSetAndGetTraceId(): void
    {
        $message = new NanoServiceMessage();
        $traceId = ['span-123', 'trace-456', 'parent-789'];
        $message->setTraceId($traceId);

        $this->assertEquals($traceId, $message->getTraceId());
    }

    public function testGetTraceIdReturnsEmptyArrayWhenNotSet(): void
    {
        $message = new NanoServiceMessage();

        $this->assertIsArray($message->getTraceId());
        $this->assertEmpty($message->getTraceId());
    }

    public function testSetAndGetEvent(): void
    {
        $message = new NanoServiceMessage();
        $message->setEvent('user.created');

        $this->assertEquals('user.created', $message->getEventName());
    }

    public function testGetPublisherName(): void
    {
        $message = new NanoServiceMessage([], ['app_id' => 'publisher.service']);

        $this->assertEquals('publisher.service', $message->getPublisherName());
    }

    // ==========================================
    // Debug Mode Tests
    // ==========================================

    public function testSetDebugTrue(): void
    {
        $message = new NanoServiceMessage();
        $message->setDebug(true);

        $this->assertTrue($message->getDebug());
    }

    public function testSetDebugFalse(): void
    {
        $message = new NanoServiceMessage();
        $message->setDebug(true);
        $message->setDebug(false);

        $this->assertFalse($message->getDebug());
    }

    public function testDefaultDebugIsFalse(): void
    {
        $message = new NanoServiceMessage();

        $this->assertFalse($message->getDebug());
    }

    // ==========================================
    // Consumer Error Tests
    // ==========================================

    public function testSetAndGetConsumerError(): void
    {
        $message = new NanoServiceMessage();
        $message->setConsumerError('Test error message');

        $this->assertEquals('Test error message', $message->getConsumerError());
    }

    public function testGetConsumerErrorReturnsEmptyStringWhenNotSet(): void
    {
        $message = new NanoServiceMessage();

        $this->assertEquals('', $message->getConsumerError());
    }

    // ==========================================
    // Created At Tests
    // ==========================================

    public function testGetCreatedAtIsSetOnConstruction(): void
    {
        $message = new NanoServiceMessage();

        $createdAt = $message->getCreatedAt();
        $this->assertNotEmpty($createdAt);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', $createdAt);
    }

    public function testSetCreatedAt(): void
    {
        $message = new NanoServiceMessage();
        $message->setCreatedAt('2026-01-20 12:00:00.000');

        $this->assertEquals('2026-01-20 12:00:00.000', $message->getCreatedAt());
    }

    // ==========================================
    // Retry Count Tests
    // ==========================================

    public function testGetRetryCountReturnsZeroWhenNoHeaders(): void
    {
        $message = new NanoServiceMessage();

        $this->assertEquals(0, $message->getRetryCount());
    }

    // ==========================================
    // Timestamp With Milliseconds Tests
    // ==========================================

    public function testGetTimestampWithMsFormat(): void
    {
        $message = new NanoServiceMessage();
        $timestamp = $message->getTimestampWithMs();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', $timestamp);
    }

    // ==========================================
    // Data Structure Tests
    // ==========================================

    public function testDefaultDataStructure(): void
    {
        $message = new NanoServiceMessage();
        $body = json_decode($message->getBody(), true);

        $this->assertIsArray($body['meta']);
        $this->assertIsArray($body['status']);
        $this->assertIsArray($body['payload']);
        $this->assertIsArray($body['system']);
        $this->assertEquals('unknown', $body['status']['code']);
        $this->assertFalse($body['system']['is_debug']);
        $this->assertNull($body['system']['consumer_error']);
    }

    // ==========================================
    // Fluent Interface Tests
    // ==========================================

    public function testFluentInterfaceForPayload(): void
    {
        $message = new NanoServiceMessage();

        $result = $message->addPayload(['test' => 'value']);
        $this->assertInstanceOf(NanoServiceMessage::class, $result);

        $result = $message->addPayloadAttribute('attr', ['data']);
        $this->assertInstanceOf(NanoServiceMessage::class, $result);
    }

    public function testFluentInterfaceForStatus(): void
    {
        $message = new NanoServiceMessage();

        $result = $message->setStatus(['code' => 'test']);
        $this->assertInstanceOf(NanoServiceMessage::class, $result);

        $result = $message->setStatusCode('code');
        $this->assertInstanceOf(NanoServiceMessage::class, $result);

        $result = $message->setStatusData([]);
        $this->assertInstanceOf(NanoServiceMessage::class, $result);

        $result = $message->setStatusSuccess();
        $this->assertInstanceOf(NanoServiceMessage::class, $result);

        $result = $message->setStatusError();
        $this->assertInstanceOf(NanoServiceMessage::class, $result);
    }

    public function testFluentInterfaceForMeta(): void
    {
        $message = new NanoServiceMessage();

        $result = $message->addMeta(['test' => 'value']);
        $this->assertInstanceOf(NanoServiceMessage::class, $result);

        $result = $message->addMetaAttribute('attr', ['data']);
        $this->assertInstanceOf(NanoServiceMessage::class, $result);
    }

    public function testFluentInterfaceForOtherMethods(): void
    {
        $message = new NanoServiceMessage();

        $result = $message->setId('id');
        $this->assertInstanceOf(NanoServiceMessage::class, $result);

        $result = $message->setTraceId(['trace-1', 'trace-2']);
        $this->assertInstanceOf(NanoServiceMessage::class, $result);

        $result = $message->setEvent('event');
        $this->assertInstanceOf(NanoServiceMessage::class, $result);

        $result = $message->setDebug(true);
        $this->assertInstanceOf(NanoServiceMessage::class, $result);

        $result = $message->setConsumerError('error');
        $this->assertInstanceOf(NanoServiceMessage::class, $result);

        $result = $message->setCreatedAt('2026-01-01 00:00:00.000');
        $this->assertInstanceOf(NanoServiceMessage::class, $result);
    }
}
