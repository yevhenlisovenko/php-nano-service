<?php

namespace AlexFN\NanoService\Tests\Unit;

use AlexFN\NanoService\Config\StatsDConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StatsDConfig
 *
 * Tests configuration loading, validation, and environment variable handling.
 * Critical: Validates no-fallback policy (fail-fast on missing required vars).
 */
class StatsDConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clean environment before each test
        $this->cleanupEnv();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->cleanupEnv();
    }

    private function cleanupEnv(): void
    {
        $vars = [
            'STATSD_ENABLED',
            'STATSD_HOST',
            'STATSD_PORT',
            'STATSD_NAMESPACE',
            'STATSD_SAMPLE_OK',
            'STATSD_SAMPLE_PAYLOAD',
        ];

        foreach ($vars as $var) {
            unset($_ENV[$var]);
            putenv($var); // Clear from getenv() as well
        }
    }

    public function testConstructorWithDisabledMetrics(): void
    {
        $_ENV['STATSD_ENABLED'] = 'false';

        $config = new StatsDConfig();

        $this->assertFalse($config->isEnabled());
    }

    public function testConstructorWithMissingEnabledVar(): void
    {
        // When STATSD_ENABLED is not set, metrics should be disabled by default
        $config = new StatsDConfig();

        $this->assertFalse($config->isEnabled());
    }

    public function testConstructorWithEnabledMetrics(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'statsd.example.com';
        $_ENV['STATSD_PORT'] = '8125';
        $_ENV['STATSD_NAMESPACE'] = 'test_namespace';
        $_ENV['STATSD_SAMPLE_OK'] = '0.1';
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.05';

        $config = new StatsDConfig();

        $this->assertTrue($config->isEnabled());
        $this->assertEquals('statsd.example.com', $config->getHost());
        $this->assertEquals(8125, $config->getPort());
        $this->assertEquals('test_namespace', $config->getNamespace());
        $this->assertEquals(0.1, $config->getSampleRate('ok_events'));
        $this->assertEquals(1.0, $config->getSampleRate('error_events'));
        $this->assertEquals(1.0, $config->getSampleRate('latency'));
        $this->assertEquals(0.05, $config->getSampleRate('payload'));
    }

    public function testConstructorWithArrayConfigOverridesEnv(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'env.host';
        $_ENV['STATSD_PORT'] = '8125';
        $_ENV['STATSD_NAMESPACE'] = 'env_namespace';
        $_ENV['STATSD_SAMPLE_OK'] = '0.1';
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.05';

        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'override.host',
            'port' => 9125,
            'namespace' => 'override_namespace',
            'sampling' => [
                'ok_events' => 0.5,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.2,
            ],
        ]);

        $this->assertTrue($config->isEnabled());
        $this->assertEquals('override.host', $config->getHost());
        $this->assertEquals(9125, $config->getPort());
        $this->assertEquals('override_namespace', $config->getNamespace());
        $this->assertEquals(0.5, $config->getSampleRate('ok_events'));
        $this->assertEquals(0.2, $config->getSampleRate('payload'));
    }

    public function testConstructorThrowsOnMissingHost(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        // Missing STATSD_HOST
        $_ENV['STATSD_PORT'] = '8125';
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = '0.1';
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.05';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('STATSD_HOST');

        new StatsDConfig();
    }

    public function testConstructorThrowsOnMissingPort(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        // Missing STATSD_PORT
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = '0.1';
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.05';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('STATSD_PORT');

        new StatsDConfig();
    }

    public function testConstructorThrowsOnMissingNamespace(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        $_ENV['STATSD_PORT'] = '8125';
        // Missing STATSD_NAMESPACE
        $_ENV['STATSD_SAMPLE_OK'] = '0.1';
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.05';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('STATSD_NAMESPACE');

        new StatsDConfig();
    }

    public function testConstructorThrowsOnMissingSampleOk(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        $_ENV['STATSD_PORT'] = '8125';
        $_ENV['STATSD_NAMESPACE'] = 'test';
        // Missing STATSD_SAMPLE_OK
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.05';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('STATSD_SAMPLE_OK');

        new StatsDConfig();
    }

    public function testConstructorThrowsOnMissingSamplePayload(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        $_ENV['STATSD_PORT'] = '8125';
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = '0.1';
        // Missing STATSD_SAMPLE_PAYLOAD

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('STATSD_SAMPLE_PAYLOAD');

        new StatsDConfig();
    }

    public function testConstructorThrowsOnMultipleMissingVars(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        // Missing all required vars

        $this->expectException(\RuntimeException::class);
        // All 5 required vars should be listed in the error message
        $this->expectExceptionMessageMatches('/STATSD_HOST.*STATSD_PORT.*STATSD_NAMESPACE.*STATSD_SAMPLE_OK.*STATSD_SAMPLE_PAYLOAD/');

        new StatsDConfig();
    }

    public function testEnabledOnlyTrueForStringTrue(): void
    {
        $testCases = [
            ['value' => 'true', 'expected' => true],
            ['value' => 'false', 'expected' => false],
            ['value' => '1', 'expected' => false],
            ['value' => '0', 'expected' => false],
            ['value' => 'yes', 'expected' => false],
            ['value' => 'no', 'expected' => false],
            ['value' => '', 'expected' => false],
        ];

        foreach ($testCases as $testCase) {
            $this->cleanupEnv();
            $_ENV['STATSD_ENABLED'] = $testCase['value'];

            // For the 'true' case, we need all required env vars
            if ($testCase['expected']) {
                $_ENV['STATSD_HOST'] = 'localhost';
                $_ENV['STATSD_PORT'] = '8125';
                $_ENV['STATSD_NAMESPACE'] = 'test';
                $_ENV['STATSD_SAMPLE_OK'] = '0.1';
                $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.05';
            }

            $config = new StatsDConfig();

            $this->assertEquals(
                $testCase['expected'],
                $config->isEnabled(),
                "STATSD_ENABLED='{$testCase['value']}' should result in " . ($testCase['expected'] ? 'enabled' : 'disabled')
            );
        }
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'metrics.host',
            'port' => 9125,
            'namespace' => 'my_namespace',
            'sampling' => [
                'ok_events' => 0.5,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.1,
            ],
        ]);

        $array = $config->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('host', $array);
        $this->assertArrayHasKey('port', $array);
        $this->assertArrayHasKey('namespace', $array);
        $this->assertEquals('metrics.host', $array['host']);
        $this->assertEquals(9125, $array['port']);
        $this->assertEquals('my_namespace', $array['namespace']);
    }

    public function testGetSampleRateForUnknownType(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        // Unknown types should return 1.0 (always sample)
        $this->assertEquals(1.0, $config->getSampleRate('unknown_type'));
    }

    public function testSamplingRatesAreFloats(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        $_ENV['STATSD_PORT'] = '8125';
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = '0.1';
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.05';

        $config = new StatsDConfig();

        $this->assertIsFloat($config->getSampleRate('ok_events'));
        $this->assertIsFloat($config->getSampleRate('error_events'));
        $this->assertIsFloat($config->getSampleRate('latency'));
        $this->assertIsFloat($config->getSampleRate('payload'));
    }

    public function testPortIsConvertedToInt(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        $_ENV['STATSD_PORT'] = '8125'; // String from env
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = '0.1';
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.05';

        $config = new StatsDConfig();

        $this->assertIsInt($config->getPort());
        $this->assertEquals(8125, $config->getPort());
    }

    public function testDisabledMetricsDoNotRequireEnvVars(): void
    {
        $_ENV['STATSD_ENABLED'] = 'false';
        // No other env vars set

        // Should NOT throw exception - disabled metrics don't need config
        $config = new StatsDConfig();

        $this->assertFalse($config->isEnabled());
    }

    public function testArrayConfigCanDisableMetrics(): void
    {
        // Even with env vars set, array config can override
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        $_ENV['STATSD_PORT'] = '8125';
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = '0.1';
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.05';

        $config = new StatsDConfig(['enabled' => false]);

        $this->assertFalse($config->isEnabled());
    }

    /**
     * Critical test: Validates no-fallback policy
     *
     * This test ensures that the configuration follows the project's
     * critical rule: NEVER use fallback values for environment variables.
     * All required vars must be explicitly set or fail-fast with clear errors.
     */
    public function testNoFallbackValuesPolicy(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        // Intentionally missing all required vars

        try {
            new StatsDConfig();
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            // Expected behavior: clear error message listing missing vars
            $this->assertStringContainsString('missing required environment variables', strtolower($e->getMessage()));
        }
    }

    // ==========================================
    // Array Config with Full Config Skips Validation
    // ==========================================

    public function testFullArrayConfigSkipsEnvValidation(): void
    {
        // No env vars set at all
        $this->cleanupEnv();

        // Full config provided via array should work without env vars
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'array-host',
            'port' => 1234,
            'namespace' => 'array-namespace',
            'sampling' => [
                'ok_events' => 0.5,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.25,
            ],
        ]);

        $this->assertTrue($config->isEnabled());
        $this->assertEquals('array-host', $config->getHost());
        $this->assertEquals(1234, $config->getPort());
        $this->assertEquals('array-namespace', $config->getNamespace());
        $this->assertEquals(0.5, $config->getSampleRate('ok_events'));
        $this->assertEquals(0.25, $config->getSampleRate('payload'));
    }

    public function testPartialArrayConfigStillValidatesEnv(): void
    {
        // Only partial config - missing sampling
        $_ENV['STATSD_ENABLED'] = 'true';

        $this->expectException(\RuntimeException::class);

        new StatsDConfig([
            'enabled' => true,
            'host' => 'partial-host',
            'port' => 1234,
            'namespace' => 'partial-namespace',
            // Missing 'sampling' - should trigger env validation
        ]);
    }

    // ==========================================
    // Getters When Disabled
    // ==========================================

    public function testGetSampleRateWhenDisabled(): void
    {
        $config = new StatsDConfig(['enabled' => false]);

        // Should return default 1.0 even when disabled
        $this->assertEquals(1.0, $config->getSampleRate('ok_events'));
        $this->assertEquals(1.0, $config->getSampleRate('unknown'));
    }

    // ==========================================
    // Mixed Config Source Tests
    // ==========================================

    public function testArrayConfigOverridesPartialEnvVars(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'env-host';
        $_ENV['STATSD_PORT'] = '8125';
        $_ENV['STATSD_NAMESPACE'] = 'env-namespace';
        $_ENV['STATSD_SAMPLE_OK'] = '0.1';
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.05';

        // Array config should override just the specified values
        $config = new StatsDConfig([
            'host' => 'override-host',
            'port' => 9999,
        ]);

        $this->assertTrue($config->isEnabled());
        $this->assertEquals('override-host', $config->getHost());
        $this->assertEquals(9999, $config->getPort());
        $this->assertEquals('env-namespace', $config->getNamespace()); // From env
        $this->assertEquals(0.1, $config->getSampleRate('ok_events')); // From env
    }

    // ==========================================
    // Sample Rate Edge Cases
    // ==========================================

    public function testSampleRateZero(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.0,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.0,
            ],
        ]);

        $this->assertEquals(0.0, $config->getSampleRate('ok_events'));
        $this->assertEquals(0.0, $config->getSampleRate('payload'));
    }

    public function testSampleRateOne(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 1.0,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 1.0,
            ],
        ]);

        $this->assertEquals(1.0, $config->getSampleRate('ok_events'));
        $this->assertEquals(1.0, $config->getSampleRate('payload'));
    }

    public function testSampleRateVerySmall(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.001,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.0001,
            ],
        ]);

        $this->assertEquals(0.001, $config->getSampleRate('ok_events'));
        $this->assertEquals(0.0001, $config->getSampleRate('payload'));
    }

    public function testSampleRateCloseToOne(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.999,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.9999,
            ],
        ]);

        $this->assertEquals(0.999, $config->getSampleRate('ok_events'));
        $this->assertEquals(0.9999, $config->getSampleRate('payload'));
    }

    // ==========================================
    // Port Variations
    // ==========================================

    public function testPortAsString(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        $_ENV['STATSD_PORT'] = '8125';
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = '0.1';
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.05';

        $config = new StatsDConfig();

        $this->assertIsInt($config->getPort());
        $this->assertEquals(8125, $config->getPort());
    }

    public function testPortDifferentValues(): void
    {
        $testPorts = [1, 80, 443, 8125, 9125, 65535];

        foreach ($testPorts as $port) {
            $config = new StatsDConfig([
                'enabled' => true,
                'host' => 'localhost',
                'port' => $port,
                'namespace' => 'test',
                'sampling' => [
                    'ok_events' => 0.1,
                    'error_events' => 1.0,
                    'latency' => 1.0,
                    'payload' => 0.05,
                ],
            ]);

            $this->assertEquals($port, $config->getPort());
        }
    }

    // ==========================================
    // Namespace Variations
    // ==========================================

    public function testNamespaceWithDots(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'my.service.namespace',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals('my.service.namespace', $config->getNamespace());
    }

    public function testNamespaceWithUnderscores(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'my_service_namespace',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals('my_service_namespace', $config->getNamespace());
    }

    public function testNamespaceWithHyphens(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'my-service-namespace',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals('my-service-namespace', $config->getNamespace());
    }

    // ==========================================
    // Host Variations
    // ==========================================

    public function testHostAsLocalhost(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals('localhost', $config->getHost());
    }

    public function testHostAsIpAddress(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => '192.168.1.100',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals('192.168.1.100', $config->getHost());
    }

    public function testHostAsFqdn(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'statsd.metrics.example.com',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals('statsd.metrics.example.com', $config->getHost());
    }

    public function testHostAsKubernetesService(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'statsd-exporter.monitoring.svc.cluster.local',
            'port' => 9125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals('statsd-exporter.monitoring.svc.cluster.local', $config->getHost());
    }

    // ==========================================
    // toArray Tests
    // ==========================================

    public function testToArrayContainsAllRequiredKeys(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'test-host',
            'port' => 1234,
            'namespace' => 'test-namespace',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $array = $config->toArray();

        $this->assertArrayHasKey('host', $array);
        $this->assertArrayHasKey('port', $array);
        $this->assertArrayHasKey('namespace', $array);
        $this->assertCount(3, $array);
    }

    public function testToArrayValuesMatchGetters(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'test-host',
            'port' => 1234,
            'namespace' => 'test-namespace',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $array = $config->toArray();

        $this->assertEquals($config->getHost(), $array['host']);
        $this->assertEquals($config->getPort(), $array['port']);
        $this->assertEquals($config->getNamespace(), $array['namespace']);
    }

    // ==========================================
    // Enabled Edge Cases
    // ==========================================

    public function testEnabledWithBooleanTrue(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertTrue($config->isEnabled());
    }

    public function testEnabledWithBooleanFalse(): void
    {
        $config = new StatsDConfig(['enabled' => false]);

        $this->assertFalse($config->isEnabled());
    }

    public function testEnabledDefaultsToFalse(): void
    {
        // No STATSD_ENABLED env var set
        $this->cleanupEnv();

        $config = new StatsDConfig();

        $this->assertFalse($config->isEnabled());
    }

    // ==========================================
    // Environment Variable from String Tests
    // ==========================================

    public function testSampleRatesFromEnvAreFloats(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        $_ENV['STATSD_PORT'] = '8125';
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = '0.123';
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.456';

        $config = new StatsDConfig();

        $this->assertIsFloat($config->getSampleRate('ok_events'));
        $this->assertIsFloat($config->getSampleRate('payload'));
        $this->assertEquals(0.123, $config->getSampleRate('ok_events'));
        $this->assertEquals(0.456, $config->getSampleRate('payload'));
    }

    public function testErrorEventsAlwaysFullSampleRate(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.01,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.01,
            ],
        ]);

        // Error events should always be sampled at 100%
        $this->assertEquals(1.0, $config->getSampleRate('error_events'));
    }

    public function testLatencyAlwaysFullSampleRate(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.01,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.01,
            ],
        ]);

        // Latency should always be sampled at 100% for accuracy
        $this->assertEquals(1.0, $config->getSampleRate('latency'));
    }

    // ==========================================
    // Weird/Edge Case Values - STATSD_ENABLED
    // ==========================================

    public function testEnabledWithUppercaseTrue(): void
    {
        $_ENV['STATSD_ENABLED'] = 'TRUE';
        // Uppercase should NOT enable (strict comparison to 'true')

        $config = new StatsDConfig();

        $this->assertFalse($config->isEnabled());
    }

    public function testEnabledWithMixedCaseTrue(): void
    {
        $_ENV['STATSD_ENABLED'] = 'True';

        $config = new StatsDConfig();

        $this->assertFalse($config->isEnabled());
    }

    public function testEnabledWithWhitespace(): void
    {
        $_ENV['STATSD_ENABLED'] = ' true ';

        $config = new StatsDConfig();

        // Whitespace around 'true' should NOT enable
        $this->assertFalse($config->isEnabled());
    }

    public function testEnabledWithNewline(): void
    {
        $_ENV['STATSD_ENABLED'] = "true\n";

        $config = new StatsDConfig();

        $this->assertFalse($config->isEnabled());
    }

    public function testEnabledWithTab(): void
    {
        $_ENV['STATSD_ENABLED'] = "\ttrue";

        $config = new StatsDConfig();

        $this->assertFalse($config->isEnabled());
    }

    public function testEnabledWithOnValue(): void
    {
        $_ENV['STATSD_ENABLED'] = 'on';

        $config = new StatsDConfig();

        $this->assertFalse($config->isEnabled());
    }

    public function testEnabledWithEnabledValue(): void
    {
        $_ENV['STATSD_ENABLED'] = 'enabled';

        $config = new StatsDConfig();

        $this->assertFalse($config->isEnabled());
    }

    // ==========================================
    // Weird/Edge Case Values - Sample Rates
    // ==========================================

    public function testSampleRateNegativeValue(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => -0.5,  // Negative - weird but allowed
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => -1.0,
            ],
        ]);

        // PHP will accept negative floats - no validation
        $this->assertEquals(-0.5, $config->getSampleRate('ok_events'));
        $this->assertEquals(-1.0, $config->getSampleRate('payload'));
    }

    public function testSampleRateGreaterThanOne(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 1.5,  // Greater than 1 - weird but allowed
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 100.0,
            ],
        ]);

        $this->assertEquals(1.5, $config->getSampleRate('ok_events'));
        $this->assertEquals(100.0, $config->getSampleRate('payload'));
    }

    public function testSampleRateAsIntegerZero(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0,  // Integer zero
                'error_events' => 1,  // Integer one
                'latency' => 1,
                'payload' => 0,
            ],
        ]);

        // Integers are stored as-is and can be compared to floats due to PHP type juggling
        $this->assertEquals(0, $config->getSampleRate('ok_events'));
        $this->assertEquals(0, $config->getSampleRate('payload'));
    }

    public function testSampleRateFromEnvAsNonNumericString(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        $_ENV['STATSD_PORT'] = '8125';
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = 'abc';  // Non-numeric
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = 'xyz';

        $config = new StatsDConfig();

        // (float)'abc' === 0.0 in PHP
        $this->assertEquals(0.0, $config->getSampleRate('ok_events'));
        $this->assertEquals(0.0, $config->getSampleRate('payload'));
    }

    public function testSampleRateFromEnvWithLeadingZeros(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        $_ENV['STATSD_PORT'] = '8125';
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = '00.100';  // Leading zeros
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '000.050';

        $config = new StatsDConfig();

        $this->assertEquals(0.1, $config->getSampleRate('ok_events'));
        $this->assertEquals(0.05, $config->getSampleRate('payload'));
    }

    public function testSampleRateFromEnvWithScientificNotation(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        $_ENV['STATSD_PORT'] = '8125';
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = '1e-2';  // Scientific notation = 0.01
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '5E-3';  // = 0.005

        $config = new StatsDConfig();

        $this->assertEquals(0.01, $config->getSampleRate('ok_events'));
        $this->assertEquals(0.005, $config->getSampleRate('payload'));
    }

    public function testSampleRateFromEnvWithPlusSign(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        $_ENV['STATSD_PORT'] = '8125';
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = '+0.5';  // Plus sign
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '+0.1';

        $config = new StatsDConfig();

        $this->assertEquals(0.5, $config->getSampleRate('ok_events'));
        $this->assertEquals(0.1, $config->getSampleRate('payload'));
    }

    public function testSampleRateFromEnvWithNegativeSign(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        $_ENV['STATSD_PORT'] = '8125';
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = '-0.5';  // Negative
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '-0.1';

        $config = new StatsDConfig();

        // PHP will cast to -0.5
        $this->assertEquals(-0.5, $config->getSampleRate('ok_events'));
        $this->assertEquals(-0.1, $config->getSampleRate('payload'));
    }

    // ==========================================
    // Weird/Edge Case Values - Port
    // ==========================================

    public function testPortZero(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 0,  // Port 0
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals(0, $config->getPort());
    }

    public function testPortNegative(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => -1,  // Negative port
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals(-1, $config->getPort());
    }

    public function testPortVeryLarge(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 99999,  // Above valid port range
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals(99999, $config->getPort());
    }

    public function testPortFromEnvNonNumeric(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        $_ENV['STATSD_PORT'] = 'abc';  // Non-numeric
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = '0.1';
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.05';

        $config = new StatsDConfig();

        // (int)'abc' === 0 in PHP
        $this->assertEquals(0, $config->getPort());
    }

    public function testPortFromEnvWithDecimal(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        $_ENV['STATSD_PORT'] = '8125.7';  // Decimal
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = '0.1';
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.05';

        $config = new StatsDConfig();

        // (int)'8125.7' === 8125 in PHP
        $this->assertEquals(8125, $config->getPort());
    }

    public function testPortFromEnvWithLeadingText(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        $_ENV['STATSD_PORT'] = 'port8125';  // Text before number
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = '0.1';
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.05';

        $config = new StatsDConfig();

        // (int)'port8125' === 0 in PHP (leading non-numeric)
        $this->assertEquals(0, $config->getPort());
    }

    public function testPortFromEnvWithTrailingText(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        $_ENV['STATSD_PORT'] = '8125port';  // Number followed by text
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = '0.1';
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.05';

        $config = new StatsDConfig();

        // (int)'8125port' === 8125 in PHP (trailing non-numeric ignored)
        $this->assertEquals(8125, $config->getPort());
    }

    public function testPortFromEnvWithWhitespace(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        $_ENV['STATSD_PORT'] = ' 8125 ';  // Whitespace around
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = '0.1';
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.05';

        $config = new StatsDConfig();

        // (int)' 8125 ' === 8125 in PHP (whitespace trimmed)
        $this->assertEquals(8125, $config->getPort());
    }

    // ==========================================
    // Weird/Edge Case Values - Host
    // ==========================================

    public function testHostEmptyStringViaArray(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => '',  // Empty string
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        // Empty string is allowed via array config
        $this->assertEquals('', $config->getHost());
    }

    public function testHostWithWhitespaceOnly(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => '   ',  // Whitespace only
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals('   ', $config->getHost());
    }

    public function testHostWithSpecialCharacters(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'host!@#$%^&*()',  // Special chars
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals('host!@#$%^&*()', $config->getHost());
    }

    public function testHostWithUnicode(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'хост.example.com',  // Unicode (Cyrillic)
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals('хост.example.com', $config->getHost());
    }

    public function testHostWithNewlines(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => "localhost\ninjected",  // Newline injection attempt
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals("localhost\ninjected", $config->getHost());
    }

    public function testHostWithIPv6(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => '::1',  // IPv6 localhost
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals('::1', $config->getHost());
    }

    public function testHostWithIPv6Full(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => '2001:0db8:85a3:0000:0000:8a2e:0370:7334',  // Full IPv6
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals('2001:0db8:85a3:0000:0000:8a2e:0370:7334', $config->getHost());
    }

    // ==========================================
    // Weird/Edge Case Values - Namespace
    // ==========================================

    public function testNamespaceEmptyStringViaArray(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => '',  // Empty string
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals('', $config->getNamespace());
    }

    public function testNamespaceWithWhitespaceOnly(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => '   ',  // Whitespace only
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals('   ', $config->getNamespace());
    }

    public function testNamespaceWithSpecialCharacters(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'ns!@#$%',  // Special chars
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals('ns!@#$%', $config->getNamespace());
    }

    public function testNamespaceVeryLong(): void
    {
        $longNamespace = str_repeat('a', 1000);  // 1000 char namespace

        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => $longNamespace,
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals($longNamespace, $config->getNamespace());
        $this->assertEquals(1000, strlen($config->getNamespace()));
    }

    public function testNamespaceWithUnicode(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'сервис_метрики',  // Cyrillic
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals('сервис_метрики', $config->getNamespace());
    }

    public function testNamespaceWithEmoji(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => '🚀metrics',  // Emoji
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertEquals('🚀metrics', $config->getNamespace());
    }

    // ==========================================
    // Weird/Edge Case Values - Sampling Array Structure
    // ==========================================

    public function testSamplingWithMissingKeys(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                // Missing error_events, latency, payload
            ],
        ]);

        $this->assertEquals(0.1, $config->getSampleRate('ok_events'));
        // Missing keys should return default 1.0
        $this->assertEquals(1.0, $config->getSampleRate('error_events'));
        $this->assertEquals(1.0, $config->getSampleRate('latency'));
        $this->assertEquals(1.0, $config->getSampleRate('payload'));
    }

    public function testSamplingWithExtraKeys(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
                'custom_key' => 0.75,  // Extra key
                'another_key' => 0.25,
            ],
        ]);

        $this->assertEquals(0.75, $config->getSampleRate('custom_key'));
        $this->assertEquals(0.25, $config->getSampleRate('another_key'));
    }

    public function testSamplingEmptyArray(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [],  // Empty sampling array
        ]);

        // All should return default 1.0
        $this->assertEquals(1.0, $config->getSampleRate('ok_events'));
        $this->assertEquals(1.0, $config->getSampleRate('error_events'));
        $this->assertEquals(1.0, $config->getSampleRate('latency'));
        $this->assertEquals(1.0, $config->getSampleRate('payload'));
    }

    // ==========================================
    // Array Config with Null Values
    // ==========================================

    public function testArrayConfigWithNullHost(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'env-host';
        $_ENV['STATSD_PORT'] = '8125';
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = '0.1';
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.05';

        $config = new StatsDConfig([
            'host' => null,  // Null should fallback to env
        ]);

        // null ?? env should use env value
        $this->assertEquals('env-host', $config->getHost());
    }

    public function testArrayConfigWithNullPort(): void
    {
        $_ENV['STATSD_ENABLED'] = 'true';
        $_ENV['STATSD_HOST'] = 'localhost';
        $_ENV['STATSD_PORT'] = '9999';
        $_ENV['STATSD_NAMESPACE'] = 'test';
        $_ENV['STATSD_SAMPLE_OK'] = '0.1';
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.05';

        $config = new StatsDConfig([
            'port' => null,  // Null should fallback to env
        ]);

        $this->assertEquals(9999, $config->getPort());
    }

    // ==========================================
    // Edge Case: Enabled via Array Bool vs String
    // ==========================================

    public function testEnabledViaArrayWithStringTrue(): void
    {
        $config = new StatsDConfig([
            'enabled' => 'true',  // String 'true' in array
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        // String 'true' is truthy in PHP
        $this->assertTrue($config->isEnabled());
    }

    public function testEnabledViaArrayWithStringFalse(): void
    {
        // String 'false' in array config is truthy in PHP (non-empty string)
        // This causes isEnabled() to be true, which triggers env validation
        // To test this behavior, we need to provide full config
        $config = new StatsDConfig([
            'enabled' => 'false',  // String 'false' in array - still truthy!
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        // String 'false' is truthy in PHP (non-empty string)
        // This is a potential gotcha!
        $this->assertTrue($config->isEnabled());
    }

    public function testEnabledViaArrayWithEmptyString(): void
    {
        $config = new StatsDConfig([
            'enabled' => '',  // Empty string - falsy
        ]);

        $this->assertFalse($config->isEnabled());
    }

    public function testEnabledViaArrayWithZeroString(): void
    {
        $config = new StatsDConfig([
            'enabled' => '0',  // String '0' - falsy in PHP
        ]);

        $this->assertFalse($config->isEnabled());
    }

    public function testEnabledViaArrayWithIntegerOne(): void
    {
        $config = new StatsDConfig([
            'enabled' => 1,  // Integer 1 - truthy
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => 0.1,
                'error_events' => 1.0,
                'latency' => 1.0,
                'payload' => 0.05,
            ],
        ]);

        $this->assertTrue($config->isEnabled());
    }

    public function testEnabledViaArrayWithIntegerZero(): void
    {
        $config = new StatsDConfig([
            'enabled' => 0,  // Integer 0 - falsy
        ]);

        $this->assertFalse($config->isEnabled());
    }

    // ==========================================
    // Type Coercion Edge Cases
    // ==========================================

    public function testPortAsFloatInArray(): void
    {
        // Suppress expected deprecation warning in PHP 8.4+
        $previousLevel = error_reporting(E_ALL & ~E_DEPRECATED);

        try {
            $config = new StatsDConfig([
                'enabled' => true,
                'host' => 'localhost',
                'port' => 8125.999,  // Float
                'namespace' => 'test',
                'sampling' => [
                    'ok_events' => 0.1,
                    'error_events' => 1.0,
                    'latency' => 1.0,
                    'payload' => 0.05,
                ],
            ]);

            // Float is implicitly converted to int in PHP 8.4 (with deprecation warning)
            // The decimal part is truncated
            $this->assertEquals(8125, $config->getPort());
        } finally {
            error_reporting($previousLevel);
        }
    }

    public function testSampleRateAsString(): void
    {
        $config = new StatsDConfig([
            'enabled' => true,
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'test',
            'sampling' => [
                'ok_events' => '0.1',  // String
                'error_events' => '1.0',
                'latency' => '1.0',
                'payload' => '0.05',
            ],
        ]);

        // Strings are stored as-is, will work in comparisons due to PHP's type juggling
        $this->assertEquals('0.1', $config->getSampleRate('ok_events'));
    }

    // ==========================================
    // Environment Variable Precedence
    // ==========================================

    public function testGetenvVsEnvArray(): void
    {
        // Set via putenv (getenv)
        putenv('STATSD_ENABLED=true');
        putenv('STATSD_HOST=putenv-host');
        putenv('STATSD_PORT=1111');
        putenv('STATSD_NAMESPACE=putenv-ns');
        putenv('STATSD_SAMPLE_OK=0.11');
        putenv('STATSD_SAMPLE_PAYLOAD=0.22');

        // Set via $_ENV (different values)
        $_ENV['STATSD_ENABLED'] = 'false';
        $_ENV['STATSD_HOST'] = 'env-host';
        $_ENV['STATSD_PORT'] = '2222';
        $_ENV['STATSD_NAMESPACE'] = 'env-ns';
        $_ENV['STATSD_SAMPLE_OK'] = '0.33';
        $_ENV['STATSD_SAMPLE_PAYLOAD'] = '0.44';

        $config = new StatsDConfig();

        // $_ENV takes precedence (checked first)
        $this->assertFalse($config->isEnabled());  // $_ENV['STATSD_ENABLED'] = 'false'

        // Cleanup putenv
        putenv('STATSD_ENABLED');
        putenv('STATSD_HOST');
        putenv('STATSD_PORT');
        putenv('STATSD_NAMESPACE');
        putenv('STATSD_SAMPLE_OK');
        putenv('STATSD_SAMPLE_PAYLOAD');
    }

    public function testOnlyPutenvWhenEnvArrayNotSet(): void
    {
        $this->cleanupEnv();

        // Only set via putenv
        putenv('STATSD_ENABLED=true');
        putenv('STATSD_HOST=putenv-only-host');
        putenv('STATSD_PORT=3333');
        putenv('STATSD_NAMESPACE=putenv-only-ns');
        putenv('STATSD_SAMPLE_OK=0.55');
        putenv('STATSD_SAMPLE_PAYLOAD=0.66');

        $config = new StatsDConfig();

        $this->assertTrue($config->isEnabled());
        $this->assertEquals('putenv-only-host', $config->getHost());
        $this->assertEquals(3333, $config->getPort());
        $this->assertEquals('putenv-only-ns', $config->getNamespace());
        $this->assertEquals(0.55, $config->getSampleRate('ok_events'));
        $this->assertEquals(0.66, $config->getSampleRate('payload'));

        // Cleanup
        putenv('STATSD_ENABLED');
        putenv('STATSD_HOST');
        putenv('STATSD_PORT');
        putenv('STATSD_NAMESPACE');
        putenv('STATSD_SAMPLE_OK');
        putenv('STATSD_SAMPLE_PAYLOAD');
    }
}
