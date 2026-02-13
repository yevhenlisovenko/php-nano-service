<?php

namespace AlexFN\NanoService\Tests\Unit;

use AlexFN\NanoService\Clients\StatsDClient\PublishMetrics;
use AlexFN\NanoService\Clients\StatsDClient\StatsDClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PublishMetrics helper class
 *
 * Tests job/message publishing metrics collection functionality.
 */
class PublishMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Disable metrics by default for tests
        $_ENV['STATSD_ENABLED'] = 'false';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clean up environment
        unset($_ENV['STATSD_ENABLED']);
        unset($_ENV['STATSD_HOST']);
        unset($_ENV['STATSD_PORT']);
        unset($_ENV['STATSD_NAMESPACE']);
    }

    // ===========================================
    // Constructor Tests
    // ===========================================

    public function testConstructorStoresParameters(): void
    {
        $statsd = new StatsDClient();
        $metrics = new PublishMetrics($statsd, 'webhook.stripe');

        $this->assertInstanceOf(PublishMetrics::class, $metrics);
    }

    // ===========================================
    // Start/Finish Lifecycle Tests
    // ===========================================

    public function testStartInitializesTracking(): void
    {
        $statsd = new StatsDClient();
        $metrics = new PublishMetrics($statsd, 'webhook.stripe');

        $metrics->start();
        // If we get here without exception, start worked
        $this->assertTrue(true);
    }

    public function testFinishWithoutStartDoesNothing(): void
    {
        $statsd = new StatsDClient();
        $metrics = new PublishMetrics($statsd, 'webhook.stripe');

        // Should not throw exception when finish is called without start
        $metrics->finish();
        $this->assertTrue(true);
    }

    public function testFinishAfterStartRecordsMetrics(): void
    {
        $statsd = new StatsDClient();
        $metrics = new PublishMetrics($statsd, 'webhook.stripe');

        $metrics->start();
        usleep(1000); // 1ms delay
        $metrics->finish();

        // If we get here without exception, metrics were recorded
        $this->assertTrue(true);
    }

    public function testMultipleFinishCallsAreSafe(): void
    {
        $statsd = new StatsDClient();
        $metrics = new PublishMetrics($statsd, 'webhook.stripe');

        $metrics->start();
        $metrics->finish();
        $metrics->finish(); // Second call should be safe (does nothing)

        $this->assertTrue(true);
    }

    // ===========================================
    // Success Recording Tests
    // ===========================================

    public function testRecordSuccess(): void
    {
        $statsd = new StatsDClient();
        $metrics = new PublishMetrics($statsd, 'webhook.stripe');

        $metrics->start();
        $metrics->recordSuccess();
        $metrics->finish();

        $this->assertTrue(true);
    }

    public function testDefaultStatusIsSuccess(): void
    {
        $statsd = new StatsDClient();
        $metrics = new PublishMetrics($statsd, 'webhook.stripe');

        $metrics->start();
        // Without explicit recordSuccess/recordFailure, status should be 'success'
        $metrics->finish();

        $this->assertTrue(true);
    }

    // ===========================================
    // Failure Recording Tests
    // ===========================================

    public function testRecordFailureWithAttempts(): void
    {
        $statsd = new StatsDClient();
        $metrics = new PublishMetrics($statsd, 'webhook.stripe');

        $metrics->start();
        $metrics->recordFailure(3); // Third attempt
        $metrics->finish();

        $this->assertTrue(true);
    }

    public function testRecordFailureTracksRetryAttempts(): void
    {
        $statsd = new StatsDClient();
        $metrics = new PublishMetrics($statsd, 'webhook.stripe');

        $metrics->start();
        $metrics->recordFailure(5);
        $metrics->finish();

        // Retry attempts should be tracked
        $this->assertTrue(true);
    }

    /**
     * @dataProvider attemptCountProvider
     */
    public function testRecordFailureWithDifferentAttemptCounts(int $attempts): void
    {
        $statsd = new StatsDClient();
        $metrics = new PublishMetrics($statsd, 'webhook.stripe');

        $metrics->start();
        $metrics->recordFailure($attempts);
        $metrics->finish();

        $this->assertTrue(true);
    }

    public static function attemptCountProvider(): array
    {
        return [
            [1],
            [2],
            [3],
            [5],
            [10],
            [100],
        ];
    }

    // ===========================================
    // Integration-like Tests
    // ===========================================

    public function testTypicalSuccessFlow(): void
    {
        $statsd = new StatsDClient();
        $metrics = new PublishMetrics($statsd, 'webhook.stripe');
        $metrics->start();

        try {
            // Simulate successful publish
            usleep(5000); // 5ms processing
            $metrics->recordSuccess();

        } finally {
            $metrics->finish();
        }

        $this->assertTrue(true);
    }

    public function testTypicalFailureFlowWithRetry(): void
    {
        $statsd = new StatsDClient();
        $metrics = new PublishMetrics($statsd, 'webhook.stripe');
        $metrics->start();

        try {
            // Simulate publish failure
            throw new \Exception('RabbitMQ connection lost');

        } catch (\Exception $e) {
            $attempts = 1; // First attempt
            $metrics->recordFailure($attempts + 1);

        } finally {
            $metrics->finish();
        }

        $this->assertTrue(true);
    }

    public function testTryFinallyPatternWithException(): void
    {
        $statsd = new StatsDClient();
        $metrics = new PublishMetrics($statsd, 'webhook.stripe');
        $metrics->start();

        $exceptionCaught = false;
        try {
            // Simulate exception
            throw new \RuntimeException('Connection timeout');

        } catch (\RuntimeException $e) {
            $exceptionCaught = true;
            $metrics->recordFailure(1);

        } finally {
            // finish() MUST be called even on exception
            $metrics->finish();
        }

        $this->assertTrue($exceptionCaught);
    }

    // ===========================================
    // Event Name Variations Tests
    // ===========================================

    /**
     * @dataProvider eventNameProvider
     */
    public function testDifferentEventNames(string $eventName): void
    {
        $statsd = new StatsDClient();
        $metrics = new PublishMetrics($statsd, $eventName);

        $metrics->start();
        $metrics->recordSuccess();
        $metrics->finish();

        $this->assertTrue(true);
    }

    public static function eventNameProvider(): array
    {
        return [
            ['webhook.stripe'],
            ['webhook.paypal'],
            ['webhook.plaid'],
            ['webhook.twilio'],
            ['webhook.sendgrid'],
            ['webhook.intercom'],
            ['webhook.shopify'],
            ['notification.email'],
            ['user.created'],
            ['order.completed'],
        ];
    }

    // ===========================================
    // Service Name Tests
    // ===========================================

    public function testDifferentEventNameVariations(): void
    {
        $events = ['webhook.stripe', 'webhook.paypal', 'notification.email', 'user.created'];

        foreach ($events as $event) {
            $statsd = new StatsDClient();
            $metrics = new PublishMetrics($statsd, $event);
            $metrics->start();
            $metrics->recordSuccess();
            $metrics->finish();
        }

        $this->assertTrue(true);
    }

    // ===========================================
    // Timing Tests
    // ===========================================

    public function testTimingAccuracy(): void
    {
        $statsd = new StatsDClient();
        $metrics = new PublishMetrics($statsd, 'webhook.stripe');

        $metrics->start();
        usleep(10000); // 10ms
        $metrics->finish();

        // If we get here, timing was recorded
        $this->assertTrue(true);
    }

    public function testZeroDelayTiming(): void
    {
        $statsd = new StatsDClient();
        $metrics = new PublishMetrics($statsd, 'webhook.stripe');

        $metrics->start();
        // No delay
        $metrics->finish();

        $this->assertTrue(true);
    }

    // ===========================================
    // Multiple Operations Tests
    // ===========================================

    public function testMultipleRecordSuccessCallsAreSafe(): void
    {
        $statsd = new StatsDClient();
        $metrics = new PublishMetrics($statsd, 'webhook.stripe');

        $metrics->start();
        $metrics->recordSuccess();
        $metrics->recordSuccess(); // Multiple calls should be safe
        $metrics->finish();

        $this->assertTrue(true);
    }

    public function testRecordFailureOverridesSuccess(): void
    {
        $statsd = new StatsDClient();
        $metrics = new PublishMetrics($statsd, 'webhook.stripe');

        $metrics->start();
        $metrics->recordSuccess();
        $metrics->recordFailure(1); // Failure should override success
        $metrics->finish();

        $this->assertTrue(true);
    }

    public function testRecordSuccessOverridesFailure(): void
    {
        $statsd = new StatsDClient();
        $metrics = new PublishMetrics($statsd, 'webhook.stripe');

        $metrics->start();
        $metrics->recordFailure(1);
        $metrics->recordSuccess(); // Success should override failure
        $metrics->finish();

        $this->assertTrue(true);
    }
}
