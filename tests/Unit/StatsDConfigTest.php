<?php

namespace AlexFN\NanoService\Tests\Unit;

use AlexFN\NanoService\Config\StatsDConfig;
use PHPUnit\Framework\TestCase;

class StatsDConfigTest extends TestCase
{
    private array $envVars = [
        'STATSD_ENABLED',
        'STATSD_HOST',
        'STATSD_PORT',
        'STATSD_NAMESPACE',
        'AMQP_MICROSERVICE_NAME',
        'APP_ENV',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupEnv();
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

    private function setAllRequiredEnv(): void
    {
        putenv('STATSD_ENABLED=true');
        putenv('STATSD_HOST=localhost');
        putenv('STATSD_PORT=8125');
        putenv('STATSD_NAMESPACE=ew');
        putenv('AMQP_MICROSERVICE_NAME=test-service');
    }

    private function fullArrayConfig(array $overrides = []): array
    {
        return array_merge([
            'enabled' => 'true',
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'ew',
            'nano_service_name' => 'test-service',
        ], $overrides);
    }

    // ==========================================
    // Disabled
    // ==========================================

    public function testDisabledByDefault(): void
    {
        $config = new StatsDConfig();
        $this->assertFalse($config->isEnabled());
    }

    public function testDisabledWhenExplicitlyFalse(): void
    {
        putenv('STATSD_ENABLED=false');
        $config = new StatsDConfig();
        $this->assertFalse($config->isEnabled());
    }

    public function testDisabledDoesNotRequireEnvVars(): void
    {
        $config = new StatsDConfig();
        $this->assertFalse($config->isEnabled());
    }

    public function testDisabledViaArrayConfig(): void
    {
        $config = new StatsDConfig(['enabled' => false]);
        $this->assertFalse($config->isEnabled());
    }

    // ==========================================
    // Enabled - env vars
    // ==========================================

    public function testEnabledFromEnv(): void
    {
        $this->setAllRequiredEnv();

        $config = new StatsDConfig();

        $this->assertTrue($config->isEnabled());
        $this->assertEquals('localhost', $config->getHost());
        $this->assertEquals(8125, $config->getPort());
        $this->assertEquals('ew', $config->getNamespace());
        $this->assertEquals(['nano_service_name' => 'test-service', 'env' => 'unknown'], $config->getDefaultTags());
    }

    public function testEnabledOnlyForExactStringTrue(): void
    {
        $falseValues = ['false', '1', '0', 'yes', 'no', '', 'TRUE', 'True', ' true ', "true\n"];

        foreach ($falseValues as $value) {
            $this->cleanupEnv();
            putenv("STATSD_ENABLED=$value");

            $config = new StatsDConfig();
            $this->assertFalse(
                $config->isEnabled(),
                "STATSD_ENABLED='$value' should be disabled"
            );
        }
    }

    // ==========================================
    // Enabled - array config
    // ==========================================

    public function testFullArrayConfigSkipsEnvValidation(): void
    {
        $config = new StatsDConfig($this->fullArrayConfig());

        $this->assertTrue($config->isEnabled());
        $this->assertEquals('localhost', $config->getHost());
        $this->assertEquals(8125, $config->getPort());
        $this->assertEquals('ew', $config->getNamespace());
        $this->assertEquals(['nano_service_name' => 'test-service', 'env' => 'unknown'], $config->getDefaultTags());
    }

    public function testArrayConfigOverridesEnv(): void
    {
        $this->setAllRequiredEnv();

        $config = new StatsDConfig($this->fullArrayConfig([
            'host' => 'override-host',
            'port' => 9999,
        ]));

        $this->assertEquals('override-host', $config->getHost());
        $this->assertEquals(9999, $config->getPort());
    }

    // ==========================================
    // Missing required env vars
    // ==========================================

    public function testThrowsOnMissingHost(): void
    {
        $this->setAllRequiredEnv();
        putenv('STATSD_HOST');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('STATSD_HOST');
        new StatsDConfig();
    }

    public function testThrowsOnMissingPort(): void
    {
        $this->setAllRequiredEnv();
        putenv('STATSD_PORT');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('STATSD_PORT');
        new StatsDConfig();
    }

    public function testThrowsOnMissingNamespace(): void
    {
        $this->setAllRequiredEnv();
        putenv('STATSD_NAMESPACE');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('STATSD_NAMESPACE');
        new StatsDConfig();
    }

    public function testThrowsOnMissingMicroserviceName(): void
    {
        $this->setAllRequiredEnv();
        putenv('AMQP_MICROSERVICE_NAME');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AMQP_MICROSERVICE_NAME');
        new StatsDConfig();
    }

    // ==========================================
    // toArray
    // ==========================================

    public function testToArray(): void
    {
        $config = new StatsDConfig($this->fullArrayConfig());

        $this->assertEquals([
            'host' => 'localhost',
            'port' => 8125,
            'namespace' => 'ew',
            'tags' => ['nano_service_name' => 'test-service', 'env' => 'unknown'],
        ], $config->toArray());
    }

    // ==========================================
    // Default tags
    // ==========================================

    public function testDefaultTagsFromEnv(): void
    {
        $this->setAllRequiredEnv();
        $config = new StatsDConfig();

        $this->assertEquals(['nano_service_name' => 'test-service', 'env' => 'unknown'], $config->getDefaultTags());
    }

    public function testDefaultTagsFromArrayConfig(): void
    {
        $config = new StatsDConfig($this->fullArrayConfig([
            'nano_service_name' => 'custom-service',
        ]));

        $this->assertEquals(['nano_service_name' => 'custom-service', 'env' => 'unknown'], $config->getDefaultTags());
    }

    public function testDefaultTagsEnvFromAppEnv(): void
    {
        putenv('APP_ENV=staging');
        $config = new StatsDConfig($this->fullArrayConfig());

        $this->assertEquals(['nano_service_name' => 'test-service', 'env' => 'staging'], $config->getDefaultTags());
    }

    public function testDefaultTagsEnvFromArrayConfig(): void
    {
        $config = new StatsDConfig($this->fullArrayConfig(['env' => 'testing']));

        $this->assertEquals(['nano_service_name' => 'test-service', 'env' => 'testing'], $config->getDefaultTags());
    }

    public function testDefaultTagsEmptyWhenDisabled(): void
    {
        $config = new StatsDConfig(['enabled' => false]);
        $this->assertEquals([], $config->getDefaultTags());
    }

    // ==========================================
    // Port type conversion
    // ==========================================

    public function testPortIsConvertedToInt(): void
    {
        $this->setAllRequiredEnv();
        $config = new StatsDConfig();

        $this->assertIsInt($config->getPort());
        $this->assertEquals(8125, $config->getPort());
    }
}
