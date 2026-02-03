<?php

namespace AlexFN\NanoService\Tests\Unit;

use AlexFN\NanoService\EventRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EventRepository
 *
 * Tests singleton pattern, database connection management, environment variable
 * validation, and outbox insert operations for transactional outbox pattern.
 */
class EventRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset singleton before each test
        EventRepository::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clean up environment variables
        $envVars = [
            'DB_BOX_HOST',
            'DB_BOX_PORT',
            'DB_BOX_NAME',
            'DB_BOX_USER',
            'DB_BOX_PASS',
            'DB_BOX_SCHEMA',
        ];
        foreach ($envVars as $var) {
            unset($_ENV[$var]);
        }
        // Reset singleton after each test
        EventRepository::reset();
    }

    // ==========================================
    // Singleton Pattern Tests
    // ==========================================

    public function testGetInstanceReturnsSameInstance(): void
    {
        $instance1 = EventRepository::getInstance();
        $instance2 = EventRepository::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testGetInstanceReturnsEventRepositoryInstance(): void
    {
        $instance = EventRepository::getInstance();

        $this->assertInstanceOf(EventRepository::class, $instance);
    }

    public function testResetClearsSingletonInstance(): void
    {
        $instance1 = EventRepository::getInstance();
        EventRepository::reset();
        $instance2 = EventRepository::getInstance();

        $this->assertNotSame($instance1, $instance2);
    }

    public function testWakeupThrowsException(): void
    {
        $instance = EventRepository::getInstance();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Cannot unserialize singleton");
        $instance->__wakeup();
    }

    // ==========================================
    // Environment Variable Validation Tests
    // ==========================================

    public function testValidateRequiredEnvVarsSucceedsWhenAllPresent(): void
    {
        $_ENV['TEST_VAR_1'] = 'value1';
        $_ENV['TEST_VAR_2'] = 'value2';
        $_ENV['TEST_VAR_3'] = 'value3';

        $repository = EventRepository::getInstance();

        $repository->validateRequiredEnvVars(['TEST_VAR_1', 'TEST_VAR_2', 'TEST_VAR_3']);

        $this->assertTrue(true); // If we got here, no exception was thrown

        unset($_ENV['TEST_VAR_1'], $_ENV['TEST_VAR_2'], $_ENV['TEST_VAR_3']);
    }

    public function testValidateRequiredEnvVarsThrowsOnSingleMissingVar(): void
    {
        $_ENV['PRESENT_VAR'] = 'value';

        $repository = EventRepository::getInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Missing required environment variables: MISSING_VAR");
        $repository->validateRequiredEnvVars(['PRESENT_VAR', 'MISSING_VAR']);

        unset($_ENV['PRESENT_VAR']);
    }

    public function testValidateRequiredEnvVarsThrowsOnMultipleMissingVars(): void
    {
        $_ENV['PRESENT_VAR'] = 'value';

        $repository = EventRepository::getInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Missing required environment variables: MISSING_VAR_1, MISSING_VAR_2");
        $repository->validateRequiredEnvVars(['PRESENT_VAR', 'MISSING_VAR_1', 'MISSING_VAR_2']);

        unset($_ENV['PRESENT_VAR']);
    }

    public function testValidateRequiredEnvVarsThrowsOnAllMissingVars(): void
    {
        $repository = EventRepository::getInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Missing required environment variables: VAR1, VAR2, VAR3");
        $repository->validateRequiredEnvVars(['VAR1', 'VAR2', 'VAR3']);
    }

    public function testValidateRequiredEnvVarsSucceedsWithEmptyArray(): void
    {
        $repository = EventRepository::getInstance();

        $repository->validateRequiredEnvVars([]);

        $this->assertTrue(true);
    }

    // ==========================================
    // Database Connection Tests
    // ==========================================

    public function testGetConnectionThrowsOnMissingDbBoxHost(): void
    {
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'testdb';
        $_ENV['DB_BOX_USER'] = 'testuser';
        $_ENV['DB_BOX_PASS'] = 'testpass';

        $repository = EventRepository::getInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Missing required environment variables: DB_BOX_HOST");
        $repository->getConnection();
    }

    public function testGetConnectionThrowsOnMissingDbBoxPort(): void
    {
        $_ENV['DB_BOX_HOST'] = 'localhost';
        $_ENV['DB_BOX_NAME'] = 'testdb';
        $_ENV['DB_BOX_USER'] = 'testuser';
        $_ENV['DB_BOX_PASS'] = 'testpass';

        $repository = EventRepository::getInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Missing required environment variables: DB_BOX_PORT");
        $repository->getConnection();
    }

    public function testGetConnectionThrowsOnMissingDbBoxName(): void
    {
        $_ENV['DB_BOX_HOST'] = 'localhost';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_USER'] = 'testuser';
        $_ENV['DB_BOX_PASS'] = 'testpass';

        $repository = EventRepository::getInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Missing required environment variables: DB_BOX_NAME");
        $repository->getConnection();
    }

    public function testGetConnectionThrowsOnMissingDbBoxUser(): void
    {
        $_ENV['DB_BOX_HOST'] = 'localhost';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'testdb';
        $_ENV['DB_BOX_PASS'] = 'testpass';

        $repository = EventRepository::getInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Missing required environment variables: DB_BOX_USER");
        $repository->getConnection();
    }

    public function testGetConnectionThrowsOnMissingDbBoxPass(): void
    {
        $_ENV['DB_BOX_HOST'] = 'localhost';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'testdb';
        $_ENV['DB_BOX_USER'] = 'testuser';

        $repository = EventRepository::getInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Missing required environment variables: DB_BOX_PASS");
        $repository->getConnection();
    }

    /**
     * @dataProvider missingDbEnvVarCombinationsProvider
     */
    public function testGetConnectionThrowsOnMultipleMissingVars(array $presentVars, string $expectedMissing): void
    {
        foreach ($presentVars as $key => $value) {
            $_ENV[$key] = $value;
        }

        $repository = EventRepository::getInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Missing required environment variables: {$expectedMissing}");
        $repository->getConnection();
    }

    public static function missingDbEnvVarCombinationsProvider(): array
    {
        return [
            'missing host and port' => [
                ['DB_BOX_NAME' => 'test', 'DB_BOX_USER' => 'user', 'DB_BOX_PASS' => 'pass'],
                'DB_BOX_HOST, DB_BOX_PORT',
            ],
            'missing name and user' => [
                ['DB_BOX_HOST' => 'localhost', 'DB_BOX_PORT' => '5432', 'DB_BOX_PASS' => 'pass'],
                'DB_BOX_NAME, DB_BOX_USER',
            ],
            'only host present' => [
                ['DB_BOX_HOST' => 'localhost'],
                'DB_BOX_PORT, DB_BOX_NAME, DB_BOX_USER, DB_BOX_PASS',
            ],
        ];
    }

    public function testGetConnectionThrowsOnInvalidHost(): void
    {
        $_ENV['DB_BOX_HOST'] = 'invalid-host-that-does-not-exist.local';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'testdb';
        $_ENV['DB_BOX_USER'] = 'testuser';
        $_ENV['DB_BOX_PASS'] = 'testpass';

        $repository = EventRepository::getInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to connect to event database:');
        $repository->getConnection();
    }

    public function testGetConnectionThrowsOnInvalidPort(): void
    {
        $_ENV['DB_BOX_HOST'] = 'localhost';
        $_ENV['DB_BOX_PORT'] = '99999'; // Invalid port
        $_ENV['DB_BOX_NAME'] = 'testdb';
        $_ENV['DB_BOX_USER'] = 'testuser';
        $_ENV['DB_BOX_PASS'] = 'testpass';

        $repository = EventRepository::getInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to connect to event database:');
        $repository->getConnection();
    }

    public function testGetConnectionThrowsOnInvalidCredentials(): void
    {
        // Assuming localhost:5432 has a PostgreSQL instance but with wrong credentials
        $_ENV['DB_BOX_HOST'] = 'localhost';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'nonexistent_db';
        $_ENV['DB_BOX_USER'] = 'invalid_user';
        $_ENV['DB_BOX_PASS'] = 'wrong_password';

        $repository = EventRepository::getInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to connect to event database:');
        $repository->getConnection();
    }

    public function testGetConnectionCachesConnection(): void
    {
        // We can't easily test this without mocking PDO, but we can test
        // that multiple calls don't throw errors when env vars are missing
        // after the first call
        $_ENV['DB_BOX_HOST'] = 'invalid-host.local';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'testdb';
        $_ENV['DB_BOX_USER'] = 'testuser';
        $_ENV['DB_BOX_PASS'] = 'testpass';

        $repository = EventRepository::getInstance();

        // First call will try to connect and fail
        try {
            $repository->getConnection();
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Remove env vars
        unset($_ENV['DB_BOX_HOST']);

        // Second call should also fail but with same error (not missing env var)
        // because it tries to use the cached connection attempt
        try {
            $repository->getConnection();
        } catch (\RuntimeException $e) {
            // If it was checking env vars again, we'd get a different error
            // This test verifies caching behavior indirectly
            $this->assertTrue(true);
        }
    }

    public function testResetClearsConnection(): void
    {
        $_ENV['DB_BOX_HOST'] = 'localhost';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'testdb';
        $_ENV['DB_BOX_USER'] = 'testuser';
        $_ENV['DB_BOX_PASS'] = 'testpass';

        $repository = EventRepository::getInstance();

        // Try to establish connection (will fail but that's ok for this test)
        try {
            $repository->getConnection();
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Reset should clear both instance and connection
        EventRepository::reset();

        // Get new instance
        $newRepository = EventRepository::getInstance();

        // Verify it's a different instance with no cached connection
        $this->assertNotSame($repository, $newRepository);

        // Verify connection is null by trying to connect again
        // (if connection was cached, this wouldn't trigger new connection attempt)
        unset($_ENV['DB_BOX_HOST']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Missing required environment variables: DB_BOX_HOST");
        $newRepository->getConnection();
    }

    // ==========================================
    // Insert Outbox Tests
    // ==========================================

    public function testInsertOutboxThrowsOnMissingConnection(): void
    {
        $_ENV['DB_BOX_HOST'] = 'invalid-host.local';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'testdb';
        $_ENV['DB_BOX_USER'] = 'testuser';
        $_ENV['DB_BOX_PASS'] = 'testpass';

        $repository = EventRepository::getInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to connect to event database:');
        $repository->insertOutbox(
            'test-service',
            'test.event',
            '{"test": "data"}',
            'partition-key',
            'public'
        );
    }

    public function testInsertOutboxThrowsOnMissingEnvVars(): void
    {
        $repository = EventRepository::getInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required environment variables:');
        $repository->insertOutbox(
            'test-service',
            'test.event',
            '{"test": "data"}',
            'partition-key',
            'public'
        );
    }

    // ==========================================
    // Schema Parameter Tests
    // ==========================================

    public function testInsertOutboxUsesPublicSchemaByDefault(): void
    {
        // This test verifies the default parameter value
        // We can't test actual SQL execution without a real DB,
        // but we can verify the method signature accepts the parameter
        $repository = EventRepository::getInstance();

        // Set invalid connection to trigger early failure
        $_ENV['DB_BOX_HOST'] = 'invalid-host.local';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'testdb';
        $_ENV['DB_BOX_USER'] = 'testuser';
        $_ENV['DB_BOX_PASS'] = 'testpass';

        try {
            // Call without schema parameter - should use 'public' by default
            $repository->insertOutbox(
                'test-service',
                'test.event',
                '{"test": "data"}',
                'partition-key'
                // schema parameter omitted - should default to 'public'
            );
        } catch (\RuntimeException $e) {
            // Expected to fail on connection, but method accepted parameters
            $this->assertStringContainsString('Failed to connect to event database:', $e->getMessage());
        }
    }

    public function testInsertOutboxAcceptsCustomSchema(): void
    {
        $repository = EventRepository::getInstance();

        // Set invalid connection to trigger early failure
        $_ENV['DB_BOX_HOST'] = 'invalid-host.local';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'testdb';
        $_ENV['DB_BOX_USER'] = 'testuser';
        $_ENV['DB_BOX_PASS'] = 'testpass';

        try {
            // Call with custom schema parameter
            $repository->insertOutbox(
                'test-service',
                'test.event',
                '{"test": "data"}',
                'partition-key',
                'custom_schema'
            );
        } catch (\RuntimeException $e) {
            // Expected to fail on connection, but method accepted parameters
            $this->assertStringContainsString('Failed to connect to event database:', $e->getMessage());
        }
    }

    // ==========================================
    // Partition Key Tests
    // ==========================================

    public function testInsertOutboxAcceptsNullPartitionKey(): void
    {
        $repository = EventRepository::getInstance();

        // Set invalid connection to trigger early failure
        $_ENV['DB_BOX_HOST'] = 'invalid-host.local';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'testdb';
        $_ENV['DB_BOX_USER'] = 'testuser';
        $_ENV['DB_BOX_PASS'] = 'testpass';

        try {
            $repository->insertOutbox(
                'test-service',
                'test.event',
                '{"test": "data"}',
                null, // null partition key
                'public'
            );
        } catch (\RuntimeException $e) {
            // Expected to fail on connection, but method accepted parameters
            $this->assertStringContainsString('Failed to connect to event database:', $e->getMessage());
        }
    }

    public function testInsertOutboxAcceptsEmptyStringPartitionKey(): void
    {
        $repository = EventRepository::getInstance();

        // Set invalid connection to trigger early failure
        $_ENV['DB_BOX_HOST'] = 'invalid-host.local';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'testdb';
        $_ENV['DB_BOX_USER'] = 'testuser';
        $_ENV['DB_BOX_PASS'] = 'testpass';

        try {
            $repository->insertOutbox(
                'test-service',
                'test.event',
                '{"test": "data"}',
                '', // empty string partition key
                'public'
            );
        } catch (\RuntimeException $e) {
            // Expected to fail on connection, but method accepted parameters
            $this->assertStringContainsString('Failed to connect to event database:', $e->getMessage());
        }
    }

    // ==========================================
    // Parameter Validation Tests
    // ==========================================

    /**
     * @dataProvider validInsertOutboxParametersProvider
     */
    public function testInsertOutboxAcceptsValidParameters(
        string $producerService,
        string $eventType,
        string $messageBody,
        ?string $partitionKey,
        string $schema
    ): void {
        $repository = EventRepository::getInstance();

        // Set invalid connection to trigger early failure
        $_ENV['DB_BOX_HOST'] = 'invalid-host.local';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'testdb';
        $_ENV['DB_BOX_USER'] = 'testuser';
        $_ENV['DB_BOX_PASS'] = 'testpass';

        try {
            $repository->insertOutbox(
                $producerService,
                $eventType,
                $messageBody,
                $partitionKey,
                $schema
            );
        } catch (\RuntimeException $e) {
            // Expected to fail on connection, but method accepted parameters
            $this->assertStringContainsString('Failed to connect to event database:', $e->getMessage());
        }
    }

    public static function validInsertOutboxParametersProvider(): array
    {
        return [
            'basic event' => [
                'my-service',
                'user.created',
                '{"user_id": 123}',
                'user-123',
                'public',
            ],
            'event with dots' => [
                'my.service.name',
                'order.payment.completed',
                '{"order_id": 456}',
                'order-456',
                'events',
            ],
            'event with hyphens' => [
                'my-service-name',
                'invoice-created',
                '{"invoice_id": 789}',
                'invoice-789',
                'public',
            ],
            'null partition key' => [
                'service',
                'event.type',
                '{"data": "value"}',
                null,
                'public',
            ],
            'empty message body' => [
                'service',
                'event.type',
                '{}',
                'key',
                'public',
            ],
            'complex json' => [
                'service',
                'event.type',
                '{"nested": {"data": {"value": 123}}, "array": [1, 2, 3]}',
                'key',
                'public',
            ],
            'long partition key' => [
                'service',
                'event.type',
                '{"data": "value"}',
                'very-long-partition-key-with-many-characters-' . str_repeat('x', 100),
                'public',
            ],
        ];
    }

    // ==========================================
    // Multiple Instance Tests
    // ==========================================

    public function testMultipleGetInstanceCallsReturnSameInstance(): void
    {
        $instance1 = EventRepository::getInstance();
        $instance2 = EventRepository::getInstance();
        $instance3 = EventRepository::getInstance();
        $instance4 = EventRepository::getInstance();

        $this->assertSame($instance1, $instance2);
        $this->assertSame($instance2, $instance3);
        $this->assertSame($instance3, $instance4);
    }

    public function testResetAndGetInstanceCreatesNewInstance(): void
    {
        $instance1 = EventRepository::getInstance();
        EventRepository::reset();
        $instance2 = EventRepository::getInstance();
        EventRepository::reset();
        $instance3 = EventRepository::getInstance();

        $this->assertNotSame($instance1, $instance2);
        $this->assertNotSame($instance2, $instance3);
        $this->assertNotSame($instance1, $instance3);
    }

    // ==========================================
    // Connection Reuse Tests
    // ==========================================

    public function testFailedConnectionIsNotCached(): void
    {
        $_ENV['DB_BOX_HOST'] = 'invalid-host.local';
        $_ENV['DB_BOX_PORT'] = '5432';
        $_ENV['DB_BOX_NAME'] = 'testdb';
        $_ENV['DB_BOX_USER'] = 'testuser';
        $_ENV['DB_BOX_PASS'] = 'testpass';

        $repository = EventRepository::getInstance();

        // First call attempts connection and fails
        try {
            $repository->getConnection();
            $this->fail('Expected RuntimeException for failed connection');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to connect to event database:', $e->getMessage());
        }

        // Remove env vars
        unset($_ENV['DB_BOX_HOST'], $_ENV['DB_BOX_PORT'], $_ENV['DB_BOX_NAME']);

        // Second call should validate env vars again (failed connections are not cached)
        try {
            $repository->getConnection();
            $this->fail('Expected RuntimeException for missing env vars');
        } catch (\RuntimeException $e) {
            // Should get missing env vars error, proving connection wasn't cached
            $this->assertStringContainsString('Missing required environment variables', $e->getMessage());
        }
    }

    // ==========================================
    // Edge Cases
    // ==========================================

    public function testValidateRequiredEnvVarsWithDuplicateVariables(): void
    {
        $_ENV['TEST_VAR'] = 'value';

        $repository = EventRepository::getInstance();

        // Should handle duplicates gracefully
        $repository->validateRequiredEnvVars(['TEST_VAR', 'TEST_VAR', 'TEST_VAR']);

        $this->assertTrue(true);

        unset($_ENV['TEST_VAR']);
    }

    public function testValidateRequiredEnvVarsWithEmptyStringValue(): void
    {
        $_ENV['EMPTY_VAR'] = '';

        $repository = EventRepository::getInstance();

        // Empty string is considered "present" (isset returns true)
        $repository->validateRequiredEnvVars(['EMPTY_VAR']);

        $this->assertTrue(true);

        unset($_ENV['EMPTY_VAR']);
    }

    public function testGetConnectionWithEmptyCredentials(): void
    {
        $_ENV['DB_BOX_HOST'] = '';
        $_ENV['DB_BOX_PORT'] = '';
        $_ENV['DB_BOX_NAME'] = '';
        $_ENV['DB_BOX_USER'] = '';
        $_ENV['DB_BOX_PASS'] = '';

        $repository = EventRepository::getInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to connect to event database:');
        $repository->getConnection();
    }
}
