<?php

namespace AlexFN\NanoService\Tests\Unit;

use AlexFN\NanoService\NanoServiceClass;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPHeartbeatMissedException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NanoServiceClass infrastructure resilience
 *
 * Tests connection health checks, outage circuit breaker,
 * and connection reset behavior without requiring real RabbitMQ.
 */
class NanoServiceClassTest extends TestCase
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
        $_ENV['AMQP_MICROSERVICE_NAME'] = 'test-service';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $vars = [
            'STATSD_ENABLED', 'AMQP_HOST', 'AMQP_PORT', 'AMQP_USER',
            'AMQP_PASS', 'AMQP_VHOST', 'AMQP_PROJECT', 'AMQP_MICROSERVICE_NAME',
        ];
        foreach ($vars as $var) {
            unset($_ENV[$var]);
        }
    }

    // -------------------------------------------------------------------------
    // isConnectionHealthy() tests
    // -------------------------------------------------------------------------

    public function testIsConnectionHealthyReturnsTrueWhenConnected(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('checkHeartBeat')->willReturn(null);

        $service = $this->createServiceWithConnection($connection);

        $this->assertTrue($service->isConnectionHealthy());
    }

    public function testIsConnectionHealthyReturnsFalseWhenDisconnected(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(false);

        $service = $this->createServiceWithConnection($connection);

        $this->assertFalse($service->isConnectionHealthy());
    }

    public function testIsConnectionHealthyReturnsFalseOnHeartbeatMissed(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('checkHeartBeat')
            ->willThrowException(new AMQPHeartbeatMissedException('Heartbeat missed'));

        $service = $this->createServiceWithConnection($connection);

        $this->assertFalse($service->isConnectionHealthy());
    }

    public function testIsConnectionHealthyReturnsFalseOnGenericException(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('checkHeartBeat')
            ->willThrowException(new \Exception('Socket error'));

        $service = $this->createServiceWithConnection($connection);

        $this->assertFalse($service->isConnectionHealthy());
    }

    public function testIsConnectionHealthyResetsConnectionOnFailure(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(false);

        $service = $this->createServiceWithConnection($connection);

        $service->isConnectionHealthy();

        // After reset, internal connection should be null
        // Next getConnection() would create a fresh one
        $reflection = new \ReflectionClass($service);
        $prop = $reflection->getProperty('connection');
        $prop->setAccessible(true);
        $this->assertNull($prop->getValue($service));
    }

    public function testIsConnectionHealthyResetsSharedConnectionOnHeartbeatMissed(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('checkHeartBeat')
            ->willThrowException(new AMQPHeartbeatMissedException('Missed'));

        $service = $this->createServiceWithConnection($connection);

        $service->isConnectionHealthy();

        $reflection = new \ReflectionClass($service);
        $shared = $reflection->getProperty('sharedConnection');
        $shared->setAccessible(true);
        $this->assertNull($shared->getValue());
    }

    // -------------------------------------------------------------------------
    // reset() tests
    // -------------------------------------------------------------------------

    public function testResetClearsInstanceAndSharedState(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);

        $service = $this->createServiceWithConnection($connection);

        $service->reset();

        $reflection = new \ReflectionClass($service);

        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $this->assertNull($connProp->getValue($service));

        $channelProp = $reflection->getProperty('channel');
        $channelProp->setAccessible(true);
        $this->assertNull($channelProp->getValue($service));

        $sharedConn = $reflection->getProperty('sharedConnection');
        $sharedConn->setAccessible(true);
        $this->assertNull($sharedConn->getValue());

        $sharedChan = $reflection->getProperty('sharedChannel');
        $sharedChan->setAccessible(true);
        $this->assertNull($sharedChan->getValue());
    }

    // -------------------------------------------------------------------------
    // Outage circuit breaker tests
    // -------------------------------------------------------------------------

    public function testEnsureConnectionOrSleepReturnsTrueWhenHealthy(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('checkHeartBeat')->willReturn(null);

        $service = $this->createServiceWithConnection($connection);

        $this->assertTrue($service->ensureConnectionOrSleep(0));
        $this->assertFalse($service->isInOutage());
    }

    public function testEnsureConnectionOrSleepReturnsFalseWhenUnhealthy(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(false);

        $service = $this->createServiceWithConnection($connection);

        // Use sleep(0) to avoid test slowness
        $this->assertFalse($service->ensureConnectionOrSleep(0));
        $this->assertTrue($service->isInOutage());
    }

    public function testEnsureConnectionOrSleepEntersOutageModeOnce(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(false);

        $enterCount = 0;
        $service = $this->createServiceWithConnection($connection);
        $service->setOutageCallbacks(
            function (int $sleep) use (&$enterCount) { $enterCount++; },
            null
        );

        // Call twice - should only enter outage once
        $service->ensureConnectionOrSleep(0);
        $service->ensureConnectionOrSleep(0);

        $this->assertEquals(1, $enterCount, 'onOutageEnter should be called only once');
    }

    public function testEnsureConnectionOrSleepCallsExitCallbackOnRecovery(): void
    {
        $service = new NanoServiceClass();
        $exitCalled = false;
        $service->setOutageCallbacks(
            function (int $sleep) {},
            function () use (&$exitCalled) { $exitCalled = true; }
        );

        // Simulate outage: inject a disconnected mock
        $disconnected = $this->createMock(AMQPStreamConnection::class);
        $disconnected->method('isConnected')->willReturn(false);
        $this->injectConnection($service, $disconnected);

        $service->ensureConnectionOrSleep(0);
        $this->assertTrue($service->isInOutage());

        // Simulate recovery: inject a healthy mock
        $connected = $this->createMock(AMQPStreamConnection::class);
        $connected->method('isConnected')->willReturn(true);
        $connected->method('checkHeartBeat')->willReturn(null);
        $this->injectConnection($service, $connected);

        $service->ensureConnectionOrSleep(0);

        $this->assertFalse($service->isInOutage());
        $this->assertTrue($exitCalled, 'onOutageExit should be called on recovery');
    }

    public function testEnsureConnectionOrSleepPassesSleepSecondsToCallback(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(false);

        $receivedSeconds = null;
        $service = $this->createServiceWithConnection($connection);
        $service->setOutageCallbacks(
            function (int $sleep) use (&$receivedSeconds) { $receivedSeconds = $sleep; },
            null
        );

        $service->ensureConnectionOrSleep(0);

        $this->assertEquals(0, $receivedSeconds);
    }

    public function testIsInOutageReturnsFalseByDefault(): void
    {
        $service = new NanoServiceClass();
        $this->assertFalse($service->isInOutage());
    }

    // -------------------------------------------------------------------------
    // setOutageCallbacks() tests
    // -------------------------------------------------------------------------

    public function testSetOutageCallbacksAcceptsNullCallbacks(): void
    {
        $connection = $this->createMock(AMQPStreamConnection::class);
        $connection->method('isConnected')->willReturn(false);

        $service = $this->createServiceWithConnection($connection);
        $service->setOutageCallbacks(null, null);

        // Should not throw when callbacks are null
        $service->ensureConnectionOrSleep(0);
        $this->assertTrue($service->isInOutage());
    }

    // -------------------------------------------------------------------------
    // Connection failure scenario tests
    // -------------------------------------------------------------------------

    public function testGetConnectionThrowsOnConnectionFailure(): void
    {
        // Don't set valid AMQP env vars - this will cause connection failure
        // But since we can't actually connect, test the re-throw behavior
        $service = new NanoServiceClass();

        $this->expectException(\Throwable::class);
        $service->getConnection();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createServiceWithConnection(AMQPStreamConnection $connection): NanoServiceClass
    {
        $service = new NanoServiceClass();
        $this->injectConnection($service, $connection);
        return $service;
    }

    private function injectConnection(NanoServiceClass $service, AMQPStreamConnection $connection): void
    {
        $reflection = new \ReflectionClass($service);

        $connProp = $reflection->getProperty('connection');
        $connProp->setAccessible(true);
        $connProp->setValue($service, $connection);

        $sharedProp = $reflection->getProperty('sharedConnection');
        $sharedProp->setAccessible(true);
        $sharedProp->setValue(null, $connection);
    }
}
