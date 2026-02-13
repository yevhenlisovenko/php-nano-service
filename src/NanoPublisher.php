<?php

namespace AlexFN\NanoService;

use AlexFN\NanoService\Clients\LoggerFactory;
use AlexFN\NanoService\Clients\StatsDClient\StatsDClient;
use AlexFN\NanoService\Contracts\NanoPublisher as NanoPublisherContract;
use AlexFN\NanoService\Contracts\NanoServiceMessage as NanoServiceMessageContract;
use AlexFN\NanoService\Enums\OutboxErrorType;
use AlexFN\NanoService\Enums\PublishErrorType;
use Exception;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;

/**
 * RabbitMQ message publisher with metrics instrumentation
 *
 * Publishes messages to RabbitMQ exchanges with automatic StatsD metrics collection
 * for monitoring publish rates, latency, errors, and payload sizes.
 *
 * @package AlexFN\NanoService
 */
class NanoPublisher extends NanoServiceClass implements NanoPublisherContract
{
    private NanoServiceMessageContract $message;

    private ?int $delay = null;

    private array $meta = [];

    // ⚠️ IMPORTANT: Do NOT redeclare $statsD property here!
    // It is inherited from parent NanoServiceClass as protected.
    // Redeclaring as private causes fatal error in PHP 8.x
    // See docs/BUGFIXES.md - "Duplicate Property Visibility" for details
    // REMOVED (2026-01-20): private StatsDClient $statsD;

    private LoggerInterface $logger;

    /**
     * Initialize publisher with StatsD client
     *
     * @param array $config Configuration array
     * @throws Exception
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->statsD = new StatsDClient();
        $this->logger = LoggerFactory::getInstance();
    }

    public function setMeta(array $data): NanoPublisherContract
    {
        $this->meta = array_merge($this->meta, $data);

        return $this;
    }

    public function setMessage(NanoServiceMessageContract $message): NanoPublisherContract
    {
        $this->message = $message;

        return $this;
    }

    public function delay(int $delay): NanoPublisherContract
    {
        $this->delay = $delay;

        return $this;
    }

    /**
     * Prepare message for publishing (sets event, app_id, delay headers, and meta)
     *
     * @param string $event Event name (routing key)
     * @return void
     */
    protected function prepareMessageForPublish(string $event): void
    {
        $this->message->setEvent($event);
        $this->message->set('app_id', $this->getNamespace($this->getEnv(self::MICROSERVICE_NAME)));

        if ($this->delay) {
            $this->message->set('application_headers', new AMQPTable(['x-delay' => $this->delay]));
        }

        if ($this->meta) {
            $this->message->addMeta($this->meta);
        }
    }

    /**
     * Publish a message to PostgreSQL outbox table with immediate RabbitMQ attempt
     *
     * Default publish method - implements transactional outbox pattern for reliable message publishing.
     * Architecture: Service → PostgreSQL → [immediate publish] → RabbitMQ
     *                                    ↓ (if fails)
     *                               pg2event dispatcher (retry)
     *
     * Outbox Pattern Flow:
     * 1. Check if message already exists in outbox (by message_id) - idempotency check
     * 2. If exists, return true (event already handled, prevents duplicates)
     * 3. Insert into outbox with status='processing' (about to publish to RabbitMQ)
     * 4. Attempt immediate publish to RabbitMQ
     * 5a. Success: Mark as 'published', return true
     * 5b. Failure: Mark as 'pending', return false (dispatcher will retry)
     *
     * Stored Message Structure (JSONB):
     * - payload (message->getPayload())
     * - meta (message->getMeta())
     * - status (message data)
     * - system (message data including trace_id)
     *
     * Error Handling Strategy (At-Least-Once Delivery):
     * - DB insert fails (non-duplicate): Exception thrown → event lost (caller notified)
     * - DB insert fails (duplicate key): Return true → idempotent (race condition handled)
     * - RabbitMQ fails: Mark as 'pending' → return false → dispatcher retries
     * - RabbitMQ succeeds, DB update fails: Log warning, return true → accept duplicate risk
     * - Status update to 'pending' fails: Log warning, return false → dispatcher can retry based on 'processing' status
     *
     * Trade-off: Prefers duplicates over lost events (event consumers must be idempotent)
     *
     * @param string $event Event name (routing key)
     * @return bool True if published to RabbitMQ successfully, false if RabbitMQ publish failed
     * @throws \RuntimeException Only if critical database operations fail (connection, insert non-duplicate)
     */
    public function publish(string $event): bool
    {
        $this->validateRequiredEnvironmentVariables();
        $this->validateMessage();

        // Prepare message
        $this->prepareMessageForPublish($event);

        // Get message ID for tracking
        $messageId = $this->message->getId();

        $producerService = $_ENV['AMQP_MICROSERVICE_NAME'];
        
        $schema = $_ENV['DB_BOX_SCHEMA'];

        $repository = EventRepository::getInstance();

        // Check if message already exists in outbox
        if ($repository->existsInOutbox($messageId, $producerService, $schema)) {
            // Message already in outbox - return true and skip (idempotent behavior)
            return true;
        }

        // Get full message body (contains payload, meta, status, system)
        $messageBody = $this->message->getBody();

        // Insert message into outbox with 'processing' status
        // insertOutbox handles duplicate key violations and returns false if already exists
        $inserted = $repository->insertOutbox(
            $producerService,                  // producer_service
            $event,                            // event_type (routing key)
            $messageBody,                      // message_body (full NanoServiceMessage as JSONB)
            $messageId,                        // message_id (UUID for tracking)
            null,                              // partition_key (optional)
            $schema,                           // schema
            'processing'                       // status (currently publishing to RabbitMQ)
        );

        if (!$inserted) {
            // Message already exists (race condition). Return true for idempotent behavior
            return true;
        }

        // Store event trace (best effort, non-blocking)
        $this->insertEventTraceBestEffort($messageId, $event, $producerService, $schema);

        // Attempt immediate publish to RabbitMQ
        try {
            $this->publishToRabbit($event);
            $this->updateOutboxAfterPublish($messageId, $producerService, $event, $schema, $repository);
            return true;

        } catch (Exception $e) {
            // RabbitMQ publish failed
            $exceptionClass = get_class($e);
            $exceptionMessage = $e->getMessage();
            $errorMessage = $exceptionClass . ($exceptionMessage ? ': ' . $exceptionMessage : '');
            
            $this->updateOutboxAfterFailure($messageId, $producerService, $event, $schema, $errorMessage, $repository);

            // Return false - RabbitMQ publish failed, event will be retried by dispatcher
            return false;
        }
    }

    /**
     * Publish a message directly to RabbitMQ with metrics instrumentation
     *
     * WARNING: This is the old direct-publish method, renamed for backward compatibility.
     * Used by pg2event dispatcher to relay outbox messages to RabbitMQ.
     *
     * For normal service usage, use publish() which writes to PostgreSQL outbox instead.
     *
     * Tracks:
     * - rmq_publish_total: Total publish attempts
     * - rmq_publish_success_total: Successful publishes
     * - rmq_publish_error_total: Failed publishes with error type
     * - rmq_publish_duration_ms: Publish latency
     * - rmq_payload_bytes: Message payload size
     *
     * @param string $event Event name (routing key)
     * @return void
     * @throws Exception
     */
    public function publishToRabbit(string $event): void
    {
        // Validate required environment variables
        if (!isset($_ENV['AMQP_MICROSERVICE_NAME'])) {
            throw new \RuntimeException("Missing required environment variable: AMQP_MICROSERVICE_NAME");
        }

        // Validate message is set
        if (!isset($this->message)) {
            throw new \RuntimeException("Message must be set before publishing. Call setMessage() first.");
        }

        // Prepare message
        $this->prepareMessageForPublish($event);

        // Metrics tags
        $tags = [
            'service' => $this->getEnv(self::MICROSERVICE_NAME),
            'event_name' => $event,
            'env' => $this->getEnvironment(),
        ];

        // Start timing
        $timerKey = 'publish_' . $event . '_' . uniqid();
        $this->statsD->startTimer($timerKey);

        // Increment total publish attempts
        $sampleRate = $this->statsD->getSampleRate('ok_events');
        $this->statsD->increment('rmq_publish_total', $tags, $sampleRate);

        try {
            // Measure payload size
            $payloadSize = strlen($this->message->getBody());
            $this->statsD->histogram(
                'rmq_payload_bytes',
                $payloadSize,
                $tags
            );

            // Perform publish
            $exchange = $this->getNamespace($this->exchange);
            $this->getChannel()->basic_publish($this->message, $exchange, $event);

            // Record success metrics
            $duration = $this->statsD->endTimer($timerKey);
            if ($duration !== null) {
                $this->statsD->timing(
                    'rmq_publish_duration_ms',
                    $duration,
                    $tags
                );
            }
            $this->statsD->increment('rmq_publish_success_total', $tags, $sampleRate);

        } catch (AMQPChannelClosedException $e) {
            $this->handlePublishError($e, $tags, PublishErrorType::CHANNEL_ERROR, $timerKey);
            $this->reset();
            throw $e;
        } catch (AMQPConnectionClosedException | AMQPIOException $e) {
            $this->handlePublishError($e, $tags, PublishErrorType::CONNECTION_ERROR, $timerKey);
            $this->reset();
            throw $e;
        } catch (AMQPTimeoutException $e) {
            $this->handlePublishError($e, $tags, PublishErrorType::TIMEOUT, $timerKey);
            $this->reset();
            throw $e;
        } catch (\JsonException $e) {
            $this->handlePublishError($e, $tags, PublishErrorType::ENCODING_ERROR, $timerKey);
            throw $e;
        } catch (Exception $e) {
            // Categorize exception type
            $errorType = $this->categorizeException($e);
            $this->handlePublishError($e, $tags, $errorType, $timerKey);
            throw $e;
        } finally {
            // Cleanup timer if not already ended
            $this->statsD->endTimer($timerKey);
        }

        // DO NOT close shared connection - it will be reused by next job in this worker
        // Connection will be closed naturally when worker process terminates
    }

    /**
     * Handle publish errors with metrics tracking
     *
     * @param Exception $e Exception that occurred
     * @param array $tags Base metric tags
     * @param PublishErrorType $errorType Categorized error type
     * @param string $timerKey Timer identifier
     * @return void
     */
    private function handlePublishError(
        Exception $e,
        array $tags,
        PublishErrorType $errorType,
        string $timerKey
    ): void {
        $errorTags = array_merge($tags, ['error_type' => $errorType->getValue()]);

        // Always track errors at 100% sample rate
        $this->statsD->increment('rmq_publish_error_total', $errorTags, 1.0);

        // Record error duration
        $duration = $this->statsD->endTimer($timerKey);
        if ($duration !== null) {
            $errorTags['status'] = 'failed';
            $this->statsD->timing('rmq_publish_duration_ms', $duration, $errorTags);
        }
    }

    /**
     * Categorize exception into PublishErrorType
     *
     * @param Exception $e Exception to categorize
     * @return PublishErrorType Error type
     */
    private function categorizeException(Exception $e): PublishErrorType
    {
        $message = strtolower($e->getMessage());

        // Check for connection-related errors
        if (strpos($message, 'connection') !== false ||
            strpos($message, 'socket') !== false ||
            strpos($message, 'network') !== false) {
            return PublishErrorType::CONNECTION_ERROR;
        }

        // Check for channel-related errors
        if (strpos($message, 'channel') !== false) {
            return PublishErrorType::CHANNEL_ERROR;
        }

        // Check for timeout errors
        if (strpos($message, 'timeout') !== false ||
            strpos($message, 'timed out') !== false) {
            return PublishErrorType::TIMEOUT;
        }

        // Check for encoding/serialization errors
        if (strpos($message, 'json') !== false ||
            strpos($message, 'encode') !== false ||
            strpos($message, 'serialize') !== false) {
            return PublishErrorType::ENCODING_ERROR;
        }

        // Check for configuration errors
        if (strpos($message, 'config') !== false ||
            strpos($message, 'exchange') !== false ||
            strpos($message, 'routing') !== false) {
            return PublishErrorType::CONFIG_ERROR;
        }

        // Unknown error type
        return PublishErrorType::UNKNOWN;
    }

    /**
     * Get application environment
     *
     * @return string Environment name (production, staging, e2e, local)
     */
    private function getEnvironment(): string
    {
        return $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
    }

    /**
     * Validate required environment variables for outbox publishing
     *
     * @return void
     * @throws \RuntimeException If required environment variables are not set
     */
    private function validateRequiredEnvironmentVariables(): void
    {
        if (!isset($_ENV['AMQP_MICROSERVICE_NAME'])) {
            throw new \RuntimeException("Missing required environment variables: AMQP_MICROSERVICE_NAME");
        }

        if (!isset($_ENV['DB_BOX_SCHEMA'])) {
            throw new \RuntimeException("Missing required environment variables: DB_BOX_SCHEMA");
        }
    }

    /**
     * Validate message and its ID before publishing
     *
     * @return void
     * @throws \RuntimeException If message is not set or message ID is empty
     */
    private function validateMessage(): void
    {
        // Check message is set
        if (!isset($this->message)) {
            $this->statsD->increment('rmq_publisher_error_total', [
                'service' => $this->getEnv(self::MICROSERVICE_NAME),
                'error_type' => OutboxErrorType::VALIDATION_ERROR->getValue(),
            ]);

            throw new \RuntimeException("Message must be set before publishing. Call setMessage() first.");
        }

        // Check message ID is not empty
        $messageId = $this->message->getId();
        if (empty($messageId)) {
            $this->statsD->increment('rmq_publisher_error_total', [
                'service' => $this->getEnv(self::MICROSERVICE_NAME),
                'error_type' => OutboxErrorType::VALIDATION_ERROR->getValue(),
            ]);

            throw new \RuntimeException("Message ID cannot be empty. Ensure message has a valid ID.");
        }
    }

    /**
     * Store event trace for distributed tracing (best effort, non-blocking)
     *
     * Tracks which parent events led to this event being published.
     * Failures are logged but do not block the publish operation - tracing is
     * for observability, not critical path.
     *
     * @param string $messageId Message identifier
     * @param string $event Event name (routing key)
     * @param string $producerService Producer service name
     * @param string $schema Database schema
     * @return void
     */
    private function insertEventTraceBestEffort(
        string $messageId,
        string $event,
        string $producerService,
        string $schema
    ): void {
        try {
            $traceIds = $this->message->getTraceId();
            $repository = EventRepository::getInstance();
            $repository->insertEventTrace($messageId, $traceIds, $schema);
        } catch (\Exception $e) {
            // Log error but don't block publishing - tracing is observability, not critical path
            $this->statsD->increment('rmq_publisher_error_total', [
                'service' => $producerService,
                'event_name' => $event,
                'error_type' => OutboxErrorType::TRACE_INSERT_ERROR->getValue(),
            ]);

            $this->logger->error("[NanoPublisher] Failed to insert event trace:", [
                'message_id' => $messageId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update outbox status after successful RabbitMQ publish
     *
     * Marks the message as published in the outbox. If the update fails after retries,
     * logs a critical warning but returns true (RabbitMQ publish did succeed).
     * This prevents false failures but accepts duplicate risk when dispatcher retries.
     *
     * @param string $messageId Message identifier
     * @param string $producerService Producer service name
     * @param string $event Event name (routing key)
     * @param string $schema Database schema
     * @param EventRepository $repository Repository instance
     * @return void
     */
    private function updateOutboxAfterPublish(
        string $messageId,
        string $producerService,
        string $event,
        string $schema,
        EventRepository $repository
    ): void {
        // Mark as published (EventRepository handles retries internally)
        $marked = $repository->markAsPublished($messageId, $schema);

        if (!$marked) {
            // Why: If RabbitMQ publish succeeds but DB update fails after retries,
            // we return true (publish did succeed) and log a critical warning.
            // This prevents false failures but accepts duplicate risk when dispatcher retries.
            $this->statsD->increment('rmq_publisher_error_total', [
                'service' => $producerService,
                'event_name' => $event,
                'error_type' => OutboxErrorType::OUTBOX_UPDATE_ERROR->getValue(),
            ]);

            $this->logger->error("[NanoPublisher] CRITICAL: Event published to RabbitMQ but not marked as published (duplicate risk):", [
                'message_id' => $messageId,
            ]);
        }
    }

    /**
     * Update outbox status after failed RabbitMQ publish
     *
     * Marks the message as pending for dispatcher retry. If the update fails,
     * the event stays in 'processing' status and dispatcher will retry based on timestamp.
     *
     * @param string $messageId Message identifier
     * @param string $producerService Producer service name
     * @param string $event Event name (routing key)
     * @param string $schema Database schema
     * @param string $errorMessage Error message from RabbitMQ publish failure
     * @param EventRepository $repository Repository instance
     * @return void
     */
    private function updateOutboxAfterFailure(
        string $messageId,
        string $producerService,
        string $event,
        string $schema,
        string $errorMessage,
        EventRepository $repository
    ): void {
        // Mark as pending for dispatcher retry, logs internally if it fails
        $marked = $repository->markAsPending($messageId, $schema, $errorMessage);

        if (!$marked) {
            // Event stays in 'processing' status, dispatcher will retry based on timestamp
            $this->statsD->increment('rmq_publisher_error_total', [
                'service' => $producerService,
                'event_name' => $event,
                'error_type' => OutboxErrorType::OUTBOX_UPDATE_ERROR->getValue(),
            ]);

            $this->logger->error("[NanoPublisher] Event failed to publish and not marked as pending:", [
                'message_id' => $messageId,
                'original_error' => $errorMessage,
            ]);
        }
    }
}
