<?php

namespace AlexFN\NanoService;

/**
 * Singleton repository for event storage operations (outbox and inbox patterns)
 *
 * Handles all database interactions for transactional outbox and inbox patterns.
 * Maintains a single cached PDO connection for efficient resource usage.
 *
 * Supports:
 * - Outbox pattern: Publishing events to be consumed by other services
 * - Inbox pattern: Receiving and processing events from other services (future)
 *
 * @package AlexFN\NanoService
 */
class EventRepository
{
    private static ?self $instance = null;
    private ?\PDO $connection = null;

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct()
    {
    }

    /**
     * Prevent cloning of the singleton instance
     */
    private function __clone()
    {
    }

    /**
     * Prevent unserialization of the singleton instance
     */
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }

    /**
     * Get the singleton instance
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Validate required environment variables exist
     *
     * @param array $variables Variable names to check
     * @throws \RuntimeException if any variable is missing
     * @return void
     */
    public function validateRequiredEnvVars(array $variables): void
    {
        $missing = [];

        foreach ($variables as $var) {
            if (!isset($_ENV[$var])) {
                $missing[] = $var;
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException(
                "Missing required environment variables: " . implode(', ', $missing)
            );
        }
    }

    /**
     * Get PostgreSQL database connection for event storage
     *
     * Creates and caches a PDO connection to the event database.
     * Connection is reused across multiple operations.
     *
     * @return \PDO PostgreSQL database connection
     * @throws \RuntimeException if connection fails or required env vars are missing
     */
    public function getConnection(): \PDO
    {
        if ($this->connection === null) {
            // Validate required environment variables
            $this->validateRequiredEnvVars([
                'DB_BOX_HOST',
                'DB_BOX_PORT',
                'DB_BOX_NAME',
                'DB_BOX_USER',
                'DB_BOX_PASS',
            ]);

            $dsn = sprintf(
                "pgsql:host=%s;port=%s;dbname=%s",
                $_ENV['DB_BOX_HOST'],
                $_ENV['DB_BOX_PORT'],
                $_ENV['DB_BOX_NAME']
            );

            try {
                $this->connection = new \PDO($dsn, $_ENV['DB_BOX_USER'], $_ENV['DB_BOX_PASS'], [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                ]);
            } catch (\PDOException $e) {
                throw new \RuntimeException(
                    "Failed to connect to event database: " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        return $this->connection;
    }

    /**
     * Insert a message into the outbox table
     *
     * @param string $producerService Producer service name
     * @param string $eventType Event type (routing key)
     * @param string $messageBody Message body as JSON
     * @param string|null $partitionKey Optional partition key
     * @param string $schema Database schema name
     * @param string|null $messageId Optional message ID (UUID) for tracking
     * @return void
     * @throws \RuntimeException if insert fails
     */
    public function insertOutbox(
        string $producerService,
        string $eventType,
        string $messageBody,
        ?string $partitionKey = null,
        string $schema = 'public',
        ?string $messageId = null
    ): void {
        try {
            $pdo = $this->getConnection();

            $stmt = $pdo->prepare("
                INSERT INTO {$schema}.outbox (
                    producer_service,
                    event_type,
                    message_body,
                    partition_key,
                    message_id
                ) VALUES (?, ?, ?::jsonb, ?, ?)
            ");

            $stmt->execute([
                $producerService,
                $eventType,
                $messageBody,
                $partitionKey,
                $messageId,
            ]);
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                "Failed to insert into outbox table: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Mark an outbox event as published (processed)
     *
     * Updates the status to 'published' and sets processed_at timestamp
     * for the event with the given message_id.
     *
     * @param string $messageId Message ID (UUID)
     * @param string $schema Database schema name
     * @return void
     * @throws \RuntimeException if update fails
     */
    public function markAsPublished(string $messageId, string $schema = 'public'): void
    {
        try {
            $pdo = $this->getConnection();

            $stmt = $pdo->prepare("
                UPDATE {$schema}.outbox
                SET status = 'published',
                    published_at = NOW()
                WHERE message_id = ?
            ");

            $stmt->execute([$messageId]);
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                "Failed to mark event as published: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Reset the singleton instance (useful for testing)
     *
     * @return void
     */
    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->connection = null;
            self::$instance = null;
        }
    }
}
