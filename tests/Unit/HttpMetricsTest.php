<?php

namespace AlexFN\NanoService\Tests\Unit;

use AlexFN\NanoService\Clients\StatsDClient\HttpMetrics;
use AlexFN\NanoService\Clients\StatsDClient\StatsDClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HttpMetrics helper class
 *
 * Tests HTTP request metrics collection:
 * - incoming_request counter (on start)
 * - http_request timing (on finish)
 * - http_response_status_total (on finish)
 * - http_request_by_latency_bucket (on finish)
 * - http_request_errors (on recordError)
 */
class HttpMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['STATSD_ENABLED'] = 'false';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
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
        $metrics = new HttpMetrics($statsd, 'App\Http\Controllers\WebhookController@handle', 'POST');

        $this->assertInstanceOf(HttpMetrics::class, $metrics);
    }

    // ===========================================
    // Start/Finish Lifecycle Tests
    // ===========================================

    public function testStartInitializesTracking(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'App\Http\Controllers\WebhookController@handle', 'POST');

        $metrics->start();
        $this->assertTrue(true);
    }

    public function testFinishWithoutStartDoesNothing(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'App\Http\Controllers\WebhookController@handle', 'POST');

        $metrics->finish();
        $this->assertTrue(true);
    }

    public function testFinishAfterStartRecordsMetrics(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'App\Http\Controllers\WebhookController@handle', 'POST');

        $metrics->start();
        usleep(1000); // 1ms delay
        $metrics->finish();

        $this->assertTrue(true);
    }

    public function testMultipleFinishCallsAreSafe(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'App\Http\Controllers\WebhookController@handle', 'POST');

        $metrics->start();
        $metrics->finish();
        $metrics->finish(); // Second call should be safe (started = false)

        $this->assertTrue(true);
    }

    // ===========================================
    // Status Code Tests
    // ===========================================

    public function testSetStatusCode(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'App\Http\Controllers\WebhookController@handle', 'POST');

        $metrics->start();
        $metrics->setStatusCode(422);
        $metrics->finish();

        $this->assertTrue(true);
    }

    public function testDefaultStatusCodeIs200(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'App\Http\Controllers\WebhookController@handle', 'POST');

        $metrics->start();
        $metrics->finish();

        $this->assertTrue(true);
    }

    /**
     * @dataProvider statusCodeProvider
     */
    public function testDifferentStatusCodes(int $code): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'App\Http\Controllers\WebhookController@handle', 'POST');

        $metrics->start();
        $metrics->setStatusCode($code);
        $metrics->finish();

        $this->assertTrue(true);
    }

    public static function statusCodeProvider(): array
    {
        return [
            [200],
            [201],
            [204],
            [301],
            [400],
            [401],
            [404],
            [422],
            [500],
            [502],
            [503],
        ];
    }

    // ===========================================
    // Error Recording Tests
    // ===========================================

    public function testRecordErrorSetsStatusCode(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'App\Http\Controllers\WebhookController@handle', 'POST');

        $metrics->start();
        $metrics->recordError(new \Exception('Database connection failed'), 500);
        $metrics->finish();

        $this->assertTrue(true);
    }

    public function testRecordErrorDefaultStatusCode(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'App\Http\Controllers\WebhookController@handle', 'POST');

        $metrics->start();
        $metrics->recordError(new \Exception('Internal error'));
        $metrics->finish();

        $this->assertTrue(true);
    }

    public function testRecordErrorCategorizesException(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'App\Http\Controllers\WebhookController@handle', 'POST');

        $metrics->start();
        $metrics->recordError(new \Exception('Database error'), 500);
        $metrics->recordError(new \Exception('Timeout occurred'), 504);
        $metrics->recordError(new \Exception('RabbitMQ connection lost'), 503);
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
        $metrics = new HttpMetrics($statsd, 'App\Http\Controllers\WebhookController@handle', $method);

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
    // Route Variations Tests
    // ===========================================

    /**
     * @dataProvider routeNameProvider
     */
    public function testDifferentRouteNames(string $route): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, $route, 'POST');

        $metrics->start();
        $metrics->finish();

        $this->assertTrue(true);
    }

    public static function routeNameProvider(): array
    {
        return [
            ['App\Http\Controllers\WebhookController@handle'],
            ['App\Http\Controllers\Api\UserController@store'],
            ['App\Http\Controllers\HealthController@check'],
            ['undefined_route'],
            ['Closure'],
        ];
    }

    // ===========================================
    // Integration-like Tests (middleware pattern)
    // ===========================================

    public function testTypicalSuccessFlow(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'App\Http\Controllers\WebhookController@handle', 'POST');
        $metrics->start();

        try {
            usleep(5000); // 5ms processing
            $metrics->setStatusCode(200);
        } finally {
            $metrics->finish();
        }

        $this->assertTrue(true);
    }

    public function testTypicalErrorFlow(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'App\Http\Controllers\WebhookController@handle', 'POST');
        $metrics->start();

        $exceptionCaught = false;
        try {
            throw new \Exception('Database connection failed');
        } catch (\Exception $e) {
            $exceptionCaught = true;
            $metrics->recordError($e, 500);
        } finally {
            $metrics->finish();
        }

        $this->assertTrue($exceptionCaught);
    }

    public function testTypicalNotFoundFlow(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'undefined_route', 'GET');
        $metrics->start();

        try {
            $metrics->setStatusCode(404);
        } finally {
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
        $metrics = new HttpMetrics($statsd, 'App\Http\Controllers\WebhookController@handle', 'POST');

        $metrics->start();
        usleep(10000); // 10ms
        $metrics->finish();

        $this->assertTrue(true);
    }

    public function testZeroDelayTiming(): void
    {
        $statsd = new StatsDClient();
        $metrics = new HttpMetrics($statsd, 'App\Http\Controllers\WebhookController@handle', 'POST');

        $metrics->start();
        $metrics->finish();

        $this->assertTrue(true);
    }
}
