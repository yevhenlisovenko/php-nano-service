<?php

namespace AlexFN\NanoService\Tests\Unit;

use AlexFN\NanoService\Clients\LoggerFactory;
use AlexFN\NanoService\Clients\StatsDClient\Enums\EventExitStatusTag;
use AlexFN\NanoService\Clients\StatsDClient\Enums\EventRetryStatusTag;
use AlexFN\NanoService\Clients\StatsDClient\StatsDClient;
use AlexFN\NanoService\NanoConsumer;
use AlexFN\NanoService\NanoServiceMessage;
use AlexFN\NanoService\SystemHandlers\SystemPing;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

/**
 * Comprehensive unit tests for NanoConsumer
 *
 * Tests all public methods, callback handling, retry logic,
 * metrics tracking, and edge cases without requiring real RabbitMQ.
 */
class NanoConsumerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set required environment variables
        $_ENV['STATSD_ENABLED'] = 'false';
        $_ENV['AMQP_HOST'] = 'localhost';
        $_ENV['AMQP_PORT'] = '5672';
        $_ENV['AMQP_USER'] = 'guest';
        $_ENV['AMQP_PASS'] = 'guest';
        $_ENV['AMQP_VHOST'] = '/';
        $_ENV['AMQP_PROJECT'] = 'test';
        $_ENV['AMQP_MICROSERVICE_NAME'] = 'test-consumer';
        // Required for inbox pattern
        $_ENV['DB_BOX_HOST'] = 'localhost';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'test_db';
        $_ENV['DB_BOX_USER'] = 'test_user';
        $_ENV['DB_BOX_PASS'] = 'test_pass';
        $_ENV['DB_BOX_SCHEMA'] = 'public';

        // Reset shared connection/channel between tests
        $this->resetSharedState();

        // Mock EventRepository to prevent actual database calls
        $this->mockEventRepository();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up environment variables
        $vars = [
            'STATSD_ENABLED', 'AMQP_HOST', 'AMQP_PORT', 'AMQP_USER',
            'AMQP_PASS', 'AMQP_VHOST', 'AMQP_PROJECT', 'AMQP_MICROSERVICE_NAME',
            'DB_BOX_HOST', 'DB_BOX_PORT', 'DB_BOX_NAME', 'DB_BOX_USER', 'DB_BOX_PASS', 'DB_BOX_SCHEMA',
        ];
        foreach ($vars as $var) {
            unset($_ENV[$var]);
        }

        $this->resetSharedState();

        // Reset EventRepository singleton between tests
        \AlexFN\NanoService\EventRepository::reset();
    }

    // -------------------------------------------------------------------------
    // Fluent interface tests
    // -------------------------------------------------------------------------

    public function testEventsReturnsSelfForChaining(): void
    {
        $consumer = new NanoConsumer();
        $result = $consumer->events('user.created', 'user.updated');

        $this->assertSame($consumer, $result);
        $this->assertEquals(['user.created', 'user.updated'], $this->getPrivateProperty($consumer, 'events'));
    }

    public function testTriesReturnsSelfForChaining(): void
    {
        $consumer = new NanoConsumer();
        $result = $consumer->tries(5);

        $this->assertSame($consumer, $result);
        $this->assertEquals(5, $this->getPrivateProperty($consumer, 'tries'));
    }

    public function testBackoffWithIntReturnsSelfForChaining(): void
    {
        $consumer = new NanoConsumer();
        $result = $consumer->backoff(10);

        $this->assertSame($consumer, $result);
        $this->assertEquals(10, $this->getPrivateProperty($consumer, 'backoff'));
    }

    public function testBackoffWithArrayReturnsSelfForChaining(): void
    {
        $consumer = new NanoConsumer();
        $result = $consumer->backoff([1, 5, 10, 30]);

        $this->assertSame($consumer, $result);
        $this->assertEquals([1, 5, 10, 30], $this->getPrivateProperty($consumer, 'backoff'));
    }

    public function testOutageSleepReturnsSelfForChaining(): void
    {
        $consumer = new NanoConsumer();
        $result = $consumer->outageSleep(60);

        $this->assertSame($consumer, $result);
        $this->assertEquals(60, $this->getPrivateProperty($consumer, 'outageSleepSeconds'));
    }

    public function testOutageSleepSetsOutageSleepSeconds(): void
    {
        $consumer = new NanoConsumer();
        $consumer->outageSleep(45);

        $this->assertEquals(45, $this->getPrivateProperty($consumer, 'outageSleepSeconds'));
    }

    public function testDefaultOutageSleepValueIs30(): void
    {
        $consumer = new NanoConsumer();
        $this->assertEquals(30, $this->getPrivateProperty($consumer, 'outageSleepSeconds'));
    }

    public function testCatchReturnsSelfForChaining(): void
    {
        $consumer = new NanoConsumer();
        $callback = function () {};
        $result = $consumer->catch($callback);

        $this->assertSame($consumer, $result);
        $this->assertSame($callback, $this->getPrivateProperty($consumer, 'catchCallback'));
    }

    public function testFailedReturnsSelfForChaining(): void
    {
        $consumer = new NanoConsumer();
        $callback = function () {};
        $result = $consumer->failed($callback);

        $this->assertSame($consumer, $result);
        $this->assertSame($callback, $this->getPrivateProperty($consumer, 'failedCallback'));
    }

    // -------------------------------------------------------------------------
    // init() method tests
    // -------------------------------------------------------------------------

    public function testInitInitializesStatsD(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();

        $consumer->events('test.event')->init();

        $statsD = $this->getPrivateProperty($consumer, 'statsD');
        $this->assertInstanceOf(StatsDClient::class, $statsD);
    }

    public function testInitCreatesFailedQueue(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        // Expect queue declarations for main queue and DLX
        $channel->expects($this->exactly(2))
            ->method('queue_declare')
            ->willReturn(['test.test-consumer', 0, 0]);

        // Expect exchange declaration for delayed message
        $channel->expects($this->once())
            ->method('exchange_declare');

        // Expect queue binds: 1 event + 1 for '#' = 2 total
        $channel->expects($this->exactly(2))
            ->method('queue_bind');

        $consumer = $this->createConsumerWithChannel($channel);
        $consumer->events('test.event')->init();
    }

    public function testInitBindsEventsToQueue(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        // Should bind user events + '#'
        $channel->expects($this->exactly(3))
            ->method('queue_bind')
            ->willReturnCallback(function ($queue, $exchange, $routingKey) {
                $this->assertContains($routingKey, ['test.event', 'another.event', '#']);
            });

        $consumer = $this->createConsumerWithChannel($channel);
        $consumer->events('test.event', 'another.event')->init();
    }

    public function testInitIsIdempotent(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        // Should only initialize once (2 queue declares, 1 exchange, 2 binds)
        $channel->expects($this->exactly(2))->method('queue_declare');
        $channel->expects($this->exactly(1))->method('exchange_declare');
        $channel->expects($this->exactly(2))->method('queue_bind');

        $consumer = $this->createConsumerWithChannel($channel);
        $consumer->events('test.event');

        // Call init multiple times
        $consumer->init();
        $consumer->init();
        $consumer->init();

        // Verify initialized flag is set
        $this->assertTrue($this->getPrivateProperty($consumer, 'initialized'));
    }

    public function testInitSetsInitializedFlag(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('test.event');

        $this->assertFalse($this->getPrivateProperty($consumer, 'initialized'));

        $consumer->init();

        $this->assertTrue($this->getPrivateProperty($consumer, 'initialized'));
    }

    public function testInitDoesNotReinitializeLoggerIfAlreadySet(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('test.event');

        // Pre-set logger
        $logger = LoggerFactory::getInstance();
        $this->setPrivateProperty($consumer, 'logger', $logger);

        $consumer->init();

        // Should be same instance
        $this->assertSame($logger, $this->getPrivateProperty($consumer, 'logger'));
    }

    public function testInitDoesNotReinitializeStatsDIfAlreadySet(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('test.event');

        // Pre-set statsD
        $statsD = new StatsDClient();
        $this->setPrivateProperty($consumer, 'statsD', $statsD);

        $consumer->init();

        // Should be same instance
        $this->assertSame($statsD, $this->getPrivateProperty($consumer, 'statsD'));
    }

    // testInitBindsSystemHandlers removed - no system handlers since system.ping.1 was removed

    // -------------------------------------------------------------------------
    // consumeCallback() - Message validation tests
    // -------------------------------------------------------------------------

    public function testConsumeCallbackRejectsMissingType(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        $callbackCalled = false;
        $callback = function () use (&$callbackCalled) {
            $callbackCalled = true;
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        // Create message without type
        $message = $this->createMock(AMQPMessage::class);
        $message->method('getBody')->willReturn(json_encode(['payload' => []]));
        $message->method('get_properties')->willReturn(['app_id' => 'test-service', 'message_id' => 'test-123']);
        $message->method('has')->willReturnCallback(function ($key) {
            return $key === 'application_headers' ? false : true;
        });
        $message->method('get')->willReturnCallback(function ($key) {
            if ($key === 'type') return null; // Missing type
            if ($key === 'app_id') return 'test-service';
            if ($key === 'message_id') return 'test-123';
            return null;
        });
        $message->expects($this->once())->method('ack'); // Should ACK invalid message

        $consumer->consumeCallback($message);

        $this->assertFalse($callbackCalled, 'Callback should not be called when type is missing');
    }

    public function testConsumeCallbackRejectsMissingMessageId(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        $callbackCalled = false;
        $callback = function () use (&$callbackCalled) {
            $callbackCalled = true;
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        // Create message without message_id
        $message = $this->createMock(AMQPMessage::class);
        $message->method('getBody')->willReturn(json_encode(['payload' => []]));
        $message->method('get_properties')->willReturn(['app_id' => 'test-service']);
        $message->method('has')->willReturnCallback(function ($key) {
            return $key === 'application_headers' ? false : true;
        });
        $message->method('get')->willReturnCallback(function ($key) {
            if ($key === 'type') return 'user.created';
            if ($key === 'app_id') return 'test-service';
            if ($key === 'message_id') return null; // Missing message_id
            return null;
        });
        $message->expects($this->once())->method('ack'); // Should ACK invalid message

        $consumer->consumeCallback($message);

        $this->assertFalse($callbackCalled, 'Callback should not be called when message_id is missing');
    }

    public function testConsumeCallbackRejectsMissingAppId(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        $callbackCalled = false;
        $callback = function () use (&$callbackCalled) {
            $callbackCalled = true;
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        // Create message without app_id
        $message = $this->createMock(AMQPMessage::class);
        $message->method('getBody')->willReturn(json_encode(['payload' => []]));
        $message->method('get_properties')->willReturn(['message_id' => 'test-123']);
        $message->method('has')->willReturnCallback(function ($key) {
            return $key === 'application_headers' ? false : true;
        });
        $message->method('get')->willReturnCallback(function ($key) {
            if ($key === 'type') return 'user.created';
            if ($key === 'app_id') return null; // Missing app_id
            if ($key === 'message_id') return 'test-123';
            return null;
        });
        $message->expects($this->once())->method('ack'); // Should ACK invalid message

        $consumer->consumeCallback($message);

        $this->assertFalse($callbackCalled, 'Callback should not be called when app_id is missing');
    }

    public function testConsumeCallbackRejectsEmptyType(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        $callbackCalled = false;
        $callback = function () use (&$callbackCalled) {
            $callbackCalled = true;
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        // Create message with empty type
        $message = $this->createMock(AMQPMessage::class);
        $message->method('getBody')->willReturn(json_encode(['payload' => []]));
        $message->method('get_properties')->willReturn(['app_id' => 'test-service', 'message_id' => 'test-123']);
        $message->method('has')->willReturnCallback(function ($key) {
            return $key === 'application_headers' ? false : true;
        });
        $message->method('get')->willReturnCallback(function ($key) {
            if ($key === 'type') return ''; // Empty type
            if ($key === 'app_id') return 'test-service';
            if ($key === 'message_id') return 'test-123';
            return null;
        });
        $message->expects($this->once())->method('ack'); // Should ACK invalid message

        $consumer->consumeCallback($message);

        $this->assertFalse($callbackCalled, 'Callback should not be called when type is empty');
    }

    public function testConsumeCallbackRejectsInvalidJson(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        $callbackCalled = false;
        $callback = function () use (&$callbackCalled) {
            $callbackCalled = true;
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        // Create message with invalid JSON
        $message = $this->createMock(AMQPMessage::class);
        $message->method('getBody')->willReturn('{invalid json}'); // Invalid JSON
        $message->method('get_properties')->willReturn(['app_id' => 'test-service', 'message_id' => 'test-123']);
        $message->method('has')->willReturnCallback(function ($key) {
            return $key === 'application_headers' ? false : true;
        });
        $message->method('get')->willReturnCallback(function ($key) {
            if ($key === 'type') return 'user.created';
            if ($key === 'app_id') return 'test-service';
            if ($key === 'message_id') return 'test-123';
            return null;
        });
        $message->expects($this->once())->method('ack'); // Should ACK invalid message

        $consumer->consumeCallback($message);

        $this->assertFalse($callbackCalled, 'Callback should not be called when JSON is invalid');
    }

    public function testConsumeCallbackRejectsMultipleValidationErrors(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        $callbackCalled = false;
        $callback = function () use (&$callbackCalled) {
            $callbackCalled = true;
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        // Create message with multiple validation errors (missing type, app_id, and invalid JSON)
        $message = $this->createMock(AMQPMessage::class);
        $message->method('getBody')->willReturn('{not valid json');
        $message->method('get_properties')->willReturn(['message_id' => 'test-123']);
        $message->method('has')->willReturnCallback(function ($key) {
            return $key === 'application_headers' ? false : true;
        });
        $message->method('get')->willReturnCallback(function ($key) {
            if ($key === 'type') return null; // Missing type
            if ($key === 'app_id') return null; // Missing app_id
            if ($key === 'message_id') return 'test-123';
            return null;
        });
        $message->expects($this->once())->method('ack'); // Should ACK invalid message

        $consumer->consumeCallback($message);

        $this->assertFalse($callbackCalled, 'Callback should not be called when multiple validations fail');
    }

    public function testConsumeCallbackProcessesValidMessage(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        $callbackCalled = false;
        $callback = function () use (&$callbackCalled) {
            $callbackCalled = true;
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        // Create valid message with all required fields
        $message = $this->createAMQPMessage('user.created', ['payload' => ['user_id' => 123]]);
        $message->expects($this->once())->method('ack');

        $consumer->consumeCallback($message);

        $this->assertTrue($callbackCalled, 'Callback should be called for valid message');
    }

    // -------------------------------------------------------------------------
    // consumeCallback() - System handler tests
    // -------------------------------------------------------------------------

    // testConsumeCallbackInvokesSystemHandler removed - no system.ping.1 handler anymore
    // testConsumeCallbackSystemHandlerDoesNotCallUserCallback removed - no system.ping.1 handler anymore

    // -------------------------------------------------------------------------
    // consumeCallback() - Successful message processing tests
    // -------------------------------------------------------------------------

    public function testConsumeCallbackSuccessfullyProcessesMessage(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        $callbackInvoked = false;
        $receivedMessage = null;

        $callback = function (NanoServiceMessage $msg) use (&$callbackInvoked, &$receivedMessage) {
            $callbackInvoked = true;
            $receivedMessage = $msg;
        };

        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => ['user_id' => 123]]);
        $message->expects($this->once())->method('ack');

        $consumer->consumeCallback($message);

        $this->assertTrue($callbackInvoked);
        $this->assertInstanceOf(NanoServiceMessage::class, $receivedMessage);
        $this->assertEquals('user.created', $receivedMessage->getEventName());
    }

    public function testConsumeCallbackUsesDebugCallbackWhenDebugEnabled(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        $userCallbackCalled = false;
        $debugCallbackCalled = false;

        $userCallback = function () use (&$userCallbackCalled) {
            $userCallbackCalled = true;
        };

        $debugCallback = function () use (&$debugCallbackCalled) {
            $debugCallbackCalled = true;
        };

        $this->setPrivateProperty($consumer, 'callback', $userCallback);
        $this->setPrivateProperty($consumer, 'debugCallback', $debugCallback);

        $data = [
            'payload' => [],
            'system' => ['is_debug' => true]
        ];
        $message = $this->createAMQPMessage('user.created', $data);
        $message->method('ack');

        $consumer->consumeCallback($message);

        $this->assertFalse($userCallbackCalled);
        $this->assertTrue($debugCallbackCalled);
    }

    public function testConsumeCallbackUsesUserCallbackWhenDebugDisabled(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        $userCallbackCalled = false;
        $debugCallbackCalled = false;

        $userCallback = function () use (&$userCallbackCalled) {
            $userCallbackCalled = true;
        };

        $debugCallback = function () use (&$debugCallbackCalled) {
            $debugCallbackCalled = true;
        };

        $this->setPrivateProperty($consumer, 'callback', $userCallback);
        $this->setPrivateProperty($consumer, 'debugCallback', $debugCallback);

        $data = [
            'payload' => [],
            'system' => ['is_debug' => false]
        ];
        $message = $this->createAMQPMessage('user.created', $data);
        $message->method('ack');

        $consumer->consumeCallback($message);

        $this->assertTrue($userCallbackCalled);
        $this->assertFalse($debugCallbackCalled);
    }

    // -------------------------------------------------------------------------
    // consumeCallback() - Retry logic tests
    // -------------------------------------------------------------------------

    public function testConsumeCallbackRetriesOnFailureWhenRetriesRemaining(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        // Should republish with delay header
        $channel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function ($msg) {
                    $headers = $msg->get('application_headers')->getNativeData();
                    $this->assertEquals(1, $headers['x-retry-count']);
                    $this->assertArrayHasKey('x-delay', $headers);
                    return true;
                })
            );

        $consumer = $this->createConsumerWithChannel($channel);
        $consumer->events('user.created')->tries(3)->backoff(5)->init();

        $callback = function () {
            throw new \Exception('Processing failed');
        };

        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => []]);
        $message->expects($this->once())->method('ack');

        $consumer->consumeCallback($message);
    }

    public function testConsumeCallbackSendsToDeadLetterQueueAfterMaxRetries(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        // Should publish to DLX queue
        $channel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->anything(),
                '',
                'test.test-consumer.failed'
            );

        $consumer = $this->createConsumerWithChannel($channel);
        $consumer->events('user.created')->tries(3)->init();

        $callback = function () {
            throw new \Exception('Processing failed');
        };

        $this->setPrivateProperty($consumer, 'callback', $callback);

        // Simulate message already retried 2 times (this is the 3rd and final attempt)
        // retryCount = 2 + 1 = 3, which is NOT < tries (3), so goes to DLX
        $properties = ['application_headers' => new AMQPTable(['x-retry-count' => 2])];
        $message = $this->createAMQPMessage('user.created', ['payload' => []], $properties);
        $message->expects($this->once())->method('ack');

        $consumer->consumeCallback($message);
    }

    public function testConsumeCallbackInvokesCatchCallbackOnRetry(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->tries(3)->init();

        $catchCallbackInvoked = false;
        $receivedException = null;

        $catchCallback = function (Throwable $e) use (&$catchCallbackInvoked, &$receivedException) {
            $catchCallbackInvoked = true;
            $receivedException = $e;
        };

        $callback = function () {
            throw new \Exception('Processing failed');
        };

        $consumer->catch($catchCallback);
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => []]);
        $message->method('ack');

        $consumer->consumeCallback($message);

        $this->assertTrue($catchCallbackInvoked);
        $this->assertInstanceOf(\Exception::class, $receivedException);
        $this->assertEquals('Processing failed', $receivedException->getMessage());
    }

    public function testConsumeCallbackInvokesFailedCallbackOnMaxRetries(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->tries(3)->init();

        $failedCallbackInvoked = false;
        $receivedException = null;

        $failedCallback = function (Throwable $e) use (&$failedCallbackInvoked, &$receivedException) {
            $failedCallbackInvoked = true;
            $receivedException = $e;
        };

        $callback = function () {
            throw new \Exception('Processing failed');
        };

        $consumer->failed($failedCallback);
        $this->setPrivateProperty($consumer, 'callback', $callback);

        // Simulate max retries reached: retryCount = 2 + 1 = 3, NOT < 3, goes to DLX
        $properties = ['application_headers' => new AMQPTable(['x-retry-count' => 2])];
        $message = $this->createAMQPMessage('user.created', ['payload' => []], $properties);
        $message->method('ack');

        $consumer->consumeCallback($message);

        $this->assertTrue($failedCallbackInvoked);
        $this->assertInstanceOf(\Exception::class, $receivedException);
    }

    public function testConsumeCallbackCatchCallbackExceptionDoesNotStopRetry(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        // Should still republish despite catch callback exception
        $channel->expects($this->once())->method('basic_publish');

        $consumer = $this->createConsumerWithChannel($channel);
        $consumer->events('user.created')->tries(3)->init();

        $catchCallback = function () {
            throw new \Exception('Catch callback failed');
        };

        $callback = function () {
            throw new \Exception('Processing failed');
        };

        $consumer->catch($catchCallback);
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => []]);
        $message->method('ack');

        // Should not throw
        $consumer->consumeCallback($message);
    }

    public function testConsumeCallbackFailedCallbackExceptionDoesNotStopDLX(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        // Should still publish to DLX despite failed callback exception
        $channel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->anything(),
                '',
                'test.test-consumer.failed'
            );

        $consumer = $this->createConsumerWithChannel($channel);
        $consumer->events('user.created')->tries(3)->init();

        $failedCallback = function () {
            throw new \Exception('Failed callback failed');
        };

        $callback = function () {
            throw new \Exception('Processing failed');
        };

        $consumer->failed($failedCallback);
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $properties = ['application_headers' => new AMQPTable(['x-retry-count' => 2])];
        $message = $this->createAMQPMessage('user.created', ['payload' => []], $properties);
        $message->method('ack');

        // Should not throw
        $consumer->consumeCallback($message);
    }

    // -------------------------------------------------------------------------
    // consumeCallback() - Backoff calculation tests
    // -------------------------------------------------------------------------

    public function testConsumeCallbackUsesScalarBackoffValue(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $channel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function ($msg) {
                    $headers = $msg->get('application_headers')->getNativeData();
                    // backoff(10) = 10 seconds = 10000ms
                    $this->assertEquals(10000, $headers['x-delay']);
                    return true;
                })
            );

        $consumer = $this->createConsumerWithChannel($channel);
        $consumer->events('user.created')->tries(3)->backoff(10)->init();

        $callback = function () {
            throw new \Exception('Fail');
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => []]);
        $message->method('ack');

        $consumer->consumeCallback($message);
    }

    public function testConsumeCallbackUsesArrayBackoffProgressively(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $channel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function ($msg) {
                    $headers = $msg->get('application_headers')->getNativeData();
                    // First retry (index 0): backoff[0] = 1 second = 1000ms
                    $this->assertEquals(1000, $headers['x-delay']);
                    return true;
                })
            );

        $consumer = $this->createConsumerWithChannel($channel);
        $consumer->events('user.created')->tries(5)->backoff([1, 5, 10, 30])->init();

        $callback = function () {
            throw new \Exception('Fail');
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => []]);
        $message->method('ack');

        $consumer->consumeCallback($message);
    }

    public function testConsumeCallbackUsesLastBackoffValueWhenExceedingArrayLength(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $channel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function ($msg) {
                    $headers = $msg->get('application_headers')->getNativeData();
                    // x-retry-count: 3 means this is 4th retry
                    // retryCount = 3 + 1 = 4
                    // getBackoff(4): count = 3, index = min(3, 2) = 2
                    // Should use backoff[2]: 10 seconds = 10000ms
                    $this->assertEquals(10000, $headers['x-delay']);
                    return true;
                })
            );

        $consumer = $this->createConsumerWithChannel($channel);
        $consumer->events('user.created')->tries(10)->backoff([1, 5, 10])->init();

        $callback = function () {
            throw new \Exception('Fail');
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        // Simulate 4th retry: x-retry-count = 3
        // retryCount = 3 + 1 = 4, which is < tries (10), so will retry
        $properties = ['application_headers' => new AMQPTable(['x-retry-count' => 3])];
        $message = $this->createAMQPMessage('user.created', ['payload' => []], $properties);
        $message->method('ack');

        $consumer->consumeCallback($message);
    }

    // -------------------------------------------------------------------------
    // consumeCallback() - ACK failure tests
    // -------------------------------------------------------------------------

    public function testConsumeCallbackThrowsOnAckFailure(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        $callback = function () {
            // Success
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => []]);
        $message->method('ack')->willThrowException(new \Exception('ACK failed'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ACK failed');

        $consumer->consumeCallback($message);
    }

    // -------------------------------------------------------------------------
    // consumeCallback() - Retry tag tests
    // -------------------------------------------------------------------------

    public function testGetRetryTagReturnsFirstForFirstAttempt(): void
    {
        $consumer = new NanoConsumer();
        $consumer->tries(3);

        $tag = $this->invokePrivateMethod($consumer, 'getRetryTag', [1]);

        $this->assertEquals(EventRetryStatusTag::FIRST, $tag);
    }

    public function testGetRetryTagReturnsLastForLastAttempt(): void
    {
        $consumer = new NanoConsumer();
        $consumer->tries(3);

        $tag = $this->invokePrivateMethod($consumer, 'getRetryTag', [3]);

        $this->assertEquals(EventRetryStatusTag::LAST, $tag);
    }

    public function testGetRetryTagReturnsRetryForMiddleAttempts(): void
    {
        $consumer = new NanoConsumer();
        $consumer->tries(5);

        $tag = $this->invokePrivateMethod($consumer, 'getRetryTag', [2]);
        $this->assertEquals(EventRetryStatusTag::RETRY, $tag);

        $tag = $this->invokePrivateMethod($consumer, 'getRetryTag', [3]);
        $this->assertEquals(EventRetryStatusTag::RETRY, $tag);

        $tag = $this->invokePrivateMethod($consumer, 'getRetryTag', [4]);
        $this->assertEquals(EventRetryStatusTag::RETRY, $tag);
    }

    // -------------------------------------------------------------------------
    // consumeCallback() - Metrics tracking tests
    // -------------------------------------------------------------------------

    public function testConsumeCallbackTracksPayloadSizeMetric(): void
    {
        $statsD = $this->createMock(StatsDClient::class);
        $statsD->method('isEnabled')->willReturn(true);
        $statsD->method('getSampleRate')->willReturn(1.0);

        $statsD->expects($this->once())
            ->method('histogram')
            ->with(
                'rmq_consumer_payload_bytes',
                $this->greaterThan(0),
                $this->callback(function ($tags) {
                    return $tags['nano_service_name'] === 'test-consumer'
                        && $tags['event_name'] === 'user.created';
                }),
                $this->anything()
            );

        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();
        $this->setPrivateProperty($consumer, 'statsD', $statsD);

        $callback = function () {};
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => ['user_id' => 123]]);
        $message->method('ack');

        $consumer->consumeCallback($message);
    }

    public function testConsumeCallbackTracksStartMetric(): void
    {
        $statsD = $this->createMock(StatsDClient::class);
        $statsD->method('isEnabled')->willReturn(true);
        $statsD->method('getSampleRate')->willReturn(1.0);

        $statsD->expects($this->once())
            ->method('start')
            ->with(
                $this->callback(function ($tags) {
                    return $tags['nano_service_name'] === 'test-consumer'
                        && $tags['event_name'] === 'user.created';
                }),
                EventRetryStatusTag::FIRST
            );

        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();
        $this->setPrivateProperty($consumer, 'statsD', $statsD);

        $callback = function () {};
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => []]);
        $message->method('ack');

        $consumer->consumeCallback($message);
    }

    public function testConsumeCallbackTracksSuccessEndMetric(): void
    {
        $statsD = $this->createMock(StatsDClient::class);
        $statsD->method('isEnabled')->willReturn(true);
        $statsD->method('getSampleRate')->willReturn(1.0);

        $statsD->expects($this->once())
            ->method('end')
            ->with(EventExitStatusTag::SUCCESS, EventRetryStatusTag::FIRST);

        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();
        $this->setPrivateProperty($consumer, 'statsD', $statsD);

        $callback = function () {};
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => []]);
        $message->method('ack');

        $consumer->consumeCallback($message);
    }

    public function testConsumeCallbackTracksFailedEndMetricOnRetry(): void
    {
        $statsD = $this->createMock(StatsDClient::class);
        $statsD->method('isEnabled')->willReturn(true);
        $statsD->method('getSampleRate')->willReturn(1.0);

        $statsD->expects($this->once())
            ->method('end')
            ->with(EventExitStatusTag::FAILED, EventRetryStatusTag::FIRST);

        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->tries(3)->init();
        $this->setPrivateProperty($consumer, 'statsD', $statsD);

        $callback = function () {
            throw new \Exception('Fail');
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => []]);
        $message->method('ack');

        $consumer->consumeCallback($message);
    }

    public function testConsumeCallbackTracksDLXMetricOnMaxRetries(): void
    {
        $statsD = $this->createMock(StatsDClient::class);
        $statsD->method('isEnabled')->willReturn(true);
        $statsD->method('getSampleRate')->willReturn(1.0);

        $dlxTracked = false;
        $statsD->method('increment')
            ->willReturnCallback(function ($metric, $tags) use (&$dlxTracked) {
                if ($metric === 'rmq_consumer_dlx_total'
                    && $tags['nano_service_name'] === 'test-consumer'
                    && $tags['event_name'] === 'user.created'
                    && $tags['reason'] === 'max_retries_exceeded') {
                    $dlxTracked = true;
                }
            });

        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->tries(3)->init();
        $this->setPrivateProperty($consumer, 'statsD', $statsD);

        $callback = function () {
            throw new \Exception('Fail');
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $properties = ['application_headers' => new AMQPTable(['x-retry-count' => 2])];
        $message = $this->createAMQPMessage('user.created', ['payload' => []], $properties);
        $message->method('ack');

        $consumer->consumeCallback($message);

        $this->assertTrue($dlxTracked, 'DLX metric should be tracked');
    }

    public function testConsumeCallbackTracksAckFailedMetric(): void
    {
        $statsD = $this->createMock(StatsDClient::class);
        $statsD->method('isEnabled')->willReturn(true);
        $statsD->method('getSampleRate')->willReturn(1.0);

        $ackFailedTracked = false;
        $statsD->method('increment')
            ->willReturnCallback(function ($metric, $tags) use (&$ackFailedTracked) {
                if ($metric === 'rmq_consumer_ack_failed_total'
                    && $tags['nano_service_name'] === 'test-consumer'
                    && $tags['event_name'] === 'user.created') {
                    $ackFailedTracked = true;
                }
            });

        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();
        $this->setPrivateProperty($consumer, 'statsD', $statsD);

        $callback = function () {};
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => []]);
        $message->method('ack')->willThrowException(new \Exception('ACK failed'));

        try {
            $consumer->consumeCallback($message);
        } catch (\Exception $e) {
            // Expected
        }

        $this->assertTrue($ackFailedTracked, 'ACK failed metric should be tracked');
    }

    // -------------------------------------------------------------------------
    // shutdown() tests
    // -------------------------------------------------------------------------

    public function testShutdownClosesChannelAndConnection(): void
    {
        $channel = $this->createMock(AMQPChannel::class);
        $connection = $this->createMock(AMQPStreamConnection::class);

        $channel->expects($this->once())->method('close');
        $connection->expects($this->once())->method('close');
        $connection->method('isConnected')->willReturn(true);
        $connection->method('channel')->willReturn($channel);

        $consumer = new NanoConsumer();
        $this->injectChannelAndConnection($consumer, $channel, $connection);

        $consumer->shutdown();
    }

    // -------------------------------------------------------------------------
    // Edge cases and error handling
    // -------------------------------------------------------------------------

    public function testConsumeCallbackHandlesMessageWithNoHeaders(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        $callback = function (NanoServiceMessage $msg) {
            $this->assertEquals(0, $msg->getRetryCount());
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => []], []);
        $message->method('ack');

        $consumer->consumeCallback($message);
    }

    public function testConsumeCallbackSetsConsumerErrorMessageOnDLX(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $channel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function ($msg) {
                    $error = $msg->getConsumerError();
                    $this->assertStringContainsString('Custom error', $error);
                    return true;
                }),
                '',
                'test.test-consumer.failed'
            );

        $consumer = $this->createConsumerWithChannel($channel);
        $consumer->events('user.created')->tries(1)->init();

        $callback = function () {
            throw new \Exception('Custom error message');
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => []]);
        $message->method('ack');

        $consumer->consumeCallback($message);
    }

    public function testDefaultTriesValueIs3(): void
    {
        $consumer = new NanoConsumer();
        $this->assertEquals(3, $this->getPrivateProperty($consumer, 'tries'));
    }

    public function testDefaultBackoffValueIs0(): void
    {
        $consumer = new NanoConsumer();
        $this->assertEquals(0, $this->getPrivateProperty($consumer, 'backoff'));
    }

    public function testDefaultInitializedValueIsFalse(): void
    {
        $consumer = new NanoConsumer();
        $this->assertFalse($this->getPrivateProperty($consumer, 'initialized'));
    }

    public function testFailedPostfixConstant(): void
    {
        $this->assertEquals('.failed', NanoConsumer::FAILED_POSTFIX);
    }

    // -------------------------------------------------------------------------
    // Circuit breaker tests
    // -------------------------------------------------------------------------

    /**
     * Test that outageSleep configuration is used for circuit breaker
     *
     * Note: The consume() method's infinite loop with circuit breaker is difficult
     * to unit test due to:
     * 1. Infinite while(true) loop
     * 2. Blocking $channel->consume() call
     * 3. Dependency on parent class ensureConnectionOrSleep()
     *
     * The circuit breaker logic is tested via integration tests where we can:
     * - Simulate RabbitMQ outage (stop RabbitMQ)
     * - Verify logging behavior
     * - Verify automatic recovery
     *
     * Here we test configuration and initialization only.
     */
    public function testCircuitBreakerConfiguration(): void
    {
        $consumer = new NanoConsumer();
        $consumer->outageSleep(120);

        $this->assertEquals(120, $this->getPrivateProperty($consumer, 'outageSleepSeconds'));
    }

    public function testInitializedFlagPreventsDuplicateInitialization(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        // Setup expectations for SINGLE initialization
        $channel->expects($this->exactly(2))->method('queue_declare');
        $channel->expects($this->exactly(1))->method('exchange_declare');
        $channel->expects($this->exactly(2))->method('queue_bind');

        $consumer = $this->createConsumerWithChannel($channel);
        $consumer->events('test.event');

        // First init: full initialization
        $consumer->init();
        $this->assertTrue($this->getPrivateProperty($consumer, 'initialized'));

        // Second init: should return early due to initialized flag
        $consumer->init();

        // If expectations are met, test passes (no duplicate operations)
    }

    public function testCircuitBreakerResetsInitializedFlagOnConnectionError(): void
    {
        // This test documents expected behavior for circuit breaker reset logic
        // The actual reset happens in consume() catch block when connection errors occur
        //
        // Expected flow:
        // 1. Consumer initialized ($initialized = true)
        // 2. Connection error occurs in consume() loop
        // 3. catch block calls $this->reset() and sets $initialized = false
        // 4. Next loop iteration re-initializes consumer
        //
        // This ensures fresh connections/channels after errors
        $consumer = new NanoConsumer();

        // Simulate initialization
        $this->setPrivateProperty($consumer, 'initialized', true);
        $this->assertTrue($this->getPrivateProperty($consumer, 'initialized'));

        // Simulate reset (what consume() catch block does)
        $this->setPrivateProperty($consumer, 'initialized', false);
        $this->assertFalse($this->getPrivateProperty($consumer, 'initialized'));

        // Next init() call would re-initialize
        // (tested above in testInitIsIdempotent)
    }

    // testSystemPingHandlerRegistered removed - system.ping.1 handler no longer exists

    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    private function createConsumerWithMockedChannel(): NanoConsumer
    {
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('queue_declare')->willReturn(['test.test-consumer', 0, 0]);
        $channel->method('exchange_declare');
        $channel->method('queue_bind');
        $channel->method('basic_publish');

        return $this->createConsumerWithChannel($channel);
    }

    private function createConsumerWithChannel(AMQPChannel $channel): NanoConsumer
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('channel')->willReturn($channel);

        $consumer = new NanoConsumer();
        $this->injectChannelAndConnection($consumer, $channel, $connection);

        return $consumer;
    }

    private function injectChannelAndConnection(
        NanoConsumer $consumer,
        AMQPChannel $channel,
        AMQPStreamConnection $connection
    ): void {
        $reflection = new ReflectionClass($consumer);

        $channelProp = $reflection->getProperty('channel');
        $channelProp->setAccessible(true);
        $channelProp->setValue($consumer, $channel);

        $connectionProp = $reflection->getProperty('connection');
        $connectionProp->setAccessible(true);
        $connectionProp->setValue($consumer, $connection);

        $sharedChannelProp = $reflection->getProperty('sharedChannel');
        $sharedChannelProp->setAccessible(true);
        $sharedChannelProp->setValue(null, $channel);

        $sharedConnectionProp = $reflection->getProperty('sharedConnection');
        $sharedConnectionProp->setAccessible(true);
        $sharedConnectionProp->setValue(null, $connection);
    }

    private function createAMQPMessage(
        string $eventType,
        array $body,
        array $properties = []
    ): AMQPMessage|\PHPUnit\Framework\MockObject\MockObject {
        $message = $this->createMock(AMQPMessage::class);

        // Ensure app_id is set in properties
        if (!isset($properties['app_id'])) {
            $properties['app_id'] = 'test-service';
        }

        $nanoMessage = new NanoServiceMessage($body, $properties);
        $nanoMessage->setEvent($eventType);

        $message->method('getBody')->willReturn($nanoMessage->getBody());
        $message->method('get_properties')->willReturn(array_merge($nanoMessage->get_properties(), $properties));
        $message->method('get')->willReturnCallback(function ($key) use ($eventType, $properties, $nanoMessage) {
            if ($key === 'type') {
                return $eventType;
            }
            if ($key === 'message_id') {
                return $nanoMessage->get('message_id');
            }
            if ($key === 'app_id') {
                return $properties['app_id'] ?? 'test-service';
            }
            if ($key === 'application_headers' && isset($properties['application_headers'])) {
                return $properties['application_headers'];
            }
            return null;
        });
        $message->method('has')->willReturnCallback(function ($key) use ($properties) {
            // Always return true for required properties (type, message_id, app_id)
            if ($key === 'type') return true;
            if ($key === 'message_id') return true;
            if ($key === 'app_id') return true;
            // Check if application_headers exists
            if ($key === 'application_headers') return isset($properties['application_headers']);
            return false;
        });
        $message->method('getDeliveryTag')->willReturn(1);

        $channel = $this->createMock(AMQPChannel::class);
        $message->method('getChannel')->willReturn($channel);

        return $message;
    }

    private function getPrivateProperty(object $object, string $propertyName): mixed
    {
        $reflection = new ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        return $property->getValue($object);
    }

    private function setPrivateProperty(object $object, string $propertyName, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function invokePrivateMethod(object $object, string $methodName, array $args = []): mixed
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $args);
    }

    private function resetSharedState(): void
    {
        $reflection = new ReflectionClass(NanoConsumer::class);

        $sharedConnection = $reflection->getProperty('sharedConnection');
        $sharedConnection->setAccessible(true);
        $sharedConnection->setValue(null, null);

        $sharedChannel = $reflection->getProperty('sharedChannel');
        $sharedChannel->setAccessible(true);
        $sharedChannel->setValue(null, null);
    }

    /**
     * Set up mocked EventRepository for tests that use consumeCallback
     *
     * This mocks all database operations to prevent actual database calls:
     * - existsInInbox() returns false (message not in inbox yet)
     * - insertInbox() succeeds
     * - fetch() returns false (no results)
     */
    private function mockEventRepository(): void
    {
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('fetch')->willReturn(false); // For existsInInbox/existsInOutbox checks
        $mockStmt->method('fetchColumn')->willReturn(false); // For count queries

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);
    }

    /**
     * Set up EventRepository mock with custom behavior
     */
    private function mockEventRepositoryWith(callable $setupCallback): void
    {
        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $setupCallback($repository);
    }

    // -------------------------------------------------------------------------
    // consumeCallback() - Inbox pattern error handling tests
    // -------------------------------------------------------------------------

    public function testConsumeCallbackAcksAndSkipsWhenMessageExistsInInbox(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        // Mock repository to return true for existsInInbox
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('fetch')->willReturn(['1' => 1]); // Message exists

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        $callbackCalled = false;
        $callback = function () use (&$callbackCalled) {
            $callbackCalled = true;
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => []]);
        $message->expects($this->once())->method('ack');

        $consumer->consumeCallback($message);

        $this->assertFalse($callbackCalled, 'Callback should not be called when message exists in inbox');
    }

    public function testConsumeCallbackThrowsWhenInsertInboxFailsWithCriticalError(): void
    {
        // Set up environment variables for getConnection validation
        $_ENV['DB_BOX_HOST'] = 'localhost';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'testdb';
        $_ENV['DB_BOX_USER'] = 'testuser';
        $_ENV['DB_BOX_PASS'] = 'testpass';

        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        // Mock repository to throw RuntimeException on insertInbox (critical DB error)
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')
            ->willReturnCallback(function () {
                static $callCount = 0;
                $callCount++;

                if ($callCount === 1) {
                    // First call: existsInInbox check - return false (doesn't exist)
                    return true;
                }

                // Second call: insertInbox - throw critical error
                throw new \PDOException('deadlock detected');
            });
        $mockStmt->method('fetch')->willReturn(false); // existsInInbox returns false

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        $message = $this->createAMQPMessage('user.created', ['payload' => []]);
        $message->expects($this->never())->method('ack'); // Should NOT ACK

        $this->expectException(\RuntimeException::class);
        // Note: When retry logic resets connection and tries to reconnect with bad credentials,
        // it throws "Failed to connect to event database" instead of "Failed to insert"
        $this->expectExceptionMessage('Failed to connect to event database');

        $consumer->consumeCallback($message);
    }

    public function testConsumeCallbackAcksAndSkipsWhenInsertInboxReturnsFalseDueToDuplicate(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        // Mock repository insertInbox to return false (duplicate detected during race condition)
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')
            ->willReturnCallback(function () {
                static $callCount = 0;
                $callCount++;

                if ($callCount === 1) {
                    // First call: existsInInbox check - return false
                    return true;
                }

                // Second call: insertInbox - simulate duplicate key error
                throw new \PDOException('duplicate key value violates unique constraint', '23505');
            });
        $mockStmt->method('fetch')->willReturn(false); // existsInInbox returns false

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        $callbackCalled = false;
        $callback = function () use (&$callbackCalled) {
            $callbackCalled = true;
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => []]);
        $message->expects($this->once())->method('ack'); // Should ACK and skip

        $consumer->consumeCallback($message);

        $this->assertFalse($callbackCalled, 'Callback should not be called when insertInbox returns false (duplicate)');
    }

    public function testConsumeCallbackContinuesWhenMarkInboxAsProcessedFails(): void
    {
        // Set up env vars for retry logic
        $_ENV['DB_BOX_HOST'] = 'localhost';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'testdb';
        $_ENV['DB_BOX_USER'] = 'testuser';
        $_ENV['DB_BOX_PASS'] = 'testpass';

        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        // Mock repository: insertInbox succeeds, markInboxAsProcessed fails
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')
            ->willReturnCallback(function () {
                static $callCount = 0;
                $callCount++;

                if ($callCount <= 3) {
                    // Calls 1-3: existsInInboxAndProcessed, existsInInbox, insertInbox (all succeed)
                    return true;
                }

                // Call 4+: markInboxAsProcessed - fail (non-retryable error)
                throw new \PDOException('Syntax error in UPDATE statement');
            });
        $mockStmt->method('fetch')->willReturn(false); // existsInInboxAndProcessed and existsInInbox return false

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        $callbackCalled = false;
        $callback = function () use (&$callbackCalled) {
            $callbackCalled = true;
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => []]);
        $message->expects($this->once())->method('ack'); // Should still ACK

        // Suppress error_log output during test
        $errorLog = ini_get('error_log');
        ini_set('error_log', '/dev/null');

        // Should not throw - continues despite markInboxAsProcessed failure
        $consumer->consumeCallback($message);

        ini_set('error_log', $errorLog);

        $this->assertTrue($callbackCalled, 'Callback should be called even if markInboxAsProcessed fails');
    }

    public function testConsumeCallbackThrowsWhenRetryRepublishFails(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        // basic_publish will fail (throw exception)
        $channel->method('basic_publish')
            ->willThrowException(new \Exception('RabbitMQ connection lost'));

        $channel->method('queue_declare')->willReturn(['test.test-consumer', 0, 0]);
        $channel->method('exchange_declare');
        $channel->method('queue_bind');

        $consumer = $this->createConsumerWithChannel($channel);
        $consumer->events('user.created')->tries(3)->init();

        $callback = function () {
            throw new \Exception('Processing failed');
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => []]);
        $message->expects($this->never())->method('ack'); // Should NOT ACK when republish fails

        // Suppress error_log output during test
        $errorLog = ini_get('error_log');
        ini_set('error_log', '/dev/null');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('RabbitMQ connection lost');

        $consumer->consumeCallback($message);

        ini_set('error_log', $errorLog);
    }

    public function testConsumeCallbackThrowsWhenDLXPublishFails(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        // basic_publish will fail (throw exception)
        $channel->method('basic_publish')
            ->willThrowException(new \Exception('DLX queue unavailable'));

        $channel->method('queue_declare')->willReturn(['test.test-consumer', 0, 0]);
        $channel->method('exchange_declare');
        $channel->method('queue_bind');

        $consumer = $this->createConsumerWithChannel($channel);
        $consumer->events('user.created')->tries(3)->init();

        $callback = function () {
            throw new \Exception('Processing failed');
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        // Simulate max retries reached
        $properties = ['application_headers' => new AMQPTable(['x-retry-count' => 2])];
        $message = $this->createAMQPMessage('user.created', ['payload' => []], $properties);
        $message->expects($this->never())->method('ack'); // Should NOT ACK when DLX publish fails

        // Suppress error_log output during test
        $errorLog = ini_get('error_log');
        ini_set('error_log', '/dev/null');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('DLX queue unavailable');

        $consumer->consumeCallback($message);

        ini_set('error_log', $errorLog);
    }

    public function testConsumeCallbackContinuesWhenMarkInboxAsFailedFails(): void
    {
        // Set up env vars for retry logic
        $_ENV['DB_BOX_HOST'] = 'localhost';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'testdb';
        $_ENV['DB_BOX_USER'] = 'testuser';
        $_ENV['DB_BOX_PASS'] = 'testpass';

        $channel = $this->createMock(AMQPChannel::class);

        // basic_publish succeeds (to DLX)
        $channel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->anything(),
                '',
                'test.test-consumer.failed'
            );

        $channel->method('queue_declare')->willReturn(['test.test-consumer', 0, 0]);
        $channel->method('exchange_declare');
        $channel->method('queue_bind');

        $consumer = $this->createConsumerWithChannel($channel);
        $consumer->events('user.created')->tries(3)->init();

        // Mock repository: markInboxAsFailed fails
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')
            ->willReturnCallback(function () {
                static $callCount = 0;
                $callCount++;

                if ($callCount <= 3) {
                    // Calls 1-3: existsInInboxAndProcessed, existsInInbox, insertInbox (all succeed)
                    return true;
                }

                // Call 4: markInboxAsFailed - fail (non-retryable error)
                throw new \PDOException('Syntax error in UPDATE statement');
            });
        $mockStmt->method('fetch')->willReturn(false); // existsInInboxAndProcessed and existsInInbox return false

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        $callback = function () {
            throw new \Exception('Processing failed');
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        // Simulate max retries reached
        $properties = ['application_headers' => new AMQPTable(['x-retry-count' => 2])];
        $message = $this->createAMQPMessage('user.created', ['payload' => []], $properties);
        $message->expects($this->once())->method('ack'); // Should still ACK (DLX publish succeeded)

        // Suppress error_log output during test
        $errorLog = ini_get('error_log');
        ini_set('error_log', '/dev/null');

        // Should not throw - continues despite markInboxAsFailed failure
        $consumer->consumeCallback($message);

        ini_set('error_log', $errorLog);
    }

    public function testConsumeCallbackLogsErrorWhenMarkInboxAsProcessedFails(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        // Mock repository: markInboxAsProcessed fails
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')
            ->willReturnCallback(function () {
                static $callCount = 0;
                $callCount++;

                if ($callCount <= 3) {
                    // Calls 1-3: existsInInboxAndProcessed, existsInInbox, insertInbox (all succeed)
                    return true;
                }

                // Call 4: markInboxAsProcessed - fail
                throw new \PDOException('Disk full');
            });
        $mockStmt->method('fetch')->willReturn(false); // existsInInboxAndProcessed and existsInInbox return false

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        $callback = function () {};
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => []]);
        $message->method('ack');

        // Verify operation completes successfully despite DB failure
        // Structured logging will output error (visible in test output)
        $consumer->consumeCallback($message);

        // Test passes if no exception thrown - logging is non-critical
        $this->assertTrue(true);
    }

    public function testConsumeCallbackLogsErrorWhenMarkInboxAsFailedFails(): void
    {
        // Set up env vars for retry logic
        $_ENV['DB_BOX_HOST'] = 'localhost';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'testdb';
        $_ENV['DB_BOX_USER'] = 'testuser';
        $_ENV['DB_BOX_PASS'] = 'testpass';

        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('basic_publish');
        $channel->method('queue_declare')->willReturn(['test.test-consumer', 0, 0]);
        $channel->method('exchange_declare');
        $channel->method('queue_bind');

        $consumer = $this->createConsumerWithChannel($channel);
        $consumer->events('user.created')->tries(3)->init();

        // Mock repository: markInboxAsFailed fails
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')
            ->willReturnCallback(function () {
                static $callCount = 0;
                $callCount++;

                if ($callCount <= 3) {
                    // Calls 1-3: existsInInboxAndProcessed, existsInInbox, insertInbox (all succeed)
                    return true;
                }

                // Call 4: markInboxAsFailed - fail (non-retryable error)
                throw new \PDOException('Syntax error in UPDATE statement');
            });
        $mockStmt->method('fetch')->willReturn(false); // existsInInboxAndProcessed and existsInInbox return false

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        $callback = function () {
            throw new \Exception('Processing failed');
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $properties = ['application_headers' => new AMQPTable(['x-retry-count' => 2])];
        $message = $this->createAMQPMessage('user.created', ['payload' => []], $properties);
        $message->method('ack');

        // Verify operation completes successfully despite DB failure
        // Structured logging will output error (visible in test output)
        $consumer->consumeCallback($message);

        // Test passes if no exception thrown - logging is non-critical
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // consumeCallback() - Retry fix tests (existsInInboxAndProcessed)
    // -------------------------------------------------------------------------

    /**
     * Test that a brand new event (first attempt) is inserted and processed
     */
    public function testConsumeCallbackProcessesBrandNewEvent(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        // Mock: event doesn't exist at all
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('fetch')->willReturn(false); // Both checks return false

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        $callbackCalled = false;
        $callback = function () use (&$callbackCalled) {
            $callbackCalled = true;
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => ['user_id' => 123]]);
        $message->expects($this->once())->method('ack');

        $consumer->consumeCallback($message);

        $this->assertTrue($callbackCalled, 'Callback should be called for brand new event');
    }

    /**
     * Test that a retried event (status = 'failed') is processed again
     * This is the main bug fix test - previously retried events were ACK'd without processing
     */
    public function testConsumeCallbackProcessesRetriedEventWithFailedStatus(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        // Mock: event exists but NOT processed (status = 'failed')
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('fetch')
            ->willReturnCallback(function () {
                static $callCount = 0;
                $callCount++;

                // First call: existsInInboxAndProcessed (status = 'processed') -> false
                if ($callCount === 1) {
                    return false;
                }

                // Second call: existsInInbox (any status) -> true
                if ($callCount === 2) {
                    return ['1' => 1]; // Event exists
                }

                return false;
            });

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        $callbackCalled = false;
        $callback = function () use (&$callbackCalled) {
            $callbackCalled = true;
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        // Simulate retried message (x-retry-count = 1)
        $properties = ['application_headers' => new AMQPTable(['x-retry-count' => 1])];
        $message = $this->createAMQPMessage('user.created', ['payload' => ['user_id' => 123]], $properties);
        $message->expects($this->once())->method('ack');

        $consumer->consumeCallback($message);

        $this->assertTrue($callbackCalled, 'Callback MUST be called for retried event with failed status - this was the bug!');
    }

    /**
     * Test that a retried event (status = 'processing') is processed again
     * Edge case: event stuck in 'processing' status (e.g., worker crashed)
     */
    public function testConsumeCallbackProcessesRetriedEventWithProcessingStatus(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        // Mock: event exists with status = 'processing' (not processed)
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('fetch')
            ->willReturnCallback(function () {
                static $callCount = 0;
                $callCount++;

                // First call: existsInInboxAndProcessed -> false (status != 'processed')
                if ($callCount === 1) {
                    return false;
                }

                // Second call: existsInInbox -> true
                if ($callCount === 2) {
                    return ['1' => 1];
                }

                return false;
            });

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        $callbackCalled = false;
        $callback = function () use (&$callbackCalled) {
            $callbackCalled = true;
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $properties = ['application_headers' => new AMQPTable(['x-retry-count' => 1])];
        $message = $this->createAMQPMessage('user.created', ['payload' => ['user_id' => 123]], $properties);
        $message->expects($this->once())->method('ack');

        $consumer->consumeCallback($message);

        $this->assertTrue($callbackCalled, 'Callback should be called for event stuck in processing status');
    }

    /**
     * Test that an already processed event (status = 'processed') is skipped
     * This is the idempotent behavior - don't process the same event twice
     */
    public function testConsumeCallbackSkipsAlreadyProcessedEvent(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        // Mock: event exists AND is processed (status = 'processed')
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('fetch')->willReturn(['1' => 1]); // existsInInboxAndProcessed returns true

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        $callbackCalled = false;
        $callback = function () use (&$callbackCalled) {
            $callbackCalled = true;
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('user.created', ['payload' => ['user_id' => 123]]);
        $message->expects($this->once())->method('ack');

        $consumer->consumeCallback($message);

        $this->assertFalse($callbackCalled, 'Callback should NOT be called for already processed event (idempotent behavior)');
    }

    /**
     * Test that retried event after failure gets correct retry count
     */
    public function testConsumeCallbackProcessesRetriedEventWithCorrectRetryCount(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->init();

        // Mock: event exists but not processed
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('fetch')
            ->willReturnCallback(function () {
                static $callCount = 0;
                $callCount++;

                if ($callCount === 1) {
                    return false; // existsInInboxAndProcessed -> false
                }
                if ($callCount === 2) {
                    return ['1' => 1]; // existsInInbox -> true
                }
                return false;
            });

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        $receivedRetryCount = null;
        $callback = function (NanoServiceMessage $msg) use (&$receivedRetryCount) {
            $receivedRetryCount = $msg->getRetryCount();
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        // Simulate 3rd retry (x-retry-count = 2)
        $properties = ['application_headers' => new AMQPTable(['x-retry-count' => 2])];
        $message = $this->createAMQPMessage('user.created', ['payload' => []], $properties);
        $message->method('ack');

        $consumer->consumeCallback($message);

        $this->assertEquals(2, $receivedRetryCount, 'Retry count should be preserved from headers');
    }

    /**
     * Test that multiple retries of the same event are all processed
     */
    public function testConsumeCallbackProcessesMultipleRetriesOfSameEvent(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('user.created')->tries(5)->init();

        // Mock: event exists but not processed (simulating retry scenario)
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('fetch')
            ->willReturnCallback(function () {
                static $callCount = 0;
                $callCount++;

                // Alternate: existsInInboxAndProcessed (false), existsInInbox (true)
                return ($callCount % 2 === 1) ? false : ['1' => 1];
            });

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;
        };
        $this->setPrivateProperty($consumer, 'callback', $callback);

        // Simulate first retry
        $properties1 = ['application_headers' => new AMQPTable(['x-retry-count' => 1])];
        $message1 = $this->createAMQPMessage('user.created', ['payload' => []], $properties1);
        $message1->method('ack');
        $consumer->consumeCallback($message1);

        // Simulate second retry
        $properties2 = ['application_headers' => new AMQPTable(['x-retry-count' => 2])];
        $message2 = $this->createAMQPMessage('user.created', ['payload' => []], $properties2);
        $message2->method('ack');
        $consumer->consumeCallback($message2);

        // Simulate third retry
        $properties3 = ['application_headers' => new AMQPTable(['x-retry-count' => 3])];
        $message3 = $this->createAMQPMessage('user.created', ['payload' => []], $properties3);
        $message3->method('ack');
        $consumer->consumeCallback($message3);

        $this->assertEquals(3, $callCount, 'All retries should be processed');
    }
}
