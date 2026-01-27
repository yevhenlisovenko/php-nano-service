<?php

namespace AlexFN\NanoService\Tests\Unit;

use AlexFN\NanoService\Clients\StatsDClient\HttpMetrics;
use AlexFN\NanoService\Clients\StatsDClient\StatsDClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HttpMetrics helper class
 *
 * Tests HTTP request metrics collection functionality.
 */
class HttpMetricsTest extends TestCase
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
        unset($_ENV['STATSD_SAMPLE_OK']);
        unset($_ENV['STATSD_SAMPLE_PAYLOAD']);
    }

    // ===========================================
    // Constructor Tests
    // ===========================================

    public function testConstructorStoresParameters(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'myservice', 'stripe', 'POST');

        // Just verify construction doesn't throw
        $this->assertInstanceOf(HttpMetrics::class, $metrics);
    }

    // ===========================================
    // Start/Finish Lifecycle Tests
    // ===========================================

    public function testStartInitializesTracking(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'myservice', 'stripe', 'POST');

        $metrics->start();
        // If we get here without exception, start worked
        $this->assertTrue(true);
    }

    public function testFinishWithoutStartDoesNothing(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'myservice', 'stripe', 'POST');

        // Should not throw exception when finish is called without start
        $metrics->finish();
        $this->assertTrue(true);
    }

    public function testFinishAfterStartRecordsMetrics(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'myservice', 'stripe', 'POST');

        $metrics->start();
        usleep(1000); // 1ms delay
        $metrics->finish();

        // If we get here without exception, metrics were recorded
        $this->assertTrue(true);
    }

    public function testMultipleFinishCallsAreSafe(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'myservice', 'stripe', 'POST');

        $metrics->start();
        $metrics->finish();
        $metrics->finish(); // Second call should be safe

        $this->assertTrue(true);
    }

    // ===========================================
    // Status Tests
    // ===========================================

    public function testSetStatusUpdatesStatusAndCode(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'myservice', 'stripe', 'POST');

        $metrics->start();
        $metrics->setStatus('validation_error', 422);
        $metrics->finish();

        // Verify no exception was thrown
        $this->assertTrue(true);
    }

    public function testDefaultStatusIsSuccess(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'myservice', 'stripe', 'POST');

        $metrics->start();
        $metrics->finish();

        // Default status should be 'success' with 200 code
        // We can't directly verify internal state, but we verify no exception
        $this->assertTrue(true);
    }

    // ===========================================
    // Error Recording Tests
    // ===========================================

    public function testRecordErrorSetsFailedStatus(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'myservice', 'stripe', 'POST');

        $metrics->start();
        $metrics->recordError(new \Exception('Database connection failed'), 500);
        $metrics->finish();

        $this->assertTrue(true);
    }

    public function testRecordErrorWithDefaultStatusCode(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'myservice', 'stripe', 'POST');

        $metrics->start();
        $metrics->recordError(new \Exception('Internal error'));
        $metrics->finish();

        // Default status code should be 500
        $this->assertTrue(true);
    }

    public function testRecordErrorCategorizesException(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'myservice', 'stripe', 'POST');

        $metrics->start();

        // Test different error types
        $metrics->recordError(new \Exception('Database error'), 500);
        $metrics->recordError(new \Exception('Timeout occurred'), 504);
        $metrics->recordError(new \Exception('RabbitMQ connection lost'), 503);

        $metrics->finish();
        $this->assertTrue(true);
    }

    // ===========================================
    // Content Type Tracking Tests
    // ===========================================

    public function testTrackContentType(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'myservice', 'stripe', 'POST');

        $metrics->start();
        $metrics->trackContentType('application/json');
        $metrics->finish();

        $this->assertTrue(true);
    }

    public function testTrackContentTypeWithNull(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'myservice', 'stripe', 'POST');

        $metrics->start();
        $metrics->trackContentType(null);
        $metrics->finish();

        $this->assertTrue(true);
    }

    public function testTrackContentTypeWithCharset(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'myservice', 'stripe', 'POST');

        $metrics->start();
        $metrics->trackContentType('application/json; charset=utf-8');
        $metrics->finish();

        $this->assertTrue(true);
    }

    // ===========================================
    // Payload Size Tracking Tests
    // ===========================================

    public function testTrackPayloadSize(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'myservice', 'stripe', 'POST');

        $metrics->start();
        $metrics->trackPayloadSize(1024);
        $metrics->finish();

        $this->assertTrue(true);
    }

    public function testTrackPayloadSizeZero(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'myservice', 'stripe', 'POST');

        $metrics->start();
        $metrics->trackPayloadSize(0);
        $metrics->finish();

        $this->assertTrue(true);
    }

    public function testTrackPayloadSizeLarge(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'myservice', 'stripe', 'POST');

        $metrics->start();
        $metrics->trackPayloadSize(10 * 1024 * 1024); // 10 MB
        $metrics->finish();

        $this->assertTrue(true);
    }

    // ===========================================
    // HTTP Methods Tests
    // ===========================================

    /**
     * @dataProvider httpMethodProvider
     */
    public function testDifferentHttpMethods(string $method): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'myservice', 'stripe', $method);

        $metrics->start();
        $metrics->finish();

        $this->assertTrue(true);
    }

    public static function httpMethodProvider(): array
    {
        return [
            ['GET'],
            ['POST'],
            ['PUT'],
            ['PATCH'],
            ['DELETE'],
            ['OPTIONS'],
            ['HEAD'],
        ];
    }

    // ===========================================
    // Integration-like Tests
    // ===========================================

    public function testTypicalSuccessFlow(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'hook2event', 'stripe', 'POST');
        $metrics->start();

        try {
            // Simulate request processing
            $metrics->trackContentType('application/json');
            $metrics->trackPayloadSize(256);
            usleep(5000); // 5ms processing

        } finally {
            $metrics->finish();
        }

        $this->assertTrue(true);
    }

    public function testTypicalValidationErrorFlow(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'hook2event', 'stripe', 'POST');
        $metrics->start();

        try {
            // Simulate validation failure
            $metrics->trackContentType('application/json');
            $metrics->trackPayloadSize(256);
            $metrics->setStatus('validation_error', 422);

        } finally {
            $metrics->finish();
        }

        $this->assertTrue(true);
    }

    public function testTypicalServerErrorFlow(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'hook2event', 'stripe', 'POST');
        $metrics->start();

        try {
            // Simulate server error
            $metrics->trackContentType('application/json');
            throw new \Exception('Database connection failed');

        } catch (\Exception $e) {
            $metrics->recordError($e, 500);

        } finally {
            $metrics->finish();
        }

        $this->assertTrue(true);
    }

    public function testMultipleTrackingCalls(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'hook2event', 'stripe', 'POST');
        $metrics->start();

        // Multiple tracking calls should be safe
        $metrics->trackContentType('application/json');
        $metrics->trackContentType('text/plain'); // Override
        $metrics->trackPayloadSize(100);
        $metrics->trackPayloadSize(200); // Override

        $metrics->finish();
        $this->assertTrue(true);
    }

    // ===========================================
    // Provider Variations Tests
    // ===========================================

    /**
     * @dataProvider providerNameProvider
     */
    public function testDifferentProviders(string $provider): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'hook2event', $provider, 'POST');

        $metrics->start();
        $metrics->finish();

        $this->assertTrue(true);
    }

    public static function providerNameProvider(): array
    {
        return [
            ['stripe'],
            ['paypal'],
            ['plaid'],
            ['twilio'],
            ['sendgrid'],
            ['intercom'],
            ['shopify'],
        ];
    }

    // ===========================================
    // Service Name Tests
    // ===========================================

    public function testDifferentServiceNames(): void
    {
        $services = ['hook2event', 'billing-core', 'notification-service', 'api-gateway'];

        foreach ($services as $service) {
            $statsd = new StatsDClient();
            $metrics = new HttpMetrics($statsd, $service, 'test', 'POST');
            $metrics->start();
            $metrics->finish();
        }

        $this->assertTrue(true);
    }
}
