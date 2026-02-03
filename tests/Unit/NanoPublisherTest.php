<?php

namespace AlexFN\NanoService\Tests\Unit;

use AlexFN\NanoService\Enums\PublishErrorType;
use AlexFN\NanoService\NanoPublisher;
use AlexFN\NanoService\NanoServiceMessage;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NanoPublisher infrastructure resilience
 *
 * Tests publish error handling, exception categorization, and
 * behavior during infrastructure failures (RabbitMQ down, channel closed, timeouts).
 */
class NanoPublisherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['STATSD_ENABLED'] = 'false';
        $_ENV['AMQP_HOST'] = 'localhost';
        $_ENV['AMQP_PORT'] = '5672';
        $_ENV['AMQP_USER'] = 'guest';
        $_ENV['AMQP_PASS'] = 'guest';
        $_ENV['AMQP_VHOST'] = '/';
        $_ENV['AMQP_PROJECT'] = 'test';
        $_ENV['AMQP_MICROSERVICE_NAME'] = 'test-publisher';
        $_ENV['AMQP_PUBLISHER_ENABLED'] = '1';
        $_ENV['APP_ENV'] = 'test';
        // Required for outbox pattern
        $_ENV['DB_BOX_HOST'] = 'localhost';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'test_db';
        $_ENV['DB_BOX_USER'] = 'test_user';
        $_ENV['DB_BOX_PASS'] = 'test_pass';
        $_ENV['DB_BOX_SCHEMA'] = 'public';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $vars = [
            'STATSD_ENABLED', 'AMQP_HOST', 'AMQP_PORT', 'AMQP_USER',
            'AMQP_PASS', 'AMQP_VHOST', 'AMQP_PROJECT', 'AMQP_MICROSERVICE_NAME',
            'AMQP_PUBLISHER_ENABLED', 'APP_ENV',
            'DB_BOX_HOST', 'DB_BOX_PORT', 'DB_BOX_NAME', 'DB_BOX_USER', 'DB_BOX_PASS', 'DB_BOX_SCHEMA',
        ];
        foreach ($vars as $var) {
            unset($_ENV[$var]);
        }
        // Clear shared static state between tests
        $reflection = new \ReflectionClass(NanoPublisher::class);
        $shared = $reflection->getProperty('sharedConnection');
        $shared->setAccessible(true);
        $shared->setValue(null, null);
        $sharedCh = $reflection->getProperty('sharedChannel');
        $sharedCh->setAccessible(true);
        $sharedCh->setValue(null, null);
    }

    // -------------------------------------------------------------------------
    // Exception categorization tests
    // -------------------------------------------------------------------------

    /**
     * @dataProvider exceptionCategorizationProvider
     */
    public function testCategorizeException(string $message, string $expectedType): void
    {
        $publisher = new NanoPublisher();

        $reflection = new \ReflectionClass($publisher);
        $method = $reflection->getMethod('categorizeException');
        $method->setAccessible(true);

        $exception = new \Exception($message);
        $result = $method->invoke($publisher, $exception);

        $this->assertEquals($expectedType, $result->getValue());
    }

    public static function exceptionCategorizationProvider(): array
    {
        return [
            // Connection errors
            ['Connection refused', PublishErrorType::CONNECTION_ERROR->value],
            ['Socket error on write', PublishErrorType::CONNECTION_ERROR->value],
            ['Network unreachable', PublishErrorType::CONNECTION_ERROR->value],
            ['Broken connection to RabbitMQ', PublishErrorType::CONNECTION_ERROR->value],

            // Channel errors
            ['Channel has been closed', PublishErrorType::CHANNEL_ERROR->value],
            ['CHANNEL_ERROR - expected content', PublishErrorType::CHANNEL_ERROR->value],

            // Timeout errors
            // Note: "Connection timeout" matches 'connection' first, so it's CONNECTION_ERROR
            ['Read timeout waiting for response', PublishErrorType::TIMEOUT->value],
            ['Operation timed out', PublishErrorType::TIMEOUT->value],

            // Encoding errors
            ['JSON encode failed', PublishErrorType::ENCODING_ERROR->value],
            ['Failed to serialize payload', PublishErrorType::ENCODING_ERROR->value],
            ['Malformed JSON input', PublishErrorType::ENCODING_ERROR->value],

            // Config errors
            ['Exchange not found', PublishErrorType::CONFIG_ERROR->value],
            ['Invalid routing key config', PublishErrorType::CONFIG_ERROR->value],

            // Unknown errors
            ['Something completely unexpected', PublishErrorType::UNKNOWN->value],
            ['OutOfMemoryError', PublishErrorType::UNKNOWN->value],
        ];
    }

    // -------------------------------------------------------------------------
    // Publish failure scenario tests
    // -------------------------------------------------------------------------

    public function testPublishThrowsOnConnectionClosedException(): void
    {
        $publisher = $this->createPublisherWithFailingChannel(
            new AMQPConnectionClosedException('Connection lost')
        );

        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $publisher->setMessage($message);

        $this->expectException(AMQPConnectionClosedException::class);
        $this->expectExceptionMessage('Connection lost');
        $publisher->publishToRabbit('test.event');
    }

    public function testPublishThrowsOnIOException(): void
    {
        $publisher = $this->createPublisherWithFailingChannel(
            new AMQPIOException('Socket write failed')
        );

        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $publisher->setMessage($message);

        $this->expectException(AMQPIOException::class);
        $publisher->publishToRabbit('test.event');
    }

    public function testPublishThrowsOnChannelClosedException(): void
    {
        $publisher = $this->createPublisherWithFailingChannel(
            new AMQPChannelClosedException('Channel closed')
        );

        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $publisher->setMessage($message);

        $this->expectException(AMQPChannelClosedException::class);
        $publisher->publishToRabbit('test.event');
    }

    public function testPublishThrowsOnTimeoutException(): void
    {
        $publisher = $this->createPublisherWithFailingChannel(
            new AMQPTimeoutException('Write timed out')
        );

        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $publisher->setMessage($message);

        $this->expectException(AMQPTimeoutException::class);
        $publisher->publishToRabbit('test.event');
    }

    public function testPublishSkipsWhenPublisherDisabled(): void
    {
        $_ENV['AMQP_PUBLISHER_ENABLED'] = '0';

        $publisher = new NanoPublisher();
        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $publisher->setMessage($message);

        // Should not throw - just returns silently
        $publisher->publishToRabbit('test.event');
        $this->assertTrue(true, 'Publish skipped when disabled');
    }

    // -------------------------------------------------------------------------
    // Outbox pattern publish() method tests
    // -------------------------------------------------------------------------

    /**
     * @dataProvider missingDbEnvVarProvider
     */
    public function testPublishThrowsOnMissingDbEnvVar(string $varName): void
    {
        unset($_ENV[$varName]);

        $publisher = new NanoPublisher();
        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $publisher->setMessage($message);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Missing required environment variable for outbox publish: {$varName}");
        $publisher->publish('test.event');
    }

    public static function missingDbEnvVarProvider(): array
    {
        return [
            'missing DB_BOX_HOST' => ['DB_BOX_HOST'],
            'missing DB_BOX_PORT' => ['DB_BOX_PORT'],
            'missing DB_BOX_NAME' => ['DB_BOX_NAME'],
            'missing DB_BOX_USER' => ['DB_BOX_USER'],
            'missing DB_BOX_PASS' => ['DB_BOX_PASS'],
            'missing DB_BOX_SCHEMA' => ['DB_BOX_SCHEMA'],
        ];
    }

    public function testPublishThrowsOnMissingMicroserviceName(): void
    {
        unset($_ENV['AMQP_MICROSERVICE_NAME']);

        $publisher = new NanoPublisher();
        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $publisher->setMessage($message);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required environment variable: AMQP_MICROSERVICE_NAME');
        $publisher->publish('test.event');
    }

    public function testPublishThrowsOnDatabaseConnectionFailure(): void
    {
        // Set invalid DB credentials to force connection failure
        $_ENV['DB_BOX_HOST'] = 'invalid-host-that-does-not-exist.local';
        $_ENV['DB_BOX_PORT'] = '9999';

        $publisher = new NanoPublisher();
        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $publisher->setMessage($message);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to publish to outbox table:');
        $publisher->publish('test.event');
    }

    // -------------------------------------------------------------------------
    // Health check integration with publisher
    // -------------------------------------------------------------------------

    public function testPublisherInheritsHealthCheckFromBase(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('checkHeartBeat')->willReturn(null);

        $publisher = new NanoPublisher();
        $this->injectConnection($publisher, $connection);

        $this->assertTrue($publisher->isConnectionHealthy());
    }

    public function testPublisherHealthCheckDetectsDisconnection(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(false);

        $publisher = new NanoPublisher();
        $this->injectConnection($publisher, $connection);

        $this->assertFalse($publisher->isConnectionHealthy());
    }

    public function testPublisherOutageCircuitBreakerWorks(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(false);

        $publisher = new NanoPublisher();
        $this->injectConnection($publisher, $connection);

        $entered = false;
        $publisher->setOutageCallbacks(
            function () use (&$entered) { $entered = true; },
            null
        );

        $result = $publisher->ensureConnectionOrSleep(0);

        $this->assertFalse($result);
        $this->assertTrue($entered);
        $this->assertTrue($publisher->isInOutage());
    }

    public function testPublisherResetClearsOutageState(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(false);

        $publisher = new NanoPublisher();
        $this->injectConnection($publisher, $connection);

        $publisher->ensureConnectionOrSleep(0);
        $this->assertTrue($publisher->isInOutage());

        // Reset clears connection state but outage flag is separate
        // A subsequent healthy check will clear outage via ensureConnectionOrSleep
        $publisher->reset();

        $reflection = new \ReflectionClass($publisher);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $this->assertNull($connProp->getValue($publisher));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createPublisherWithFailingChannel(\Throwable $exception): NanoPublisher
    {
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('basic_publish')
            ->willThrowException($exception);
        $channel->method('is_open')->willReturn(true);

        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('channel')->willReturn($channel);

        $publisher = new NanoPublisher();
        $this->injectConnection($publisher, $connection);

        // Also inject channel
        $reflection = new \ReflectionClass($publisher);
        $channelProp = $reflection->getProperty('channel');
        $channelProp->setAccessible(true);
        $channelProp->setValue($publisher, $channel);

        $sharedChannel = $reflection->getProperty('sharedChannel');
        $sharedChannel->setAccessible(true);
        $sharedChannel->setValue(null, $channel);

        return $publisher;
    }

    private function injectConnection(NanoPublisher $publisher, AMQPStreamConnection $connection): void
    {
        $reflection = new \ReflectionClass($publisher);

        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($publisher, $connection);

        $sharedProp = $reflection->getProperty('sharedConnection');
        $sharedProp->setAccessible(true);
        $sharedProp->setValue(null, $connection);
    }
}
