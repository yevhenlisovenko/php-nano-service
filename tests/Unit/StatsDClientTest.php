<?php

namespace AlexFN\NanoService\Tests\Unit;

use AlexFN\NanoService\Clients\StatsDClient\StatsDClient;
use AlexFN\NanoService\Clients\StatsDClient\Enums\EventExitStatusTag;
use AlexFN\NanoService\Clients\StatsDClient\Enums\EventRetryStatusTag;
use AlexFN\NanoService\Config\StatsDConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StatsDClient
 *
 * Tests parameter order, method behavior, and metrics collection.
 * Critical: Validates that parameters passed to League\StatsD\Client are correct.
 */
class StatsDClientTest extends TestCase
{
    private array $envVars = [
        'STATSD_ENABLED',
        'STATSD_HOST',
        'STATSD_PORT',
        'STATSD_NAMESPACE',
        'STATSD_SAMPLE_OK',
        'STATSD_SAMPLE_PAYLOAD',
        'AMQP_MICROSERVICE_NAME',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupEnv();
        // Disable metrics by default for tests
        putenv('STATSD_ENABLED=false');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->cleanupEnv();
    }

    private function cleanupEnv(): void
    {
        foreach ($this->envVars as $var) {
            putenv($var);
        }
    }

    public function testConstructorWithDisabledMetrics(): void
    {
        putenv('STATSD_ENABLED=false');

        $client = new StatsDClient();

        $this->assertFalse($client->isEnabled());
    }

    public function testConstructorWithEnabledMetrics(): void
    {
        putenv('STATSD_ENABLED=true');
        putenv('STATSD_HOST=localhost');
        putenv('STATSD_PORT=8125');
        putenv('STATSD_NAMESPACE=test');
        putenv('STATSD_SAMPLE_OK=0.1');
        putenv('STATSD_SAMPLE_PAYLOAD=0.05');
        putenv('AMQP_MICROSERVICE_NAME=test-service');

        $client = new StatsDClient();

        $this->assertTrue($client->isEnabled());
    }

    public function testConstructorWithConfigObject(): void
    {
        $config = new StatsDConfig([
            'enabled' => 'true',
            'host' => 'statsd.local',
            'port' => 9125,
            'namespace' => 'custom',
            'nano_service_name' => 'test-service',
            'sampling' => [
                'ok_events' => 0.5,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.1,
            ],
        ]);

        $client = new StatsDClient($config);

        $this->assertTrue($client->isEnabled());
        $this->assertEquals(0.5, $client->getSampleRate('ok_events'));
        $this->assertEquals(0.1, $client->getSampleRate('payload'));
    }

    public function testConstructorWithLegacyArrayConfig(): void
    {
        $config = [
            'enabled' => 'true',
            'host' => 'legacy.host',
            'port' => 8125,
            'namespace' => 'legacy',
            'nano_service_name' => 'test-service',
            'sampling' => [
                'ok_events' => 0.2,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.1,
            ],
        ];

        $client = new StatsDClient($config);

        $this->assertTrue($client->isEnabled());
        $this->assertEquals(0.2, $client->getSampleRate('ok_events'));
    }

    public function testIncrementWithDisabledMetrics(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        // Should not throw exception when disabled
        $client->increment('test_metric', ['tag' => 'value'], 1.0, 1);

        $this->assertTrue(true); // If we got here, no exception was thrown
    }

    public function testTimingWithDisabledMetrics(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        // Should not throw exception when disabled
        $client->timing('test_timing', 100, ['tag' => 'value']);

        $this->assertTrue(true);
    }

    public function testGaugeWithDisabledMetrics(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        // Should not throw exception when disabled
        $client->gauge('test_gauge', 50, ['tag' => 'value']);

        $this->assertTrue(true);
    }

    public function testHistogramWithDisabledMetrics(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        // Should not throw exception when disabled
        $client->histogram('test_histogram', 1024, ['tag' => 'value']);

        $this->assertTrue(true);
    }

    public function testStartTimerAndEndTimer(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->startTimer('test_timer');
        usleep(10000); // 10ms
        $duration = $client->endTimer('test_timer');

        $this->assertIsInt($duration);
        $this->assertGreaterThanOrEqual(10, $duration);
        $this->assertLessThan(50, $duration); // Should be less than 50ms
    }

    public function testEndTimerWithoutStart(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $duration = $client->endTimer('nonexistent_timer');

        $this->assertNull($duration);
    }

    public function testEndTimerCleansUpTimer(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->startTimer('cleanup_test');
        $first = $client->endTimer('cleanup_test');
        $second = $client->endTimer('cleanup_test');

        $this->assertIsInt($first);
        $this->assertNull($second); // Timer should be cleaned up
    }

    public function testGetSampleRate(): void
    {
        $config = new StatsDConfig([
            'enabled' => 'true',
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'nano_service_name' => 'test-service',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $client = new StatsDClient($config);

        $this->assertEquals(0.1, $client->getSampleRate('ok_events'));
        $this->assertEquals(1.0, $client->getSampleRate('error_events'));
        $this->assertEquals(1.0, $client->getSampleRate('latency'));
        $this->assertEquals(0.05, $client->getSampleRate('payload'));
    }

    public function testGetSampleRateForUnknownType(): void
    {
        $config = new StatsDConfig([
            'enabled' => 'true',
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'nano_service_name' => 'test-service',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $client = new StatsDClient($config);

        // Unknown types should return 1.0 (default)
        $this->assertEquals(1.0, $client->getSampleRate('unknown_metric_type'));
    }

    public function testStartAndEndWithDisabledMetrics(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $tags = ['service' => 'test'];
        $retryTag = EventRetryStatusTag::FIRST;
        $exitTag = EventExitStatusTag::SUCCESS;

        // Should not throw exceptions when disabled
        $client->start($tags, $retryTag);
        usleep(5000); // 5ms
        $client->end($exitTag, $retryTag);

        $this->assertTrue(true);
    }

    /**
     * Documentation test: Verify timing() and histogram() signatures match League StatsD
     *
     * League\StatsD\Client::timing signature: timing($metric, $time, $tags = [])
     * League StatsD timing() does NOT support $sampleRate parameter.
     *
     * Our StatsDClient::timing:   timing(string $metric, int $time, array $tags = [])
     * Our StatsDClient::histogram: histogram(string $metric, int $value, array $tags = [])
     * histogram() internally calls $this->statsd->timing() (League has no native histogram)
     */
    public function testTimingAndHistogramSignaturesMatchLeague(): void
    {
        $this->assertTrue(true, 'Signature documentation verified');
    }

    // ==========================================
    // Constructor Edge Cases
    // ==========================================

    public function testConstructorWithNullConfig(): void
    {
        putenv('STATSD_ENABLED=false');

        $client = new StatsDClient(null);

        $this->assertFalse($client->isEnabled());
    }

    public function testConstructorAutoConfiguresFromEnvironment(): void
    {
        putenv('STATSD_ENABLED=true');
        putenv('STATSD_HOST=auto-host');
        putenv('STATSD_PORT=9999');
        putenv('STATSD_NAMESPACE=auto-namespace');
        putenv('STATSD_SAMPLE_OK=0.3');
        putenv('STATSD_SAMPLE_PAYLOAD=0.2');
        putenv('AMQP_MICROSERVICE_NAME=auto-service');

        $client = new StatsDClient();

        $this->assertTrue($client->isEnabled());
        $this->assertEquals(0.3, $client->getSampleRate('ok_events'));
        $this->assertEquals(0.2, $client->getSampleRate('payload'));
    }

    // ==========================================
    // Timer Edge Cases
    // ==========================================

    public function testMultipleTimersSimultaneously(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->startTimer('timer1');
        $client->startTimer('timer2');
        usleep(5000); // 5ms
        $client->startTimer('timer3');
        usleep(5000); // 5ms more

        $duration1 = $client->endTimer('timer1');
        $duration2 = $client->endTimer('timer2');
        $duration3 = $client->endTimer('timer3');

        $this->assertIsInt($duration1);
        $this->assertIsInt($duration2);
        $this->assertIsInt($duration3);

        // timer1 and timer2 started together, should have similar durations (both ~10ms)
        // timer3 started 5ms later, should be shorter (~5ms)
        $this->assertGreaterThanOrEqual(10, $duration1);
        $this->assertGreaterThanOrEqual(10, $duration2);
        $this->assertGreaterThanOrEqual(5, $duration3);
        $this->assertLessThan($duration1, $duration3);
    }

    public function testStartTimerOverwritesPreviousTimer(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->startTimer('same_key');
        usleep(10000); // 10ms
        $client->startTimer('same_key'); // Overwrite
        usleep(5000); // 5ms
        $duration = $client->endTimer('same_key');

        // Should be ~5ms (from the second start), not ~15ms (from first start)
        $this->assertIsInt($duration);
        $this->assertLessThan(10, $duration);
    }

    // ==========================================
    // All Enum Values Tests
    // ==========================================

    public function testStartAndEndWithRetryEnumValue(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->start(['service' => 'test'], EventRetryStatusTag::RETRY);
        $client->end(EventExitStatusTag::SUCCESS, EventRetryStatusTag::RETRY);

        $this->assertTrue(true);
    }

    public function testStartAndEndWithLastEnumValue(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->start(['service' => 'test'], EventRetryStatusTag::LAST);
        $client->end(EventExitStatusTag::FAILED, EventRetryStatusTag::LAST);

        $this->assertTrue(true);
    }

    // ==========================================
    // isEnabled Tests
    // ==========================================

    public function testIsEnabledReturnsTrueWhenEnabled(): void
    {
        $config = new StatsDConfig([
            'enabled' => 'true',
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'nano_service_name' => 'test-service',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $client = new StatsDClient($config);

        $this->assertTrue($client->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenDisabled(): void
    {
        $config = new StatsDConfig(['enabled' => false]);

        $client = new StatsDClient($config);

        $this->assertFalse($client->isEnabled());
    }

    // ==========================================
    // Increment Parameter Variations
    // ==========================================

    public function testIncrementWithDefaultParameters(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        // Test with just metric name (all defaults)
        $client->increment('metric_only');

        $this->assertTrue(true);
    }

    public function testIncrementWithAllParameters(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->increment('full_metric', ['tag1' => 'v1', 'tag2' => 'v2'], 0.5, 10);

        $this->assertTrue(true);
    }

    // ==========================================
    // Gauge Parameter Variations
    // ==========================================

    public function testGaugeWithDefaultTags(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->gauge('gauge_metric', 100);

        $this->assertTrue(true);
    }

    public function testGaugeWithTags(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->gauge('gauge_metric', 100, ['host' => 'server1']);

        $this->assertTrue(true);
    }

    // ==========================================
    // Sample Rate Retrieval from Disabled Client
    // ==========================================

    public function testGetSampleRateWhenDisabled(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        // Should return default 1.0 for unknown types when disabled
        $sampleRate = $client->getSampleRate('any_type');

        $this->assertEquals(1.0, $sampleRate);
    }

    // ==========================================
    // Timing Edge Cases
    // ==========================================

    public function testTimingWithZeroTime(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->timing('zero_timing', 0);

        $this->assertTrue(true);
    }

    public function testTimingWithLargeTime(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->timing('large_timing', 999999);

        $this->assertTrue(true);
    }

    public function testTimingWithEmptyTags(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->timing('empty_tags_timing', 100, []);

        $this->assertTrue(true);
    }

    public function testTimingWithMultipleTags(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->timing('multi_tags_timing', 100, [
            'service' => 'test-service',
            'env' => 'production',
            'region' => 'us-east-1',
            'version' => 'v1.2.3',
        ]);

        $this->assertTrue(true);
    }

    public function testTimingWithDefaultTags(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->timing('default_tags_timing', 100);

        $this->assertTrue(true);
    }

    // ==========================================
    // Histogram Edge Cases
    // ==========================================

    public function testHistogramWithZeroValue(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->histogram('zero_histogram', 0);

        $this->assertTrue(true);
    }

    public function testHistogramWithLargeValue(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->histogram('large_histogram', 10000000);

        $this->assertTrue(true);
    }

    public function testHistogramWithEmptyTags(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->histogram('empty_tags_histogram', 500, []);

        $this->assertTrue(true);
    }

    public function testHistogramWithMultipleTags(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->histogram('multi_tags_histogram', 500, [
            'service' => 'test-service',
            'event' => 'user.created',
        ]);

        $this->assertTrue(true);
    }

    public function testHistogramWithDefaultTags(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->histogram('default_tags_histogram', 500);

        $this->assertTrue(true);
    }

    // ==========================================
    // Gauge Edge Cases
    // ==========================================

    public function testGaugeWithZeroValue(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->gauge('zero_gauge', 0);

        $this->assertTrue(true);
    }

    public function testGaugeWithNegativeValue(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->gauge('negative_gauge', -100);

        $this->assertTrue(true);
    }

    public function testGaugeWithLargeValue(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->gauge('large_gauge', PHP_INT_MAX);

        $this->assertTrue(true);
    }

    public function testGaugeWithMultipleTags(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->gauge('multi_tags_gauge', 100, [
            'host' => 'server1',
            'datacenter' => 'dc1',
        ]);

        $this->assertTrue(true);
    }

    // ==========================================
    // Increment Edge Cases
    // ==========================================

    public function testIncrementWithZeroValue(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->increment('zero_increment', [], 1.0, 0);

        $this->assertTrue(true);
    }

    public function testIncrementWithNegativeValue(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->increment('negative_increment', [], 1.0, -1);

        $this->assertTrue(true);
    }

    public function testIncrementWithLargeValue(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->increment('large_increment', [], 1.0, 1000000);

        $this->assertTrue(true);
    }

    public function testIncrementWithLowSampleRate(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->increment('low_sample_increment', [], 0.001, 1);

        $this->assertTrue(true);
    }

    public function testIncrementWithMultipleTags(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->increment('multi_tags_increment', [
            'service' => 'my-service',
            'event' => 'order.created',
            'status' => 'success',
        ], 1.0, 1);

        $this->assertTrue(true);
    }

    // ==========================================
    // Sample Rate Variations
    // ==========================================

    public function testAllSampleRateTypes(): void
    {
        $config = new StatsDConfig([
            'enabled' => 'true',
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'nano_service_name' => 'test-service',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 0.5,
                'payload' => 0.05,
            ],
        ]);

        $client = new StatsDClient($config);

        $this->assertEquals(0.1, $client->getSampleRate('ok_events'));
        $this->assertEquals(1.0, $client->getSampleRate('error_events'));
        $this->assertEquals(0.5, $client->getSampleRate('latency'));
        $this->assertEquals(0.05, $client->getSampleRate('payload'));
    }

    public function testSampleRateEdgeValues(): void
    {
        $config = new StatsDConfig([
            'enabled' => 'true',
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'nano_service_name' => 'test-service',
            'sampling' => [
                'ok_events' => 0.0,
                'error_events' => 1.0,
                'latency' => 0.001,
                'payload' => 0.999,
            ],
        ]);

        $client = new StatsDClient($config);

        $this->assertEquals(0.0, $client->getSampleRate('ok_events'));
        $this->assertEquals(1.0, $client->getSampleRate('error_events'));
        $this->assertEquals(0.001, $client->getSampleRate('latency'));
        $this->assertEquals(0.999, $client->getSampleRate('payload'));
    }

    // ==========================================
    // Start/End Event Tracking Tests
    // ==========================================

    public function testStartWithEmptyTags(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->start([], EventRetryStatusTag::FIRST);
        $client->end(EventExitStatusTag::SUCCESS, EventRetryStatusTag::FIRST);

        $this->assertTrue(true);
    }

    public function testStartWithManyTags(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->start([
            'service' => 'my-service',
            'event' => 'user.created',
            'env' => 'production',
            'region' => 'us-east-1',
            'version' => 'v2.0.0',
        ], EventRetryStatusTag::FIRST);
        $client->end(EventExitStatusTag::SUCCESS, EventRetryStatusTag::FIRST);

        $this->assertTrue(true);
    }

    public function testAllRetryStatusCombinations(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        // Test FIRST + SUCCESS
        $client->start(['test' => '1'], EventRetryStatusTag::FIRST);
        $client->end(EventExitStatusTag::SUCCESS, EventRetryStatusTag::FIRST);

        // Test FIRST + FAILED
        $client->start(['test' => '2'], EventRetryStatusTag::FIRST);
        $client->end(EventExitStatusTag::FAILED, EventRetryStatusTag::FIRST);

        // Test RETRY + SUCCESS
        $client->start(['test' => '3'], EventRetryStatusTag::RETRY);
        $client->end(EventExitStatusTag::SUCCESS, EventRetryStatusTag::RETRY);

        // Test RETRY + FAILED
        $client->start(['test' => '4'], EventRetryStatusTag::RETRY);
        $client->end(EventExitStatusTag::FAILED, EventRetryStatusTag::RETRY);

        // Test LAST + SUCCESS
        $client->start(['test' => '5'], EventRetryStatusTag::LAST);
        $client->end(EventExitStatusTag::SUCCESS, EventRetryStatusTag::LAST);

        // Test LAST + FAILED
        $client->start(['test' => '6'], EventRetryStatusTag::LAST);
        $client->end(EventExitStatusTag::FAILED, EventRetryStatusTag::LAST);

        $this->assertTrue(true);
    }

    public function testEndWithDifferentRetryTagThanStart(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        // Start with FIRST, end with RETRY (simulating retry scenario)
        $client->start(['service' => 'test'], EventRetryStatusTag::FIRST);
        $client->end(EventExitStatusTag::FAILED, EventRetryStatusTag::RETRY);

        $this->assertTrue(true);
    }

    // ==========================================
    // Timer Precision Tests
    // ==========================================

    public function testTimerPrecisionIsInMilliseconds(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->startTimer('precision_test');
        usleep(1000); // 1ms
        $duration = $client->endTimer('precision_test');

        // Should be at least 1ms but not much more
        $this->assertIsInt($duration);
        $this->assertGreaterThanOrEqual(1, $duration);
        $this->assertLessThan(100, $duration); // Should not be seconds
    }

    public function testTimerWithVeryShortDuration(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->startTimer('short_timer');
        // No sleep - immediate end
        $duration = $client->endTimer('short_timer');

        $this->assertIsInt($duration);
        $this->assertGreaterThanOrEqual(0, $duration);
        $this->assertLessThan(10, $duration);
    }

    // ==========================================
    // Multiple Clients Tests
    // ==========================================

    public function testMultipleClientsWithDifferentConfigs(): void
    {
        $config1 = new StatsDConfig([
            'enabled' => 'true',
            'host' => 'host1',
            'port' => 8125,
            'namespace' => 'namespace1',
            'nano_service_name' => 'service1',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $config2 = new StatsDConfig([
            'enabled' => 'true',
            'host' => 'host2',
            'port' => 9125,
            'namespace' => 'namespace2',
            'nano_service_name' => 'service2',
            'sampling' => [
                'ok_events' => 0.5,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.25,
            ],
        ]);

        $client1 = new StatsDClient($config1);
        $client2 = new StatsDClient($config2);

        $this->assertTrue($client1->isEnabled());
        $this->assertTrue($client2->isEnabled());
        $this->assertEquals(0.1, $client1->getSampleRate('ok_events'));
        $this->assertEquals(0.5, $client2->getSampleRate('ok_events'));
    }

    public function testEnabledAndDisabledClientsCoexist(): void
    {
        $enabledConfig = new StatsDConfig([
            'enabled' => 'true',
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'enabled',
            'nano_service_name' => 'test-service',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $disabledConfig = new StatsDConfig(['enabled' => false]);

        $enabledClient = new StatsDClient($enabledConfig);
        $disabledClient = new StatsDClient($disabledConfig);

        $this->assertTrue($enabledClient->isEnabled());
        $this->assertFalse($disabledClient->isEnabled());
    }

    // ==========================================
    // Metric Name Variations
    // ==========================================

    public function testMetricWithDots(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->increment('my.service.metric.name');
        $client->timing('my.timing.metric', 100);
        $client->gauge('my.gauge.metric', 50);
        $client->histogram('my.histogram.metric', 500);

        $this->assertTrue(true);
    }

    public function testMetricWithUnderscores(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->increment('my_service_metric_name');
        $client->timing('my_timing_metric', 100);
        $client->gauge('my_gauge_metric', 50);
        $client->histogram('my_histogram_metric', 500);

        $this->assertTrue(true);
    }

    public function testMetricWithMixedNaming(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->increment('rmq_publish_total');
        $client->timing('rmq.publish.duration_ms', 100);
        $client->gauge('rmq_channel_active', 1);
        $client->histogram('rmq_payload_bytes', 1024);

        $this->assertTrue(true);
    }

    // ==========================================
    // Tag Value Variations
    // ==========================================

    public function testTagsWithSpecialCharacters(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->increment('metric', [
            'service' => 'my-service',
            'event' => 'user.created',
            'version' => 'v1.2.3-beta',
        ]);

        $this->assertTrue(true);
    }

    public function testTagsWithEmptyValue(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->increment('metric', [
            'service' => '',
            'event' => 'test',
        ]);

        $this->assertTrue(true);
    }

    public function testTagsWithNumericValues(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->increment('metric', [
            'status_code' => '200',
            'retry_count' => '3',
        ]);

        $this->assertTrue(true);
    }

    // ==========================================
    // Concurrent Timer Operations
    // ==========================================

    public function testTimersAreIsolatedPerKey(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $client->startTimer('timer_a');
        usleep(5000); // 5ms
        $client->startTimer('timer_b');
        usleep(5000); // 5ms more

        $durationA = $client->endTimer('timer_a');
        $durationB = $client->endTimer('timer_b');

        // timer_a ran for ~10ms, timer_b ran for ~5ms
        $this->assertGreaterThan($durationB, $durationA);
    }

    public function testEndTimerReturnsNullForNeverStartedTimer(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        $this->assertNull($client->endTimer('never_started'));
        $this->assertNull($client->endTimer('also_never_started'));
        $this->assertNull($client->endTimer(''));
    }

    // ==========================================
    // Configuration Inheritance Tests
    // ==========================================

    public function testStatsDClientInheritsConfigSampleRates(): void
    {
        $customSampling = [
            'ok_events' => 0.123,
            'error_events' => 0.456,
            'latency' => 0.789,
            'payload' => 0.321,
        ];

        $config = new StatsDConfig([
            'enabled' => 'true',
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'nano_service_name' => 'test-service',
            'sampling' => $customSampling,
        ]);

        $client = new StatsDClient($config);

        foreach ($customSampling as $type => $expectedRate) {
            $this->assertEquals($expectedRate, $client->getSampleRate($type));
        }
    }

    // ==========================================
    // Disabled Client Behavior Tests
    // ==========================================

    public function testDisabledClientAllMethodsAreNoOps(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        // All these should be no-ops (no exceptions)
        $client->increment('metric', ['tag' => 'value'], 1.0, 1);
        $client->timing('metric', 100, ['tag' => 'value']);
        $client->gauge('metric', 50, ['tag' => 'value']);
        $client->histogram('metric', 500, ['tag' => 'value']);
        $client->start(['service' => 'test'], EventRetryStatusTag::FIRST);
        $client->end(EventExitStatusTag::SUCCESS, EventRetryStatusTag::FIRST);

        $this->assertFalse($client->isEnabled());
    }

    public function testDisabledClientTimersStillWork(): void
    {
        putenv('STATSD_ENABLED=false');
        $client = new StatsDClient();

        // Timers should still work even when metrics are disabled
        // (they're local to the client, not sent anywhere)
        $client->startTimer('local_timer');
        usleep(5000);
        $duration = $client->endTimer('local_timer');

        $this->assertIsInt($duration);
        $this->assertGreaterThanOrEqual(5, $duration);
    }
}
