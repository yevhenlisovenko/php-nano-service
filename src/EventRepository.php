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
 * - Inbox pattern: Receiving and processing events from other services (idempotent consumption)
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
     * Execute a callable with retry logic for transient failures
     *
     * Retries database operations that fail due to transient issues
     * (connection errors, deadlocks, timeouts) with exponential backoff.
     *
     * @param callable $operation The operation to execute (receives PDO as parameter)
     * @param int $maxRetries Maximum number of retry attempts (default: 3)
     * @param int $baseDelayMs Base delay in milliseconds for exponential backoff (default: 100ms)
     * @return mixed The result of the operation
     * @throws \PDOException Re-throws the exception if all retries fail
     */
    private function executeWithRetry(callable $operation, int $maxRetries = 3, int $baseDelayMs = 100)
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $pdo = $this->getConnection();
                return $operation($pdo);
            } catch (\PDOException $e) {
                $lastException = $e;

                // Don't retry if this is the last attempt
                if ($attempt >= $maxRetries) {
                    break;
                }

                // Check if error is retryable (connection issues, deadlocks, timeouts)
                $errorCode = $e->getCode();
                $errorMessage = strtolower($e->getMessage());
                $isRetryable = (
                    // Connection errors
                    stripos($errorMessage, 'connection') !== false ||
                    stripos($errorMessage, 'server closed') !== false ||
                    stripos($errorMessage, 'broken pipe') !== false ||
                    // Deadlocks
                    $errorCode === '40P01' ||
                    stripos($errorMessage, 'deadlock') !== false ||
                    // Lock timeouts
                    stripos($errorMessage, 'lock timeout') !== false ||
                    stripos($errorMessage, 'timeout') !== false
                );

                if (!$isRetryable) {
                    // Non-retryable error, throw immediately
                    throw $e;
                }

                // Exponential backoff: 100ms, 200ms, 300ms, etc.
                usleep($baseDelayMs * 1000 * $attempt);

                // Reset connection on connection errors
                if (stripos($errorMessage, 'connection') !== false ||
                    stripos($errorMessage, 'server closed') !== false ||
                    stripos($errorMessage, 'broken pipe') !== false) {
                    $this->connection = null;
                }
            }
        }

        // All retries failed, throw the last exception
        throw $lastException;
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
     * Handles duplicate key violations gracefully (returns false for idempotent behavior).
     * This allows multiple concurrent calls with the same message_id to succeed idempotently.
     *
     * @param string $producerService Producer service name
     * @param string $eventType Event type (routing key)
     * @param string $messageBody Message body as JSON
     * @param string $messageId Required message ID (UUID) for tracking
     * @param string|null $partitionKey Optional partition key
     * @param string $schema Database schema name
     * @param string $status Initial status (default: 'processing' to prevent race conditions)
     * @return bool True if inserted successfully, false if already exists (duplicate message_id)
     * @throws \RuntimeException if insert fails for reasons other than duplicate key
     */
    public function insertOutbox(
        string $producerService,
        string $eventType,
        string $messageBody,
        string $messageId,
        ?string $partitionKey = null,
        string $schema = 'public',
        string $status = 'processing'
    ): bool {
        try {
            $pdo = $this->getConnection();

            $stmt = $pdo->prepare("
                INSERT INTO {$schema}.outbox (
                    producer_service,
                    event_type,
                    message_body,
                    partition_key,
                    message_id,
                    status
                ) VALUES (?, ?, ?::jsonb, ?, ?, ?)
            ");

            $stmt->execute([
                $producerService,
                $eventType,
                $messageBody,
                $partitionKey,
                $messageId,
                $status,
            ]);

            return true; // Insert successful
        } catch (\PDOException $e) {
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();

            // Handle duplicate key violation (race condition - another thread inserted same message_id)
            if ($errorCode === '23505' ||
                stripos($errorMessage, 'duplicate key') !== false ||
                stripos($errorMessage, 'unique constraint') !== false) {
                // Message already exists - return false to indicate idempotent skip
                return false;
            }

            // Other database errors are critical
            throw new \RuntimeException(
                "Failed to insert into outbox table: " . $errorMessage,
                0,
                $e
            );
        }
    }

    /**
     * Mark an outbox event as published (processed)
     *
     * Updates the status to 'published' and sets published_at timestamp
     * for the event with the given message_id.
     *
     * Handles database errors gracefully by logging and returning false.
     * This prevents exceptions when the event was successfully published to RabbitMQ
     * but the database update fails (accept duplicate risk over false failure).
     *
     * Automatically retries transient failures (connection errors, deadlocks) up to 3 times.
     *
     * @param string $messageId Message ID (UUID)
     * @param string $schema Database schema name
     * @return bool True if marked successfully, false if database update failed after retries
     */
    public function markAsPublished(string $messageId, string $schema = 'public'): bool
    {
        try {
            $this->executeWithRetry(function ($pdo) use ($messageId, $schema) {
                $stmt = $pdo->prepare("
                    UPDATE {$schema}.outbox
                    SET status = 'published',
                        published_at = NOW()
                    WHERE message_id = ?
                ");

                $stmt->execute([$messageId]);
                return true;
            });
            return true;
        } catch (\PDOException $e) {
            // Log error but don't throw - caller can decide how to handle
            error_log(sprintf(
                "[EventRepository] Failed to mark event %s as published after retries: %s",
                $messageId,
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Mark an outbox event as pending for retry
     *
     * Updates the status to 'pending' when RabbitMQ publishing fails.
     * This allows the pg2event cronjob to pick up and retry the event later.
     *
     * Handles database errors gracefully by logging and returning false.
     * If update fails, event stays in 'processing' status and dispatcher
     * can retry based on created_at timestamp.
     *
     * Automatically retries transient failures (connection errors, deadlocks) up to 3 times.
     *
     * @param string $messageId Message ID (UUID)
     * @param string $schema Database schema name
     * @param string|null $errorMessage Optional error message to store in last_error column
     * @return bool True if marked successfully, false if database update failed after retries
     */
    public function markAsPending(string $messageId, string $schema = 'public', ?string $errorMessage = null): bool
    {
        try {
            $this->executeWithRetry(function ($pdo) use ($messageId, $schema, $errorMessage) {
                $stmt = $pdo->prepare("
                    UPDATE {$schema}.outbox
                    SET status = 'pending',
                        last_error = ?
                    WHERE message_id = ?
                ");

                $stmt->execute([$errorMessage, $messageId]);
                return true;
            });
            return true;
        } catch (\PDOException $e) {
            // Log error but don't throw - caller can decide how to handle
            error_log(sprintf(
                "[EventRepository] Failed to mark event %s as pending after retries: %s. Original error: %s",
                $messageId,
                $e->getMessage(),
                $errorMessage ?? 'none'
            ));
            return false;
        }
    }

    /**
     * Mark an outbox event as failed
     *
     * Updates the status to 'failed' when RabbitMQ publishing fails permanently.
     * Reserved for future use (e.g., events that exceeded max retry attempts).
     *
     * Automatically retries transient failures (connection errors, deadlocks) up to 3 times.
     *
     * @param string $messageId Message ID (UUID)
     * @param string $schema Database schema name
     * @param string|null $errorMessage Optional error message to store in last_error column
     * @return void
     * @throws \RuntimeException if update fails after retries
     */
    public function markAsFailed(string $messageId, string $schema = 'public', ?string $errorMessage = null): void
    {
        try {
            $this->executeWithRetry(function ($pdo) use ($messageId, $schema, $errorMessage) {
                $stmt = $pdo->prepare("
                    UPDATE {$schema}.outbox
                    SET status = 'failed',
                        last_error = ?
                    WHERE message_id = ?
                ");

                $stmt->execute([$errorMessage, $messageId]);
                return true;
            });
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                "Failed to mark event as failed after retries: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Check if a message exists in the outbox table
     *
     * Returns true if the message exists regardless of status (published, pending, processing, or failed).
     * If the message exists, it means it's already been submitted and will be/has been processed.
     *
     * @param string $messageId Message ID (UUID)
     * @param string $producerService Producer service name
     * @param string $schema Database schema name
     * @return bool True if message exists in outbox, false otherwise
     */
    public function existsInOutbox(string $messageId, string $producerService, string $schema = 'public'): bool
    {
        try {
            $pdo = $this->getConnection();

            $stmt = $pdo->prepare("
                SELECT 1
                FROM {$schema}.outbox
                WHERE message_id = ? AND producer_service = ?
                LIMIT 1
            ");

            $stmt->execute([$messageId, $producerService]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Message exists in outbox - already submitted for publishing
            return $result !== false;
        } catch (\PDOException $e) {
            // If table doesn't exist or other DB error, fail open (allow publishing)
            // This prevents blocking all messages if outbox table is not set up
            return false;
        }
    }

    /**
     * Check if a message exists in the inbox table
     *
     * Returns true if the message exists regardless of status (processing, processed, or failed).
     * If the message exists, it means it's already been received and will be/has been processed.
     *
     * @param string $messageId Message ID (UUID)
     * @param string $consumerService Consumer service name
     * @param string $schema Database schema name
     * @return bool True if message exists in inbox, false otherwise
     */
    public function existsInInbox(string $messageId, string $consumerService, string $schema = 'public'): bool
    {
        try {
            $pdo = $this->getConnection();

            $stmt = $pdo->prepare("
                SELECT 1
                FROM {$schema}.inbox
                WHERE message_id = ? AND consumer_service = ?
                LIMIT 1
            ");

            $stmt->execute([$messageId, $consumerService]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Message exists in inbox - already received for processing
            return $result !== false;
        } catch (\PDOException $e) {
            // If table doesn't exist or other DB error, fail open (allow processing)
            // This prevents blocking all messages if inbox table is not set up
            return false;
        }
    }

    /**
     * Check if a message exists in the inbox table
     *
     * Returns true if the message exists regardless of status (processing, processed, or failed).
     * If the message exists, it means it's already been received and will be/has been processed.
     *
     * @param string $messageId Message ID (UUID)
     * @param string $consumerService Consumer service name
     * @param string $schema Database schema name
     * @return bool True if message exists in inbox, false otherwise
     */
    public function existsInInboxAndProcessed(string $messageId, string $consumerService, string $schema = 'public'): bool
    {
        try {
            $pdo = $this->getConnection();

            $stmt = $pdo->prepare("
                SELECT 1
                FROM {$schema}.inbox
                WHERE message_id = ? AND consumer_service = ? AND status = 'processed'
                LIMIT 1
            ");

            $stmt->execute([$messageId, $consumerService]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Message exists in inbox - already received for processing
            return $result !== false;
        } catch (\PDOException $e) {
            // If table doesn't exist or other DB error, fail open (allow processing)
            // This prevents blocking all messages if inbox table is not set up
            return false;
        }
    }

    /**
     * Insert a message into the inbox table
     *
     * Handles duplicate key violations gracefully (returns false for idempotent behavior).
     * This allows multiple concurrent calls with the same message_id to succeed idempotently.
     *
     * @param string $consumerService Consumer service name
     * @param string $producerService Producer service name
     * @param string $eventType Event type (routing key)
     * @param string $messageBody Message body as JSON
     * @param string $messageId Required message ID (UUID) for tracking
     * @param string $schema Database schema name
     * @param string $status Initial status (default: 'processing')
     * @param int $retryCount Initial retry count (default: 1 for first attempt)
     * @return bool True if inserted successfully, false if already exists (duplicate message_id)
     * @throws \RuntimeException if insert fails for reasons other than duplicate key
     */
    public function insertInbox(
        string $consumerService,
        string $producerService,
        string $eventType,
        string $messageBody,
        string $messageId,
        string $schema = 'public',
        string $status = 'processing',
        int $retryCount = 1
    ): bool {
        try {
            $pdo = $this->getConnection();

            $stmt = $pdo->prepare("
                INSERT INTO {$schema}.inbox (
                    consumer_service,
                    producer_service,
                    event_type,
                    message_body,
                    message_id,
                    status,
                    retry_count
                ) VALUES (?, ?, ?, ?::jsonb, ?, ?, ?)
            ");

            $stmt->execute([
                $consumerService,
                $producerService,
                $eventType,
                $messageBody,
                $messageId,
                $status,
                $retryCount,
            ]);

            return true; // Insert successful
        } catch (\PDOException $e) {
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();

            // Handle duplicate key violation (race condition - another worker inserted same message_id)
            if ($errorCode === '23505' ||
                stripos($errorMessage, 'duplicate key') !== false ||
                stripos($errorMessage, 'unique constraint') !== false) {
                // Message already exists - return false to indicate idempotent skip
                return false;
            }

            // Other database errors are critical
            throw new \RuntimeException(
                "Failed to insert into inbox table: " . $errorMessage,
                0,
                $e
            );
        }
    }

    /**
     * Mark an inbox event as processed
     *
     * Updates the status to 'processed' and sets processed_at timestamp
     * for the event with the given message_id.
     *
     * Handles database errors gracefully by logging and returning false.
     * This prevents exceptions when the event was successfully processed and ACKed
     * but the database update fails (accept duplicate risk over false failure).
     *
     * Automatically retries transient failures (connection errors, deadlocks) up to 3 times.
     *
     * @param string $messageId Message ID (UUID)
     * @param string $consumerService Consumer service name
     * @param string $schema Database schema name
     * @return bool True if marked successfully, false if database update failed after retries
     */
    public function markInboxAsProcessed(string $messageId, string $consumerService, string $schema = 'public'): bool
    {
        try {
            $this->executeWithRetry(function ($pdo) use ($messageId, $consumerService, $schema) {
                $stmt = $pdo->prepare("
                    UPDATE {$schema}.inbox
                    SET status = 'processed',
                        processed_at = NOW()
                    WHERE message_id = ? AND consumer_service = ?
                ");

                $stmt->execute([$messageId, $consumerService]);
                return true;
            });
            return true;
        } catch (\PDOException $e) {
            // Log error but don't throw - caller can decide how to handle
            error_log(sprintf(
                "[EventRepository] Failed to mark inbox event %s as processed after retries: %s",
                $messageId,
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Mark an inbox event as failed
     *
     * Updates the status to 'failed' when message processing fails.
     *
     * Handles database errors gracefully by logging and returning false.
     * This prevents exceptions when the event was sent to DLX successfully
     * but the database update fails (accept missing failure tracking over event loss).
     *
     * Automatically retries transient failures (connection errors, deadlocks) up to 3 times.
     *
     * @param string $messageId Message ID (UUID)
     * @param string $consumerService Consumer service name
     * @param string $schema Database schema name
     * @param string|null $errorMessage Optional error message to store in last_error column
     * @return bool True if marked successfully, false if database update failed after retries
     */
    public function markInboxAsFailed(string $messageId, string $consumerService, string $schema = 'public', ?string $errorMessage = null): bool
    {
        try {
            $this->executeWithRetry(function ($pdo) use ($messageId, $consumerService, $schema, $errorMessage) {
                $stmt = $pdo->prepare("
                    UPDATE {$schema}.inbox
                    SET status = 'failed',
                        last_error = ?
                    WHERE message_id = ? AND consumer_service = ?
                ");

                $stmt->execute([$errorMessage, $messageId, $consumerService]);
                return true;
            });
            return true;
        } catch (\PDOException $e) {
            // Log error but don't throw - caller can decide how to handle
            error_log(sprintf(
                "[EventRepository] Failed to mark inbox event %s as failed after retries: %s. Original error: %s",
                $messageId,
                $e->getMessage(),
                $errorMessage ?? 'none'
            ));
            return false;
        }
    }

    /**
     * Update retry count for an inbox event
     *
     * Increments the retry_count column when a message is being retried.
     * This tracks how many times the message processing has been attempted.
     *
     * Handles database errors gracefully by logging and returning false.
     * This prevents exceptions during retry attempts.
     *
     * Automatically retries transient failures (connection errors, deadlocks) up to 3 times.
     *
     * @param string $messageId Message ID (UUID)
     * @param string $consumerService Consumer service name
     * @param int $retryCount Current retry count to set
     * @param string $schema Database schema name
     * @return bool True if updated successfully, false if database update failed after retries
     */
    public function updateInboxRetryCount(string $messageId, string $consumerService, int $retryCount, string $schema = 'public'): bool
    {
        try {
            $this->executeWithRetry(function ($pdo) use ($messageId, $consumerService, $retryCount, $schema) {
                $stmt = $pdo->prepare("
                    UPDATE {$schema}.inbox
                    SET retry_count = ?
                    WHERE message_id = ? AND consumer_service = ?
                ");

                $stmt->execute([$retryCount, $messageId, $consumerService]);
                return true;
            });
            return true;
        } catch (\PDOException $e) {
            // Log error but don't throw - caller can decide how to handle
            error_log(sprintf(
                "[EventRepository] Failed to update retry_count for inbox event %s after retries: %s",
                $messageId,
                $e->getMessage()
            ));
            return false;
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
