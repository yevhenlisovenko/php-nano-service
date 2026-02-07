<?php

namespace AlexFN\NanoService;

use AlexFN\NanoService\Clients\LoggerFactory;
use AlexFN\NanoService\Clients\StatsDClient\Enums\EventExitStatusTag;
use AlexFN\NanoService\Clients\StatsDClient\Enums\EventRetryStatusTag;
use AlexFN\NanoService\Clients\StatsDClient\StatsDClient;
use AlexFN\NanoService\Contracts\NanoConsumer as NanoConsumerContract;
use AlexFN\NanoService\Enums\ConsumerErrorType;
use AlexFN\NanoService\SystemHandlers\SystemPing;
use ErrorException;
use Exception;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * RabbitMQ Consumer with delayed message exchange support
 *
 * IMPORTANT: Do NOT redeclare properties from parent class (NanoServiceClass)
 * - $statsD is inherited as protected from parent
 * - Redeclaring with stricter visibility (private) causes fatal error
 * - See docs/BUGFIXES.md for details
 */
class NanoConsumer extends NanoServiceClass implements NanoConsumerContract
{
    const FAILED_POSTFIX = '.failed';
    protected array $handlers = [
        // 'system.ping.1' => SystemPing::class,
    ];

    // ⚠️ IMPORTANT: Do NOT redeclare $statsD property here!
    // It is inherited from parent NanoServiceClass as protected.
    // Redeclaring as private causes fatal error in PHP 8.x
    // See docs/BUGFIXES.md - "Duplicate Property Visibility" for details
    // REMOVED (2026-01-20): private StatsDClient $statsD;

    private LoggerInterface $logger;

    private $callback;

    private $catchCallback;

    private $failedCallback;

    private $debugCallback;

    private array $events;

    private int $tries = 3;

    private int|array $backoff = 0;

    public function init(): NanoConsumerContract
    {
        // Validate required environment variables BEFORE starting consumption
        // This prevents consumer from crashing on first message
        if (!isset($_ENV['AMQP_MICROSERVICE_NAME'])) {
            throw new \RuntimeException("Missing required environment variables: AMQP_MICROSERVICE_NAME");
        }

        if (!isset($_ENV['DB_BOX_SCHEMA'])) {
            throw new \RuntimeException("Missing required environment variables: DB_BOX_SCHEMA");
        }

        // Initialize StatsD - auto-configures from environment
        // Will be disabled if STATSD_ENABLED != 'true'
        $this->statsD = new StatsDClient();
        $this->logger = LoggerFactory::getInstance();

        $this->initialWithFailedQueue();

        $exchange = $this->getNamespace($this->exchange);

        foreach ($this->events as $event) {
            $this->getChannel()->queue_bind($this->queue, $exchange, $event);
        }

        // Bind system events
        foreach (array_keys($this->handlers) as $systemEvent) {
            $this->getChannel()->queue_bind($this->queue, $exchange, $systemEvent);
        }

        return $this;
    }

    /** Deprecated */
    private function initialQueue(): void
    {
        $this->queue($this->getEnv(self::MICROSERVICE_NAME));
    }

    private function initialWithFailedQueue(): void
    {
        $queue = $this->getEnv(self::MICROSERVICE_NAME);
        $dlx = $this->getNamespace($queue).self::FAILED_POSTFIX;

        $this->queue($queue, new AMQPTable([
            'x-dead-letter-exchange' => $dlx,
        ]));
        $this->createExchange($this->queue, 'x-delayed-message', new AMQPTable([
            'x-delayed-type' => 'topic',
        ]));

        $this->createQueue($dlx);
        $this->getChannel()->queue_bind($this->queue, $this->queue, '#');
    }

    public function events(string ...$events): NanoConsumerContract
    {
        $this->events = $events;

        return $this;
    }

    public function tries(int $attempts): NanoConsumerContract
    {
        $this->tries = $attempts;

        return $this;
    }

    public function backoff(int|array $seconds): NanoConsumerContract
    {
        $this->backoff = $seconds;

        return $this;
    }

    /**
     * @throws ErrorException
     */
    public function consume(callable $callback, ?callable $debugCallback = null): void
    {
        $this->init();

        $this->callback = $callback;
        $this->debugCallback = $debugCallback;

        $this->getChannel()->basic_qos(0, 1, 0);
        $this->getChannel()->basic_consume($this->queue, $this->getEnv(self::MICROSERVICE_NAME), false, false, false, false, [$this, 'consumeCallback']);
        register_shutdown_function([$this, 'shutdown'], $this->getChannel(), $this->getConnection());
        $this->getChannel()->consume();
    }

    public function catch(callable $callback): NanoConsumerContract
    {
        $this->catchCallback = $callback;

        return $this;
    }

    public function failed(callable $callback): NanoConsumerContract
    {
        $this->failedCallback = $callback;

        return $this;
    }

    /**
     * Validate incoming message structure and required fields
     *
     * Validates:
     * - type (event name) - required
     * - message_id - required
     * - app_id (publisher name) - required
     * - Valid JSON payload
     *
     * @param AMQPMessage $message RabbitMQ message
     * @return bool True if valid, false if invalid
     */
    private function validateMessage(AMQPMessage $message): bool
    {
        $errors = [];

        // Check type (event name)
        if (!$message->has('type') || empty($message->get('type'))) {
            $errors[] = 'Missing or empty type';
        }

        // Check message_id
        if (!$message->has('message_id') || empty($message->get('message_id'))) {
            $errors[] = 'Missing or empty message_id';
        }

        // Check app_id (publisher name)
        if (!$message->has('app_id') || empty($message->get('app_id'))) {
            $errors[] = 'Missing or empty app_id';
        }

        // Check valid JSON payload
        $body = $message->getBody();
        if (!empty($body)) {
            json_decode($body);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = 'Invalid JSON payload: ' . json_last_error_msg();
            }
        }

        if (!empty($errors)) {
            // Track validation error
            $this->statsD->increment('rmq_consumer_error_total', [
                'nano_service_name' => $this->getEnv(self::MICROSERVICE_NAME),
                'error_type' => ConsumerErrorType::VALIDATION_ERROR->getValue(),
            ]);

            $this->logger->error('[NanoConsumer] Invalid message received, rejecting:', [
                'errors' => $errors,
                'message_id' => $message->has('message_id') ? $message->get('message_id') : 'unknown',
                'type' => $message->has('type') ? $message->get('type') : 'unknown',
                'app_id' => $message->has('app_id') ? $message->get('app_id') : 'unknown',
                'body_preview' => substr($body, 0, 200), // First 200 chars for debugging
            ]);
            return false;
        }

        return true;
    }

    /**
     * Process incoming RabbitMQ message with enhanced metrics and inbox pattern
     *
     * Implements inbox pattern for idempotent message processing:
     * 1. Check if message already exists in inbox (by message_id)
     * 2. If yes, ACK and skip (prevents duplicate processing)
     * 3. If no, insert into inbox and process
     * 4. On success, mark as processed in inbox
     *
     * Tracks:
     * - event_started_count: Event consumption started
     * - event_processed_duration: Event processing time
     * - rmq_consumer_payload_bytes: Message payload size
     * - rmq_consumer_dlx_total: Dead-letter queue events
     * - rmq_consumer_ack_failed_total: ACK failures
     *
     * @param AMQPMessage $message RabbitMQ message
     * @return void
     */
    public function consumeCallback(AMQPMessage $message): void
    {
        // Validate message structure before processing
        if (!$this->validateMessage($message)) {
            // Invalid message - ACK and skip to prevent reprocessing
            $message->ack();
            return;
        }

        $newMessage = $this->createNanoServiceMessage($message);
        
        $key = $message->get('type');

        // Check system handlers
        if ($this->handleSystemEvent($key, $newMessage, $message)) {
            return;
        }

        // Environment variables validated in init() - safe to use here
        $messageId = $newMessage->getId();
        $consumerService = $_ENV['AMQP_MICROSERVICE_NAME'];
        $schema = $_ENV['DB_BOX_SCHEMA'];

        $repository = EventRepository::getInstance();

        // Setup metrics and tracking
        $tags = $this->setupMetricsAndTracking($newMessage, $message);
        $retryCount = $newMessage->getRetryCount() + 1;
        $eventRetryStatusTag = $this->getRetryTag($retryCount);

        $this->statsD->start($tags, $eventRetryStatusTag);

        try {
            // Check if message already exists in inbox and already processed
            if ($repository->existsInInboxAndProcessed($messageId, $consumerService, $schema)) {
                // Message already in inbox and processed - ACK and skip (idempotent behavior)
                $message->ack();
                return;
            }

            // Insert into inbox with status 'processing' and handle duplicates
            if (!$this->insertMessageToInbox($repository, $newMessage, $consumerService, $schema, $message->getBody())) {
                // Race condition - another worker inserted it between existence check and insert
                // ACK and skip (idempotent behavior)
                $message->ack();
                return;
            }

            $this->executeUserCallback($newMessage);

            $this->handleSuccessfulProcessing(
                $message,
                $messageId,
                $consumerService,
                $newMessage->getEventName(),
                $schema,
                $eventRetryStatusTag,
                $repository,
                $tags
            );

        } catch (Throwable $exception) {

            $retryCount = $newMessage->getRetryCount() + 1;

            if ($retryCount < $this->tries) {
                $this->handleRetryableFailure(
                    $exception,
                    $newMessage,
                    $message,
                    $key,
                    $consumerService,
                    $schema,
                    $eventRetryStatusTag,
                    $repository
                );
            } else {
                $this->handleFinalFailure(
                    $exception,
                    $newMessage,
                    $message,
                    $tags,
                    $consumerService,
                    $schema,
                    $eventRetryStatusTag,
                    $repository
                );
            }
        }
    }

    /**
     * @throws Throwable
     */
    public function shutdown(): void
    {
        $this->getChannel()->close();
        $this->getConnection()->close();
    }

    /**
     * Handle system event if it matches registered handlers
     *
     * @param string $key Event routing key
     * @param NanoServiceMessage $message Wrapped message
     * @param AMQPMessage $originalMessage Original AMQP message
     * @return bool True if system event was handled, false otherwise
     */
    private function handleSystemEvent(
        string $key,
        NanoServiceMessage $message,
        AMQPMessage $originalMessage
    ): bool {
        if (!array_key_exists($key, $this->handlers)) {
            return false;
        }

        (new $this->handlers[$key]())($message);
        $originalMessage->ack();
        return true;
    }

    /**
     * Insert message into inbox with status 'processing'
     * Handles race conditions via database unique constraint
     *
     * NOTE: Check-then-insert pattern creates TOCTOU race condition where multiple workers
     * could check simultaneously and both attempt insertion. This is handled gracefully:
     * 1. Database unique constraint on (message_id, consumer_service) prevents duplicates
     * 2. insertInbox() returns false on duplicate key (caught and handled below)
     * 3. Losing worker gets false return value (caller should ACK and exit)
     *
     * This approach is safe because idempotency ensures duplicate processing is prevented,
     * even if ACK fails and message is redelivered.
     *
     * @param EventRepository $repository Event repository instance
     * @param NanoServiceMessage $message Message to insert
     * @param string $consumerService Consumer service name
     * @param string $schema Database schema
     * @param string $messageBody Raw message body
     * @return bool True if inserted successfully, false if race condition detected
     * @throws \RuntimeException on critical DB errors (caller should not ACK)
     */
    private function insertMessageToInbox(
        EventRepository $repository,
        NanoServiceMessage $message,
        string $consumerService,
        string $schema,
        string $messageBody
    ): bool {
        $messageId = $message->getId();

        try {
            // If message already exists in inbox, continue processing (it's in 'processing' state)
            if ($repository->existsInInbox($messageId, $consumerService, $schema)) {
                return true;
            }

            // Message doesn't exist - insert it
            $initialRetryCount = $message->getRetryCount() + 1; // First attempt = 1, retries = 2, 3, etc.

            $inserted = $repository->insertInbox(
                $consumerService,                  // consumer_service
                $message->getPublisherName(),      // producer_service
                $message->getEventName(),          // event_type
                $messageBody,                      // message_body (full message)
                $messageId,                        // message_id
                $schema,                           // schema
                'processing',                      // status
                $initialRetryCount                 // retry_count
            );

            // Return false if race condition (insert failed), true if successful
            return $inserted;
        } catch (\RuntimeException $e) {
            // Critical DB error (not duplicate) - track metrics, log, and rethrow
            $this->statsD->increment('rmq_consumer_error_total', [
                'nano_service_name' => $consumerService,
                'event_name' => $message->getEventName(),
                'error_type' => ConsumerErrorType::INBOX_INSERT_ERROR->getValue(),
            ]);

            $this->logger->error("[NanoConsumer] Failed to insert inbox for message:", [
                'message_id' => $messageId,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function getBackoff(int $retryCount): int
    {
        if (is_array($this->backoff)) {
            $count = $retryCount - 1;
            $lastIndex = count($this->backoff) - 1;
            $index = min($count, $lastIndex);

            return $this->backoff[$index] * 1000;
        }

        return $this->backoff * 1000;
    }

    private function getRetryTag(int $retryCount): EventRetryStatusTag
    {
        return match ($retryCount) {
            1 => EventRetryStatusTag::FIRST,
            $this->tries => EventRetryStatusTag::LAST,
            default => EventRetryStatusTag::RETRY,
        };
    }

    /**
     * Create NanoServiceMessage wrapper from AMQP message
     *
     * @param AMQPMessage $message Original AMQP message
     * @return NanoServiceMessage Wrapped message
     */
    private function createNanoServiceMessage(AMQPMessage $message): NanoServiceMessage
    {
        $newMessage = new NanoServiceMessage($message->getBody(), $message->get_properties());
        $newMessage->setDeliveryTag($message->getDeliveryTag());
        $newMessage->setChannel($message->getChannel());

        return $newMessage;
    }

    /**
     * Setup metrics tracking for event processing
     * Tracks payload size and returns base tags for subsequent metrics
     *
     * @param NanoServiceMessage $message Message being processed
     * @param AMQPMessage $originalMessage Original AMQP message
     * @return array Base metric tags (nano_service_name, event_name)
     */
    private function setupMetricsAndTracking(
        NanoServiceMessage $message,
        AMQPMessage $originalMessage
    ): array {
        $tags = [
            'nano_service_name' => $this->getEnv(self::MICROSERVICE_NAME),
            'event_name' => $message->getEventName()
        ];

        // Track payload size
        $payloadSize = strlen($originalMessage->getBody());
        $this->statsD->histogram(
            'rmq_consumer_payload_bytes',
            $payloadSize,
            $tags,
            $this->statsD->getSampleRate('payload')
        );

        return $tags;
    }

    /**
     * Execute user-defined callback (normal or debug mode)
     *
     * @param NanoServiceMessage $message Message to process
     * @throws Throwable Any exception from user callback
     */
    private function executeUserCallback(NanoServiceMessage $message): void
    {
        $callback = $message->getDebug() && is_callable($this->debugCallback)
            ? $this->debugCallback
            : $this->callback;

        call_user_func($callback, $message);
    }

    /**
     * Handle successful message processing
     * 1. ACK message (critical - remove from RabbitMQ)
     * 2. Mark as processed in inbox (best effort)
     * 3. Record success metrics
     *
     * @param AMQPMessage $originalMessage Original AMQP message to ACK
     * @param string $messageId Message UUID
     * @param string $consumerService Consumer service name
     * @param string $eventName Event name
     * @param string $schema Database schema
     * @param EventRetryStatusTag $eventRetryStatusTag Retry status for metrics
     * @param EventRepository $repository Event repository instance
     * @param array $tags Base metric tags (nano_service_name, event_name)
     * @throws Throwable If ACK fails (critical error)
     */
    private function handleSuccessfulProcessing(
        AMQPMessage $originalMessage,
        string $messageId,
        string $consumerService,
        string $eventName,
        string $schema,
        EventRetryStatusTag $eventRetryStatusTag,
        EventRepository $repository,
        array $tags
    ): void {
        // Try to ACK message first (critical - remove from RabbitMQ)
        try {
            $originalMessage->ack();
        } catch (Throwable $e) {
            // Track ACK failure
            $this->statsD->increment('rmq_consumer_ack_failed_total', $tags);
            // Critical - if ACK fails, don't mark as processed
            throw $e;
        }

        // Mark as processed in inbox (best effort - non-blocking)
        try {
            $marked = $repository->markInboxAsProcessed($messageId, $consumerService, $schema);

            if (!$marked) {
                // Event processed and ACKed but not marked in DB - duplicate risk
                // Message stays in "processing" state, might be picked up by cleanup job
                $this->statsD->increment('rmq_consumer_error_total', [
                    'nano_service_name' => $consumerService,
                    'event_name' => $eventName,
                    'error_type' => ConsumerErrorType::INBOX_UPDATE_ERROR->getValue(),
                ]);

                $this->logger->error("[NanoConsumer] Event processed and ACKed but not marked as processed (duplicate risk):", [
                    'message_id' => $messageId,
                ]);
            }
        } catch (Throwable $e) {
            // Database error - log but don't fail (message already ACK'd and processed)
            $this->statsD->increment('rmq_consumer_error_total', [
                'nano_service_name' => $consumerService,
                'event_name' => $eventName,
                'error_type' => ConsumerErrorType::INBOX_UPDATE_ERROR->getValue(),
            ]);

            $this->logger->error("[NanoConsumer] Database error marking as processed (non-blocking):", [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->statsD->end(EventExitStatusTag::SUCCESS, $eventRetryStatusTag);
    }

    /**
     * Handle retryable failure by republishing message with delay
     * 1. Execute user's catch callback
     * 2. Republish message to retry queue with backoff delay
     * 3. Update retry count in inbox
     *
     * @param Throwable $exception The exception that caused the failure
     * @param NanoServiceMessage $message Message that failed
     * @param AMQPMessage $originalMessage Original AMQP message
     * @param string $key Routing key
     * @param string $consumerService Consumer service name
     * @param string $schema Database schema
     * @param EventRetryStatusTag $eventRetryStatusTag Retry status for metrics
     * @param EventRepository $repository Event repository instance
     * @throws Throwable If republish fails (critical - don't ACK)
     */
    private function handleRetryableFailure(
        Throwable $exception,
        NanoServiceMessage $message,
        AMQPMessage $originalMessage,
        string $key,
        string $consumerService,
        string $schema,
        EventRetryStatusTag $eventRetryStatusTag,
        EventRepository $repository
    ): void {
        $messageId = $message->getId();
        $retryCount = $message->getRetryCount() + 1;

        // Execute user's catch callback (non-critical)
        $this->executeCatchCallback($exception, $message, $messageId, $consumerService);

        // Republish for retry (critical)
        try {
            $this->republishForRetry($message, $originalMessage, $key, $retryCount);

        } catch (Throwable $e) {
            // Republish failed - don't ACK, let RabbitMQ redeliver
            $this->statsD->increment('rmq_consumer_error_total', [
                'nano_service_name' => $consumerService,
                'event_name' => $message->getEventName(),
                'error_type' => ConsumerErrorType::RETRY_REPUBLISH_ERROR->getValue(),
            ]);

            $this->logger->error("[NanoConsumer] Retry republish failed for message:", [
                'message_id' => $messageId,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Update retry count in inbox (best effort - non-blocking)
        try {
            $this->updateInboxRetryCount($messageId, $consumerService, $retryCount, $schema, $message->getEventName(), $repository);
        } catch (Throwable $e) {
            // Database error - log but don't fail the retry (message already republished)
            $this->statsD->increment('rmq_consumer_error_total', [
                'nano_service_name' => $consumerService,
                'event_name' => $message->getEventName(),
                'error_type' => ConsumerErrorType::INBOX_UPDATE_ERROR->getValue(),
            ]);

            $this->logger->error("[NanoConsumer] Database error updating retry count (non-blocking):", [
                'message_id' => $messageId,
                'retry_count' => $retryCount,
                'error' => $e->getMessage(),
            ]);
        }
        $this->statsD->end(EventExitStatusTag::FAILED, $eventRetryStatusTag);
    }

    /**
     * Execute user-defined catch callback for retry scenarios
     * Swallows exceptions from user code (non-critical)
     *
     * @param Throwable $exception Original exception
     * @param NanoServiceMessage $message Message being retried
     * @param string $messageId Message UUID
     * @param string $consumerService Consumer service name
     */
    private function executeCatchCallback(
        Throwable $exception,
        NanoServiceMessage $message,
        string $messageId,
        string $consumerService
    ): void {
        if (!is_callable($this->catchCallback)) {
            return;
        }

        try {
            call_user_func($this->catchCallback, $exception, $message);
        } catch (Throwable $e) {
            $this->statsD->increment('rmq_consumer_error_total', [
                'nano_service_name' => $consumerService,
                'event_name' => $message->getEventName(),
                'error_type' => ConsumerErrorType::USER_CALLBACK_ERROR->getValue(),
            ]);

            $this->logger->error("[NanoConsumer] catchCallback failed for message:", [
                'message_id' => $messageId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Republish message to retry queue with delay header
     *
     * @param NanoServiceMessage $message Message to republish
     * @param AMQPMessage $originalMessage Original message to ACK
     * @param string $key Routing key
     * @param int $retryCount Current retry count
     * @throws Throwable on publish or ACK failure
     */
    private function republishForRetry(
        NanoServiceMessage $message,
        AMQPMessage $originalMessage,
        string $key,
        int $retryCount
    ): void {
        // Republish for retry
        //
        // NOTE: If basic_publish() succeeds but ack() fails, message will be duplicated
        // (retry message + redelivered original). This is acceptable because:
        // 1. Inbox pattern provides idempotency - duplicate will be detected and skipped
        // 2. Prefer duplicate processing (caught by idempotency) over lost messages
        // 3. ACK failures are rare (network issues, channel closed)
        $headers = new AMQPTable([
            'x-delay' => $this->getBackoff($retryCount),
            'x-retry-count' => $retryCount
        ]);
        $message->set('application_headers', $headers);
        $this->getChannel()->basic_publish($message, $this->queue, $key);
        $originalMessage->ack();
    }

    /**
     * Update retry count in inbox table (best effort)
     *
     * @param string $messageId Message UUID
     * @param string $consumerService Consumer service name
     * @param int $retryCount New retry count
     * @param string $schema Database schema
     * @param string $eventName Event name
     * @param EventRepository $repository Event repository instance
     */
    private function updateInboxRetryCount(
        string $messageId,
        string $consumerService,
        int $retryCount,
        string $schema,
        string $eventName,
        EventRepository $repository
    ): void {
        $updated = $repository->updateInboxRetryCount($messageId, $consumerService, $retryCount, $schema);

        if (!$updated) {
            // Failed to update retry count in DB, but message is republished
            $this->statsD->increment('rmq_consumer_error_total', [
                'nano_service_name' => $consumerService,
                'event_name' => $eventName,
                'error_type' => ConsumerErrorType::INBOX_UPDATE_ERROR->getValue(),
            ]);

            $this->logger->error("[NanoConsumer] Failed to update retry_count for message:", [
                'message_id' => $messageId,
                'retry_count' => $retryCount,
            ]);
        }

        // Note: Don't update inbox status on retry
        // Message stays in "processing" until final success/failure
    }

    /**
     * Handle final failure after max retries exceeded
     * 1. Execute user's failed callback
     * 2. Publish message to Dead Letter Exchange (DLX)
     * 3. Mark as failed in inbox
     *
     * @param Throwable $exception Final exception
     * @param NanoServiceMessage $message Failed message
     * @param AMQPMessage $originalMessage Original AMQP message
     * @param array $tags Base metric tags
     * @param string $consumerService Consumer service name
     * @param string $schema Database schema
     * @param EventRetryStatusTag $eventRetryStatusTag Retry status for metrics
     * @param EventRepository $repository Event repository instance
     * @throws Throwable If DLX publish fails (critical - don't ACK)
     */
    private function handleFinalFailure(
        Throwable $exception,
        NanoServiceMessage $message,
        AMQPMessage $originalMessage,
        array $tags,
        string $consumerService,
        string $schema,
        EventRetryStatusTag $eventRetryStatusTag,
        EventRepository $repository
    ): void {
        $messageId = $message->getId();
        $retryCount = $message->getRetryCount() + 1;

        // Execute user's failed callback (non-critical)
        $this->executeFailedCallback($exception, $message, $messageId, $consumerService);

        // Track DLX event
        $dlxTags = array_merge($tags, ['reason' => 'max_retries_exceeded']);
        $this->statsD->increment('rmq_consumer_dlx_total', $dlxTags);

        // Publish to DLX (critical)
        try {
            $this->publishToDLX($message, $originalMessage, $exception, $retryCount);

        } catch (Throwable $e) {
            // DLX publish failed - don't ACK, let RabbitMQ redeliver
            $this->statsD->increment('rmq_consumer_error_total', [
                'nano_service_name' => $consumerService,
                'event_name' => $message->getEventName(),
                'error_type' => ConsumerErrorType::DLX_PUBLISH_ERROR->getValue(),
            ]);

            $this->logger->error("[NanoConsumer] DLX publish failed for message:", [
                'message_id' => $messageId,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Mark as failed in inbox (best effort - non-blocking)
        try {
            $this->markInboxAsFailed($messageId, $consumerService, $schema, $exception, $message->getEventName(), $repository);
        } catch (Throwable $e) {
            // Database error - log but don't fail the DLX flow (message already sent to DLX)
            $this->statsD->increment('rmq_consumer_error_total', [
                'nano_service_name' => $consumerService,
                'event_name' => $message->getEventName(),
                'error_type' => ConsumerErrorType::INBOX_UPDATE_ERROR->getValue(),
            ]);

            $this->logger->error("[NanoConsumer] Database error marking as failed (non-blocking):", [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->statsD->end(EventExitStatusTag::FAILED, $eventRetryStatusTag);
    }

    /**
     * Execute user-defined failed callback for DLX scenarios
     * Swallows exceptions from user code (non-critical)
     *
     * @param Throwable $exception Original exception
     * @param NanoServiceMessage $message Message being sent to DLX
     * @param string $messageId Message UUID
     * @param string $consumerService Consumer service name
     */
    private function executeFailedCallback(
        Throwable $exception,
        NanoServiceMessage $message,
        string $messageId,
        string $consumerService
    ): void {
        if (!is_callable($this->failedCallback)) {
            return;
        }

        try {
            call_user_func($this->failedCallback, $exception, $message);
        } catch (Throwable $e) {
            $this->statsD->increment('rmq_consumer_error_total', [
                'nano_service_name' => $consumerService,
                'event_name' => $message->getEventName(),
                'error_type' => ConsumerErrorType::USER_CALLBACK_ERROR->getValue(),
            ]);

            $this->logger->error("[NanoConsumer] failedCallback failed for message:", [
                'message_id' => $messageId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Publish message to Dead Letter Exchange
     *
     * @param NanoServiceMessage $message Message to publish
     * @param AMQPMessage $originalMessage Original message to ACK
     * @param Throwable $exception Failure exception
     * @param int $retryCount Final retry count
     * @throws Throwable on publish or ACK failure
     */
    private function publishToDLX(
        NanoServiceMessage $message,
        AMQPMessage $originalMessage,
        Throwable $exception,
        int $retryCount
    ): void {
        // Publish to DLX
        //
        // NOTE: If basic_publish() succeeds but ack() fails, message will be duplicated
        // (DLX message + redelivered original). This is acceptable because:
        // 1. Inbox pattern provides idempotency - duplicate will be detected and skipped
        // 2. Prefer duplicate in DLX (can be manually processed) over lost failed messages
        // 3. ACK failures are rare (network issues, channel closed)
        $headers = new AMQPTable([
            'x-retry-count' => $retryCount
        ]);
        $message->set('application_headers', $headers);
        $message->setConsumerError($exception->getMessage());
        $this->getChannel()->basic_publish($message, '', $this->queue . self::FAILED_POSTFIX);
        $originalMessage->ack();
    }

    /**
     * Mark message as failed in inbox table (best effort)
     *
     * @param string $messageId Message UUID
     * @param string $consumerService Consumer service name
     * @param string $schema Database schema
     * @param Throwable $exception Failure exception
     * @param string $eventName Event name
     * @param EventRepository $repository Event repository instance
     */
    private function markInboxAsFailed(
        string $messageId,
        string $consumerService,
        string $schema,
        Throwable $exception,
        string $eventName,
        EventRepository $repository
    ): void {
        $exceptionClass = get_class($exception);
        $exceptionMessage = $exception->getMessage();
        $errorMessage = $exceptionClass . ($exceptionMessage ? ': ' . $exceptionMessage : '');

        $marked = $repository->markInboxAsFailed($messageId, $consumerService, $schema, $errorMessage);

        if (!$marked) {
            // Event sent to DLX but not marked as failed in DB
            $this->statsD->increment('rmq_consumer_error_total', [
                'nano_service_name' => $consumerService,
                'event_name' => $eventName,
                'error_type' => ConsumerErrorType::INBOX_UPDATE_ERROR->getValue(),
            ]);

            $this->logger->error("[NanoConsumer] Event sent to DLX but not marked as failed in inbox:", [
                'message_id' => $messageId,
            ]);
        }
    }
}
