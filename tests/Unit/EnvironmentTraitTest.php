<?php

namespace AlexFN\NanoService\Tests\Unit;

use AlexFN\NanoService\Traits\Environment;
use PHPUnit\Framework\TestCase;

/**
 * Test class that uses the Environment trait for testing
 */
class EnvironmentTraitTestClass
{
    use Environment;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function getEnvPublic(string $param): ?string
    {
        return $this->getEnv($param);
    }
}

/**
 * Unit tests for Environment trait
 *
 * Tests environment variable retrieval and config priority.
 */
class EnvironmentTraitTest extends TestCase
{
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
        $vars = [
            'AMQP_HOST',
            'AMQP_PORT',
            'AMQP_TEST_VAR',
        ];

        foreach ($vars as $var) {
            unset($_ENV[$var]);
            putenv($var);
        }
    }

    // ==========================================
    // Config Override Tests
    // ==========================================

    public function testConfigOverridesEnvVar(): void
    {
        $_ENV['AMQP_HOST'] = 'env-host';
        $class = new EnvironmentTraitTestClass(['host' => 'config-host']);

        $this->assertEquals('config-host', $class->getEnvPublic('AMQP_HOST'));
    }

    public function testConfigTakesPriority(): void
    {
        putenv('AMQP_HOST=putenv-host');
        $_ENV['AMQP_HOST'] = 'env-host';
        $class = new EnvironmentTraitTestClass(['host' => 'config-host']);

        $this->assertEquals('config-host', $class->getEnvPublic('AMQP_HOST'));
    }

    // ==========================================
    // Environment Variable Tests
    // ==========================================

    public function testGetEnvFromEnvArray(): void
    {
        $_ENV['AMQP_HOST'] = 'localhost';
        $class = new EnvironmentTraitTestClass();

        $this->assertEquals('localhost', $class->getEnvPublic('AMQP_HOST'));
    }

    public function testGetEnvFromPutenv(): void
    {
        putenv('AMQP_HOST=putenv-host');
        $class = new EnvironmentTraitTestClass();

        $this->assertEquals('putenv-host', $class->getEnvPublic('AMQP_HOST'));
    }

    public function testGetEnvReturnsNullWhenNotSet(): void
    {
        $class = new EnvironmentTraitTestClass();

        $this->assertNull($class->getEnvPublic('AMQP_NONEXISTENT'));
    }

    // ==========================================
    // Prefix Handling Tests
    // ==========================================

    public function testPrefixIsStrippedForConfigLookup(): void
    {
        $class = new EnvironmentTraitTestClass(['port' => '5672']);

        // AMQP_PORT -> port (stripped AMQP_ prefix, lowercased)
        $this->assertEquals('5672', $class->getEnvPublic('AMQP_PORT'));
    }

    public function testPrefixHandlingWithDifferentVars(): void
    {
        $class = new EnvironmentTraitTestClass([
            'host' => 'my-host',
            'port' => '1234',
            'test_var' => 'test-value',
        ]);

        $this->assertEquals('my-host', $class->getEnvPublic('AMQP_HOST'));
        $this->assertEquals('1234', $class->getEnvPublic('AMQP_PORT'));
        $this->assertEquals('test-value', $class->getEnvPublic('AMQP_TEST_VAR'));
    }

    // ==========================================
    // Priority Order Tests
    // ==========================================

    public function testPriorityOrder(): void
    {
        // Priority: config > getenv(local) > getenv(global) > $_ENV

        // Set up all sources
        $_ENV['AMQP_HOST'] = 'env-host';
        putenv('AMQP_HOST=putenv-host');

        // Config has highest priority
        $classWithConfig = new EnvironmentTraitTestClass(['host' => 'config-host']);
        $this->assertEquals('config-host', $classWithConfig->getEnvPublic('AMQP_HOST'));

        // Without config, getenv has priority over $_ENV
        $classWithoutConfig = new EnvironmentTraitTestClass();
        $this->assertEquals('putenv-host', $classWithoutConfig->getEnvPublic('AMQP_HOST'));
    }

    public function testFallbackToEnvArrayWhenGetenvReturnsFalse(): void
    {
        // Only set $_ENV, not putenv
        $_ENV['AMQP_HOST'] = 'only-in-env-array';

        $class = new EnvironmentTraitTestClass();
        $this->assertEquals('only-in-env-array', $class->getEnvPublic('AMQP_HOST'));
    }
}
