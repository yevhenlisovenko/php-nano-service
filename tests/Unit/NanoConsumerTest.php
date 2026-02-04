<?php

namespace AlexFN\NanoService\Tests\Unit;

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

        // Expect queue binds: 1 event + 1 system handler + 1 for '#' = 3 total
        $channel->expects($this->exactly(3))
            ->method('queue_bind');

        $consumer = $this->createConsumerWithChannel($channel);
        $consumer->events('test.event')->init();
    }

    public function testInitBindsEventsToQueue(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        // Should bind user events + system.ping.1 + '#'
        $channel->expects($this->exactly(4))
            ->method('queue_bind')
            ->willReturnCallback(function ($queue, $exchange, $routingKey) {
                $this->assertContains($routingKey, ['test.event', 'another.event', 'system.ping.1', '#']);
            });

        $consumer = $this->createConsumerWithChannel($channel);
        $consumer->events('test.event', 'another.event')->init();
    }

    public function testInitBindsSystemHandlers(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $boundSystemEvents = [];
        $channel->method('queue_bind')
            ->willReturnCallback(function ($queue, $exchange, $routingKey) use (&$boundSystemEvents) {
                if (str_starts_with($routingKey, 'system.')) {
                    $boundSystemEvents[] = $routingKey;
                }
            });

        $consumer = $this->createConsumerWithChannel($channel);
        $consumer->events('test.event')->init();

        $this->assertContains('system.ping.1', $boundSystemEvents);
    }

    // -------------------------------------------------------------------------
    // consumeCallback() - System handler tests
    // -------------------------------------------------------------------------

    public function testConsumeCallbackInvokesSystemHandler(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('test.event')->init();

        $message = $this->createAMQPMessage('system.ping.1', ['payload' => []]);

        // System handler should be invoked and message ACKed
        $message->expects($this->once())->method('ack');

        $consumer->consumeCallback($message);
    }

    public function testConsumeCallbackSystemHandlerDoesNotCallUserCallback(): void
    {
        $consumer = $this->createConsumerWithMockedChannel();
        $consumer->events('test.event')->init();

        $userCallbackCalled = false;
        $callback = function () use (&$userCallbackCalled) {
            $userCallbackCalled = true;
        };

        $this->setPrivateProperty($consumer, 'callback', $callback);

        $message = $this->createAMQPMessage('system.ping.1', ['payload' => []]);
        $message->method('ack');

        $consumer->consumeCallback($message);

        $this->assertFalse($userCallbackCalled, 'User callback should not be called for system events');
    }

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

    public function testFailedPostfixConstant(): void
    {
        $this->assertEquals('.failed', NanoConsumer::FAILED_POSTFIX);
    }

    public function testSystemPingHandlerRegistered(): void
    {
        $consumer = new NanoConsumer();
        $handlers = $this->getPrivateProperty($consumer, 'handlers');

        $this->assertArrayHasKey('system.ping.1', $handlers);
        $this->assertEquals(SystemPing::class, $handlers['system.ping.1']);
    }

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

        $nanoMessage = new NanoServiceMessage($body, $properties);
        $nanoMessage->setEvent($eventType);

        $message->method('getBody')->willReturn($nanoMessage->getBody());
        $message->method('get_properties')->willReturn(array_merge($nanoMessage->get_properties(), $properties));
        $message->method('get')->willReturnCallback(function ($key) use ($eventType, $properties) {
            if ($key === 'type') {
                return $eventType;
            }
            if ($key === 'application_headers' && isset($properties['application_headers'])) {
                return $properties['application_headers'];
            }
            return null;
        });
        $message->method('has')->willReturnCallback(function ($key) use ($properties) {
            return $key === 'application_headers' && isset($properties['application_headers']);
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
}
