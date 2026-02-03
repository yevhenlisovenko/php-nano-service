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

        // Reset EventRepository singleton between tests
        \AlexFN\NanoService\EventRepository::reset();
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
        $this->expectExceptionMessage("Missing required environment variables: {$varName}");
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
        $this->expectExceptionMessage('Missing required environment variables: AMQP_MICROSERVICE_NAME');
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
        $this->expectExceptionMessage('Failed to connect to event database:');
        $publisher->publish('test.event');
    }

    // -------------------------------------------------------------------------
    // Message ID preservation tests
    // -------------------------------------------------------------------------

    public function testSetIdMethodSetsMessageId(): void
    {
        $message = new NanoServiceMessage();
        $customId = 'custom-message-id-12345';

        $message->setId($customId);

        $this->assertEquals($customId, $message->getId());
    }

    public function testPublishToRabbitPreservesCustomMessageId(): void
    {
        $customId = 'my-custom-id-67890';
        $eventName = 'test.event';

        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $message->setId($customId);

        // Capture the published message
        $publishedMessage = null;
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('basic_publish')
            ->willReturnCallback(function ($msg) use (&$publishedMessage) {
                $publishedMessage = $msg;
            });
        $channel->method('is_open')->willReturn(true);

        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('channel')->willReturn($channel);

        $publisher = new NanoPublisher();
        $this->injectConnection($publisher, $connection);

        // Inject channel
        $reflection = new \ReflectionClass($publisher);
        $channelProp = $reflection->getProperty('channel');
        $channelProp->setAccessible(true);
        $channelProp->setValue($publisher, $channel);

        $sharedChannel = $reflection->getProperty('sharedChannel');
        $sharedChannel->setAccessible(true);
        $sharedChannel->setValue(null, $channel);

        $publisher->setMessage($message);
        $publisher->publishToRabbit($eventName);

        // Verify the message_id wasn't changed
        $this->assertNotNull($publishedMessage, 'Message should have been published');
        $this->assertEquals($customId, $publishedMessage->get('message_id'));
    }

    public function testPublishToRabbitDoesNotOverwriteCustomMessageId(): void
    {
        $customId = 'preserved-id-99999';
        $eventName = 'another.test.event';

        $message = new NanoServiceMessage();
        $message->addPayload(['data' => 'value']);
        $message->setId($customId);

        // Store original ID
        $originalId = $message->getId();

        // Capture the published message
        $publishedMessage = null;
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('basic_publish')
            ->willReturnCallback(function ($msg) use (&$publishedMessage) {
                $publishedMessage = $msg;
            });
        $channel->method('is_open')->willReturn(true);

        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('channel')->willReturn($channel);

        $publisher = new NanoPublisher();
        $this->injectConnection($publisher, $connection);

        // Inject channel
        $reflection = new \ReflectionClass($publisher);
        $channelProp = $reflection->getProperty('channel');
        $channelProp->setAccessible(true);
        $channelProp->setValue($publisher, $channel);

        $sharedChannel = $reflection->getProperty('sharedChannel');
        $sharedChannel->setAccessible(true);
        $sharedChannel->setValue(null, $channel);

        $publisher->setMessage($message);
        $publisher->publishToRabbit($eventName);

        // Verify IDs match
        $this->assertEquals($originalId, $customId, 'Original ID should match custom ID');
        $this->assertEquals($customId, $message->getId(), 'Message ID should not change after publish');
        $this->assertEquals($customId, $publishedMessage->get('message_id'), 'Published message should have custom ID');
    }

    public function testMessageIdPersistsAfterMultiplePublishes(): void
    {
        $customId = 'persistent-id-11111';

        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $message->setId($customId);

        // Capture published messages
        $publishedMessages = [];
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('basic_publish')
            ->willReturnCallback(function ($msg) use (&$publishedMessages) {
                $publishedMessages[] = clone $msg;
            });
        $channel->method('is_open')->willReturn(true);

        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('channel')->willReturn($channel);

        $publisher = new NanoPublisher();
        $this->injectConnection($publisher, $connection);

        // Inject channel
        $reflection = new \ReflectionClass($publisher);
        $channelProp = $reflection->getProperty('channel');
        $channelProp->setAccessible(true);
        $channelProp->setValue($publisher, $channel);

        $sharedChannel = $reflection->getProperty('sharedChannel');
        $sharedChannel->setAccessible(true);
        $sharedChannel->setValue(null, $channel);

        // Publish multiple times
        $publisher->setMessage($message);
        $publisher->publishToRabbit('event.one');
        $publisher->publishToRabbit('event.two');
        $publisher->publishToRabbit('event.three');

        // Verify all published messages have the same custom ID
        $this->assertCount(3, $publishedMessages);
        foreach ($publishedMessages as $publishedMsg) {
            $this->assertEquals($customId, $publishedMsg->get('message_id'), 'Each published message should have the custom ID');
        }
    }

    // -------------------------------------------------------------------------
    // Publish method integration tests (outbox + RabbitMQ)
    // -------------------------------------------------------------------------

    public function testPublishCallsPublishToRabbitAfterStoringInOutbox(): void
    {
        // Create mock PDO and statement for successful outbox insert
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        // Inject mock PDO into EventRepository via reflection
        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        // Create a partial mock of NanoPublisher to spy on publishToRabbit
        $publisher = $this->getMockBuilder(NanoPublisher::class)
            ->onlyMethods(['publishToRabbit'])
            ->getMock();

        // Expect publishToRabbit to be called once with the event name
        $publisher->expects($this->once())
            ->method('publishToRabbit')
            ->with('test.event');

        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $publisher->setMessage($message);

        // Now publish should succeed and call publishToRabbit
        $publisher->publish('test.event');
    }

    public function testPublishSkipsRabbitWhenPublisherDisabled(): void
    {
        $_ENV['AMQP_PUBLISHER_ENABLED'] = '0';

        // Mock successful database insert
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        // Create a partial mock to spy on publishToRabbit
        $publisher = $this->getMockBuilder(NanoPublisher::class)
            ->onlyMethods(['publishToRabbit'])
            ->getMock();

        // publishToRabbit will still be called, but it should return early
        // We verify it gets called but doesn't actually publish
        $publisher->expects($this->once())
            ->method('publishToRabbit')
            ->with('test.event');

        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $publisher->setMessage($message);

        $publisher->publish('test.event');
    }

    public function testPublishStoresMessageBodyInOutbox(): void
    {
        // Track what was passed to insertOutbox
        $capturedProducerService = null;
        $capturedEventType = null;
        $capturedMessageBody = null;

        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')
            ->willReturnCallback(function ($params) use (&$capturedProducerService, &$capturedEventType, &$capturedMessageBody) {
                // Only capture from INSERT query (has 5 parameters), not UPDATE query (has 1 parameter)
                if (count($params) === 5) {
                    if (isset($params[0])) $capturedProducerService = $params[0];
                    if (isset($params[1])) $capturedEventType = $params[1];
                    if (isset($params[2])) $capturedMessageBody = $params[2];
                }
                return true;
            });

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        // Mock publishToRabbit to prevent actual RabbitMQ call
        $publisher = $this->getMockBuilder(NanoPublisher::class)
            ->onlyMethods(['publishToRabbit'])
            ->getMock();

        $message = new NanoServiceMessage();
        $message->addPayload(['user_id' => 123, 'action' => 'created']);
        $message->addMeta(['request_id' => 'test-123']);
        $publisher->setMessage($message);

        $publisher->publish('user.created');

        // Verify the captured data
        $this->assertEquals('test-publisher', $capturedProducerService);
        $this->assertEquals('user.created', $capturedEventType);
        $this->assertNotNull($capturedMessageBody);

        // Verify message body structure
        $body = json_decode($capturedMessageBody, true);
        $this->assertArrayHasKey('payload', $body);
        $this->assertEquals(123, $body['payload']['user_id']);
        $this->assertEquals('created', $body['payload']['action']);
    }

    public function testPublishWithMetaSetsMetaOnMessage(): void
    {
        // Mock successful database
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        $publisher = $this->getMockBuilder(NanoPublisher::class)
            ->onlyMethods(['publishToRabbit'])
            ->getMock();

        $message = new NanoServiceMessage();
        $message->addPayload(['data' => 'value']);
        $publisher->setMessage($message);
        $publisher->setMeta(['correlation_id' => 'abc-123', 'trace_id' => 'xyz-789']);

        $publisher->publish('test.event.with.meta');

        // Verify meta was added to message
        $body = json_decode($message->getBody(), true);
        $this->assertArrayHasKey('meta', $body);
        $this->assertEquals('abc-123', $body['meta']['correlation_id']);
        $this->assertEquals('xyz-789', $body['meta']['trace_id']);
    }

    public function testPublishSetsCorrectEventAndAppId(): void
    {
        // Mock successful database
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        $publisher = $this->getMockBuilder(NanoPublisher::class)
            ->onlyMethods(['publishToRabbit'])
            ->getMock();

        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $publisher->setMessage($message);

        $publisher->publish('my.test.event');

        // Verify event and app_id were set (these are message properties, not body data)
        $this->assertEquals('my.test.event', $message->getEventName());
        $this->assertEquals('test.test-publisher', $message->get('app_id'));
    }

    public function testPublishPassesMessageIdToStoreEvent(): void
    {
        // Track what was passed to insertOutbox
        $capturedMessageId = null;

        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')
            ->willReturnCallback(function ($params) use (&$capturedMessageId) {
                // The message_id is the 5th parameter (index 4)
                if (isset($params[4])) {
                    $capturedMessageId = $params[4];
                }
                return true;
            });

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        // Mock publishToRabbit to prevent actual RabbitMQ call
        $publisher = $this->getMockBuilder(NanoPublisher::class)
            ->onlyMethods(['publishToRabbit'])
            ->getMock();

        $customMessageId = 'custom-uuid-12345';
        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $message->setId($customMessageId);
        $publisher->setMessage($message);

        $publisher->publish('test.event');

        // Verify the message_id was passed to storeEvent
        $this->assertEquals($customMessageId, $capturedMessageId);
    }

    public function testPublishCallsMarkAsPublishedAfterRabbitMQPublish(): void
    {
        // Track calls to prepare() to see what SQL queries are executed
        $preparedQueries = [];

        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')
            ->willReturnCallback(function ($query) use (&$preparedQueries, $mockStmt) {
                $preparedQueries[] = $query;
                return $mockStmt;
            });

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        // Create a real channel mock to ensure publishToRabbit succeeds
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('basic_publish')->willReturn(null);
        $channel->method('is_open')->willReturn(true);

        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('channel')->willReturn($channel);

        $publisher = new NanoPublisher();
        $this->injectConnection($publisher, $connection);

        // Inject channel
        $publisherReflection = new \ReflectionClass($publisher);
        $channelProp = $publisherReflection->getProperty('channel');
        $channelProp->setAccessible(true);
        $channelProp->setValue($publisher, $channel);

        $sharedChannel = $publisherReflection->getProperty('sharedChannel');
        $sharedChannel->setAccessible(true);
        $sharedChannel->setValue(null, $channel);

        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $publisher->setMessage($message);

        $publisher->publish('test.event');

        // Verify both INSERT and UPDATE queries were executed
        $this->assertCount(2, $preparedQueries, 'Should have two SQL queries: INSERT and UPDATE');

        // First query should be INSERT (storeEvent)
        $this->assertStringContainsString('INSERT INTO', $preparedQueries[0]);
        $this->assertStringContainsString('outbox', $preparedQueries[0]);

        // Second query should be UPDATE (markAsPublished)
        $this->assertStringContainsString('UPDATE', $preparedQueries[1]);
        $this->assertStringContainsString('outbox', $preparedQueries[1]);
        $this->assertStringContainsString("status = 'published'", $preparedQueries[1]);
        $this->assertStringContainsString('published_at = NOW()', $preparedQueries[1]);
    }

    public function testPublishDoesNotCallMarkAsPublishedIfRabbitMQFails(): void
    {
        // Track calls to prepare() to verify markAsPublished is NOT called
        $preparedQueries = [];

        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')
            ->willReturnCallback(function ($query) use (&$preparedQueries, $mockStmt) {
                $preparedQueries[] = $query;
                return $mockStmt;
            });

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        // Create a channel that throws exception on publish
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('basic_publish')
            ->willThrowException(new AMQPConnectionClosedException('Connection lost'));
        $channel->method('is_open')->willReturn(true);

        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('channel')->willReturn($channel);

        $publisher = new NanoPublisher();
        $this->injectConnection($publisher, $connection);

        // Inject channel
        $publisherReflection = new \ReflectionClass($publisher);
        $channelProp = $publisherReflection->getProperty('channel');
        $channelProp->setAccessible(true);
        $channelProp->setValue($publisher, $channel);

        $sharedChannel = $publisherReflection->getProperty('sharedChannel');
        $sharedChannel->setAccessible(true);
        $sharedChannel->setValue(null, $channel);

        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $publisher->setMessage($message);

        // NEW BEHAVIOR: publish() returns false instead of throwing
        $result = $publisher->publish('test.event');

        // Verify publish() returned false
        $this->assertFalse($result, 'publish() should return false when RabbitMQ fails');

        // Verify only INSERT was executed, NOT UPDATE for 'published'
        $this->assertCount(2, $preparedQueries, 'Should have INSERT and UPDATE queries');
        $this->assertStringContainsString('INSERT INTO', $preparedQueries[0]);

        // Second query should be UPDATE for markAsFailed (not markAsPublished)
        $this->assertStringContainsString('UPDATE', $preparedQueries[1]);
        $this->assertStringContainsString("status = 'failed'", $preparedQueries[1]);
        $this->assertStringNotContainsString("status = 'published'", $preparedQueries[1]);
        $this->assertStringNotContainsString('published_at = NOW()', $preparedQueries[1]);
    }

    public function testPublishWithCustomMessageIdMarksCorrectEventAsPublished(): void
    {
        // Track the message_id passed to markAsPublished (UPDATE query)
        $capturedUpdateMessageId = null;

        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')
            ->willReturnCallback(function ($params) use (&$capturedUpdateMessageId) {
                // For UPDATE query, message_id is the first parameter
                if (count($params) === 1) {
                    $capturedUpdateMessageId = $params[0];
                }
                return true;
            });

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        // Create successful RabbitMQ channel
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('basic_publish')->willReturn(null);
        $channel->method('is_open')->willReturn(true);

        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('channel')->willReturn($channel);

        $publisher = new NanoPublisher();
        $this->injectConnection($publisher, $connection);

        // Inject channel
        $publisherReflection = new \ReflectionClass($publisher);
        $channelProp = $publisherReflection->getProperty('channel');
        $channelProp->setAccessible(true);
        $channelProp->setValue($publisher, $channel);

        $sharedChannel = $publisherReflection->getProperty('sharedChannel');
        $sharedChannel->setAccessible(true);
        $sharedChannel->setValue(null, $channel);

        $customMessageId = 'my-tracking-uuid-99999';
        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $message->setId($customMessageId);
        $publisher->setMessage($message);

        $publisher->publish('test.event');

        // Verify markAsPublished was called with the correct message_id
        $this->assertEquals($customMessageId, $capturedUpdateMessageId);
    }

    public function testPublishReturnsTrueOnSuccessfulRabbitMQPublish(): void
    {
        // Mock successful database operations
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        // Create successful RabbitMQ channel
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('basic_publish')->willReturn(null);
        $channel->method('is_open')->willReturn(true);

        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('channel')->willReturn($channel);

        $publisher = new NanoPublisher();
        $this->injectConnection($publisher, $connection);

        // Inject channel
        $publisherReflection = new \ReflectionClass($publisher);
        $channelProp = $publisherReflection->getProperty('channel');
        $channelProp->setAccessible(true);
        $channelProp->setValue($publisher, $channel);

        $sharedChannel = $publisherReflection->getProperty('sharedChannel');
        $sharedChannel->setAccessible(true);
        $sharedChannel->setValue(null, $channel);

        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $publisher->setMessage($message);

        $result = $publisher->publish('test.event');

        $this->assertTrue($result, 'publish() should return true on success');
    }

    public function testPublishReturnsFalseOnRabbitMQFailure(): void
    {
        // Mock successful database operations
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        // Create failing RabbitMQ channel
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('basic_publish')
            ->willThrowException(new AMQPTimeoutException('Timeout'));
        $channel->method('is_open')->willReturn(true);

        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('channel')->willReturn($channel);

        $publisher = new NanoPublisher();
        $this->injectConnection($publisher, $connection);

        // Inject channel
        $publisherReflection = new \ReflectionClass($publisher);
        $channelProp = $publisherReflection->getProperty('channel');
        $channelProp->setAccessible(true);
        $channelProp->setValue($publisher, $channel);

        $sharedChannel = $publisherReflection->getProperty('sharedChannel');
        $sharedChannel->setAccessible(true);
        $sharedChannel->setValue(null, $channel);

        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $publisher->setMessage($message);

        $result = $publisher->publish('test.event');

        $this->assertFalse($result, 'publish() should return false when RabbitMQ fails');
    }

    public function testPublishCallsMarkAsFailedWhenRabbitMQFails(): void
    {
        // Track SQL queries to verify markAsFailed is called
        $preparedQueries = [];

        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')
            ->willReturnCallback(function ($query) use (&$preparedQueries, $mockStmt) {
                $preparedQueries[] = $query;
                return $mockStmt;
            });

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        // Create failing RabbitMQ channel
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('basic_publish')
            ->willThrowException(new AMQPChannelClosedException('Channel closed'));
        $channel->method('is_open')->willReturn(true);

        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('channel')->willReturn($channel);

        $publisher = new NanoPublisher();
        $this->injectConnection($publisher, $connection);

        // Inject channel
        $publisherReflection = new \ReflectionClass($publisher);
        $channelProp = $publisherReflection->getProperty('channel');
        $channelProp->setAccessible(true);
        $channelProp->setValue($publisher, $channel);

        $sharedChannel = $publisherReflection->getProperty('sharedChannel');
        $sharedChannel->setAccessible(true);
        $sharedChannel->setValue(null, $channel);

        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $publisher->setMessage($message);

        $result = $publisher->publish('test.event');

        // Verify markAsFailed was called
        $this->assertFalse($result);
        $this->assertCount(2, $preparedQueries, 'Should have INSERT and UPDATE (markAsFailed) queries');
        $this->assertStringContainsString('INSERT INTO', $preparedQueries[0]);
        $this->assertStringContainsString('UPDATE', $preparedQueries[1]);
        $this->assertStringContainsString("status = 'failed'", $preparedQueries[1]);
    }

    public function testPublishDoesNotThrowExceptionOnRabbitMQFailure(): void
    {
        // Mock successful database operations
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        // Create failing RabbitMQ channel with various exceptions
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('basic_publish')
            ->willThrowException(new AMQPIOException('Network error'));
        $channel->method('is_open')->willReturn(true);

        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('channel')->willReturn($channel);

        $publisher = new NanoPublisher();
        $this->injectConnection($publisher, $connection);

        // Inject channel
        $publisherReflection = new \ReflectionClass($publisher);
        $channelProp = $publisherReflection->getProperty('channel');
        $channelProp->setAccessible(true);
        $channelProp->setValue($publisher, $channel);

        $sharedChannel = $publisherReflection->getProperty('sharedChannel');
        $sharedChannel->setAccessible(true);
        $sharedChannel->setValue(null, $channel);

        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $publisher->setMessage($message);

        // Should NOT throw exception, just return false
        $result = $publisher->publish('test.event');

        $this->assertFalse($result, 'Should return false without throwing exception');
    }

    /**
     * @dataProvider rabbitMQExceptionProvider
     */
    public function testPublishHandlesAllRabbitMQExceptionTypes(\Throwable $exception): void
    {
        // Mock successful database operations
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);

        $mockPdo = $this->createMock(\PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repository = \AlexFN\NanoService\EventRepository::getInstance();
        $reflection = new \ReflectionClass($repository);
        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($repository, $mockPdo);

        // Create failing RabbitMQ channel
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('basic_publish')->willThrowException($exception);
        $channel->method('is_open')->willReturn(true);

        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('channel')->willReturn($channel);

        $publisher = new NanoPublisher();
        $this->injectConnection($publisher, $connection);

        // Inject channel
        $publisherReflection = new \ReflectionClass($publisher);
        $channelProp = $publisherReflection->getProperty('channel');
        $channelProp->setAccessible(true);
        $channelProp->setValue($publisher, $channel);

        $sharedChannel = $publisherReflection->getProperty('sharedChannel');
        $sharedChannel->setAccessible(true);
        $sharedChannel->setValue(null, $channel);

        $message = new NanoServiceMessage();
        $message->addPayload(['test' => 'data']);
        $publisher->setMessage($message);

        // Should return false for all exception types
        $result = $publisher->publish('test.event');

        $this->assertFalse($result, 'Should return false for ' . get_class($exception));
    }

    public static function rabbitMQExceptionProvider(): array
    {
        return [
            'connection closed' => [new AMQPConnectionClosedException('Connection lost')],
            'channel closed' => [new AMQPChannelClosedException('Channel closed')],
            'IO exception' => [new AMQPIOException('Network error')],
            'timeout' => [new AMQPTimeoutException('Timeout occurred')],
            'generic exception' => [new \Exception('Unexpected error')],
        ];
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
