<?php

namespace AlexFN\NanoService;

use AlexFN\NanoService\Clients\LoggerFactory;
use AlexFN\NanoService\Clients\StatsDClient\Enums\EventExitStatusTag;
use AlexFN\NanoService\Clients\StatsDClient\Enums\EventRetryStatusTag;
use AlexFN\NanoService\Clients\StatsDClient\StatsDClient;
use AlexFN\NanoService\Contracts\NanoConsumer as NanoConsumerContract;
use AlexFN\NanoService\Enums\ConsumerErrorType;
use AlexFN\NanoService\Validators\MessageValidator;
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
    protected array $handlers = [];

    // ⚠️ IMPORTANT: Do NOT redeclare $statsD property here!
    // It is inherited from parent NanoServiceClass as protected.
    // Redeclaring as private causes fatal error in PHP 8.x
    // See docs/BUGFIXES.md - "Duplicate Property Visibility" for details
    // REMOVED (2026-01-20): private StatsDClient $statsD;

    private LoggerInterface $logger;

    private MessageValidator $messageValidator;

    private $callback;

    private $catchCallback;

    private $failedCallback;

    private $debugCallback;

    private array $events;

    private int $tries = 3;

    private int|array $backoff = 0;

    private bool $rabbitMQInitialized = false;

    private int $outageSleepSeconds = 30;

    // Connection lifecycle tracking (opt-in via env vars)
    private int $connectionMaxJobs = 0;         // Max jobs before reconnect (0 = disabled)
    private int $connectionJobsProcessed = 0;   // Counter of jobs processed since connection init

    private static bool $shutdownRegistered = false;

    /**
     * Initialize components that don't require RabbitMQ connection
     * Safe to call before connection is established
     *
     * @return void
     * @throws \RuntimeException If required environment variables are missing
     */
    private function initSafeComponents(): void
    {
        // Validate required environment variables BEFORE starting consumption
        if (!isset($_ENV['AMQP_MICROSERVICE_NAME'])) {
            throw new \RuntimeException("Missing required environment variables: AMQP_MICROSERVICE_NAME");
        }

        if (!isset($_ENV['DB_BOX_SCHEMA'])) {
            throw new \RuntimeException("Missing required environment variables: DB_BOX_SCHEMA");
        }

        // Initialize StatsD - auto-configures from environment (only if not already initialized)
        if (!$this->statsD) {
            $this->statsD = new StatsDClient();
        }

        // Initialize logger (only if not already set)
        if (!isset($this->logger)) {
            $this->logger = LoggerFactory::getInstance();
        }

        // Initialize message validator (only if not already set)
        if (!isset($this->messageValidator)) {
            $this->messageValidator = new MessageValidator(
                $this->statsD,
                $this->logger,
                $this->getEnv(self::MICROSERVICE_NAME)
            );
        }

        // Load connection lifecycle config (opt-in, disabled by default)
        // Controls when to reinitialize RabbitMQ + DB connections based on job count
        $this->connectionMaxJobs = (int)($_ENV['CONNECTION_MAX_JOBS'] ?? getenv('CONNECTION_MAX_JOBS') ?: 0);

        // Log if lifecycle management is enabled
        if ($this->connectionMaxJobs > 0) {
            $this->logger->info('[NanoConsumer] Connection lifecycle management enabled', [
                'max_jobs' => $this->connectionMaxJobs,
                'microservice' => $_ENV['AMQP_MICROSERVICE_NAME'] ?? 'unknown',
            ]);
        }
    }

    /**
     * Initialize RabbitMQ queues and bindings
     * Requires active RabbitMQ connection
     * Idempotent - safe to call multiple times (RabbitMQ declarations are idempotent)
     *
     * @return void
     * @throws \PhpAmqpLib\Exception\AMQPRuntimeException If RabbitMQ operations fail
     */
    private function initRabbitMQ(): void
    {
        $this->initialWithFailedQueue();

        $exchange = $this->getNamespace($this->exchange);

        foreach ($this->events as $event) {
            $this->getChannel()->queue_bind($this->queue, $exchange, $event);
        }

        // Bind system events
        foreach (array_keys($this->handlers) as $systemEvent) {
            $this->getChannel()->queue_bind($this->queue, $exchange, $systemEvent);
        }

        // Initialize connection tracking (if lifecycle management enabled)
        if ($this->connectionMaxJobs > 0) {
            $this->connectionJobsProcessed = 0;

            $this->logger->debug('[NanoConsumer] Connection lifecycle tracking started', [
                'max_jobs' => $this->connectionMaxJobs,
                'microservice' => $this->getEnv(self::MICROSERVICE_NAME),
            ]);
        }

        $this->rabbitMQInitialized = true;
    }

    public function init(): NanoConsumerContract
    {
        // Call safe components first (ENV validation, StatsD, logger)
        $this->initSafeComponents();

        // Then RabbitMQ initialization (queues, bindings)
        $this->initRabbitMQ();

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

    public function outageSleep(int $seconds): NanoConsumerContract
    {
        $this->outageSleepSeconds = $seconds;

        return $this;
    }

    /**
     * Start consuming messages with circuit breaker for RabbitMQ outages
     *
     * Implements resilient consumption pattern:
     * 1. Initialize safe components (ENV validation, logger, StatsD)
     * 2. Set up circuit breaker callbacks
     * 3. Enter main consumption loop with retry logic
     * 4. Check RabbitMQ health before each iteration
     * 5. Initialize RabbitMQ queues/bindings
     * 6. Start consumption
     * 7. On RabbitMQ error: reset, log, track metrics, retry
     * 8. On other errors: log and crash (let orchestration restart)
     *
     * @param callable $callback Message handler callback
     * @param callable|null $debugCallback Optional debug handler
     * @return void
     * @throws ErrorException
     */
    public function consume(callable $callback, ?callable $debugCallback = null): void
    {
        $this->callback = $callback;
        $this->debugCallback = $debugCallback;

        // Phase 1: Initialize safe components (no RabbitMQ needed)
        $this->initSafeComponents();

        // Phase 2: Set up circuit breaker callbacks
        $this->setOutageCallbacks(
            fn(int $sleepSeconds) => $this->logger->warning('[NanoConsumer] Entering outage mode', [
                'sleep_seconds' => $sleepSeconds,
                'microservice' => $this->getEnv(self::MICROSERVICE_NAME),
            ]),
            fn() => $this->logger->info('[NanoConsumer] Connection restored', [
                'microservice' => $this->getEnv(self::MICROSERVICE_NAME),
            ])
        );

        // Phase 3: Main consumption loop with circuit breaker
        while (true) {
            try {
                // Check connection health
                if (!$this->ensureConnectionOrSleep($this->outageSleepSeconds)) {
                    continue;
                }

                // Initialize RabbitMQ resources (queues, bindings)
                if (!$this->rabbitMQInitialized) {
                    $this->initRabbitMQ();
                }

                // Check if connection lifecycle thresholds exceeded (after RabbitMQ is ready)
                if ($this->shouldReinitializeConnection()) {
                    $this->reinitializeConnections();
                    // Skip this iteration, let loop restart with fresh connections
                    continue;
                }

                // Set up consumption
                $this->getChannel()->basic_qos(0, 1, 0);
                $this->getChannel()->basic_consume(
                    $this->queue,
                    $this->getEnv(self::MICROSERVICE_NAME),
                    false, false, false, false,
                    [$this, 'consumeCallback']
                );

                // Register shutdown handler (once)
                if (!self::$shutdownRegistered) {
                    register_shutdown_function([$this, 'shutdown'], $this->getChannel(), $this->getConnection());
                    self::$shutdownRegistered = true;
                }

                // Start blocking consumption
                $this->getChannel()->consume();

            } catch (\PhpAmqpLib\Exception\AMQPHeartbeatMissedException $e) {
                $this->handleRabbitMQError($e, ConsumerErrorType::CONNECTION_ERROR);
            } catch (\PhpAmqpLib\Exception\AMQPConnectionClosedException $e) {
                $this->handleRabbitMQError($e, ConsumerErrorType::CONNECTION_ERROR);
            } catch (\PhpAmqpLib\Exception\AMQPChannelClosedException $e) {
                $this->handleRabbitMQError($e, ConsumerErrorType::CHANNEL_ERROR);
            } catch (\PhpAmqpLib\Exception\AMQPSocketException $e) {
                $this->handleRabbitMQError($e, ConsumerErrorType::IO_ERROR);
            } catch (\PhpAmqpLib\Exception\AMQPIOException $e) {
                $this->handleRabbitMQError($e, ConsumerErrorType::IO_ERROR);
            } catch (\PhpAmqpLib\Exception\AMQPRuntimeException $e) {
                $this->handleRabbitMQError($e, ConsumerErrorType::CONSUME_SETUP_ERROR);
            } catch (\Throwable $e) {
                // Non-RabbitMQ error - crash consumer
                $this->logger->critical('[NanoConsumer] Unexpected error, crashing', [
                    'microservice' => $this->getEnv(self::MICROSERVICE_NAME),
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                ]);
                throw $e;
            }
        }
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
        if (!$this->messageValidator->validateMessage($message)) {
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
     * Insert message into inbox with atomic claim mechanism
     *
     * Implements safe concurrent processing by using atomic claim pattern:
     * 1. Try to INSERT new message (for first-time delivery)
     * 2. If INSERT fails (duplicate key), try to atomically CLAIM existing row
     * 3. Only proceed if INSERT succeeded OR CLAIM succeeded
     * 4. Skip processing if row exists but is actively locked by another worker
     *
     * This fixes Issue 1 - Concurrent Processing via existsInInbox Bypass
     * Reference: /Users/begimov/Downloads/CONCURRENCY_ISSUES.md
     *
     * @param EventRepository $repository Event repository instance
     * @param NanoServiceMessage $message Message to insert
     * @param string $consumerService Consumer service name
     * @param string $schema Database schema
     * @param string $messageBody Raw message body
     * @return bool True if should proceed with processing (inserted OR claimed), false if message is locked by another worker
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
            // First, try to INSERT the message (for new/first-time delivery)
            // Use atomic locking by setting locked_at=NOW() and locked_by=workerId
            $initialRetryCount = $message->getRetryCount() + 1; // First attempt = 1, retries = 2, 3, etc.
            $workerId = $this->getWorkerId();

            $inserted = $repository->insertInbox(
                $consumerService,                  // consumer_service
                $message->getPublisherName(),      // producer_service
                $message->getEventName(),          // event_type
                $messageBody,                      // message_body (full message)
                $messageId,                        // message_id
                $schema,                           // schema
                'processing',                      // status
                $initialRetryCount,                // retry_count
                $workerId                          // locked_by (for atomic locking)
            );

            // If INSERT succeeded, we own the message - proceed with processing
            if ($inserted) {
                return true;
            }

            // INSERT failed (duplicate key) - message already exists
            // Check if it's already processed (skip processing)
            if ($repository->existsInInboxAndProcessed($messageId, $consumerService, $schema)) {
                $this->logger->info("[NanoConsumer] Message already processed, skipping:", [
                    'message_id' => $messageId,
                    'consumer_service' => $consumerService,
                ]);
                return false; // Skip processing, but caller should ACK
            }

            // Message exists but not processed yet - try to claim it atomically
            // Only claims messages in 'processing' status with stale/NULL locked_at
            // Common scenarios:
            // - RabbitMQ redelivered because connection dropped, worker crashed, or heartbeat timeout
            // - Original worker may still be running (race condition) or already crashed
            // - Legacy inbox row without lock (before v7.2.0)
            //
            // Note: Does NOT claim 'failed' messages (exhausted retries)
            // If admin republishes failed messages from DLQ, they should reset inbox status first
            $staleThresholdSeconds = (int)($_ENV['INBOX_LOCK_STALE_THRESHOLD'] ?? getenv('INBOX_LOCK_STALE_THRESHOLD') ?: 300);

            $claimed = $repository->tryClaimInboxMessage(
                $messageId,
                $consumerService,
                $workerId,
                $staleThresholdSeconds,
                $schema
            );

            if ($claimed) {
                $this->logger->info("[NanoConsumer] Claimed existing message for processing:", [
                    'message_id' => $messageId,
                    'worker_id' => $workerId,
                ]);
                return true; // Claim succeeded - proceed with processing
            }

            // Claim failed - message is actively being processed by another worker
            $this->logger->info("[NanoConsumer] Message is locked by another worker, skipping:", [
                'message_id' => $messageId,
                'consumer_service' => $consumerService,
            ]);
            return false; // Skip processing, caller should ACK to avoid redelivery loop

        } catch (\RuntimeException $e) {
            // Critical DB error (not duplicate) - track metrics, log, and rethrow
            $this->statsD->increment('rmq_consumer_error_total', [
                'nano_service_name' => $consumerService,
                'event_name' => $message->getEventName(),
                'error_type' => ConsumerErrorType::INBOX_INSERT_ERROR->getValue(),
            ]);

            $this->logger->error("[NanoConsumer] Failed to insert/claim inbox for message:", [
                'message_id' => $messageId,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get worker identifier for inbox locking
     *
     * Uses POD_NAME (Kubernetes) if available, otherwise falls back to hostname:pid
     *
     * @return string Worker identifier (e.g., "myservice-worker-abc123" or "hostname:12345")
     */
    private function getWorkerId(): string
    {
        // Try Kubernetes POD_NAME first (most reliable in K8s)
        $podName = $_ENV['POD_NAME'] ?? getenv('POD_NAME');
        if ($podName) {
            return $podName;
        }

        // Fallback to hostname:pid
        $hostname = gethostname() ?: 'unknown';
        $pid = getmypid() ?: 0;
        return "{$hostname}:{$pid}";
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

        // Increment job counter for connection lifecycle tracking
        if ($this->connectionMaxJobs > 0) {
            $this->connectionJobsProcessed++;
        }

        // Check if connection lifecycle thresholds exceeded (after processing message)
        // Cancel consumer to break out of blocking consume() and trigger reinit in main loop
        if ($this->shouldReinitializeConnection()) {
            try {
                // Cancel this consumer to exit the blocking consume() call
                // This will cause consume() to return and the main loop to reinitialize
                $this->getChannel()->basic_cancel($this->getEnv(self::MICROSERVICE_NAME));
            } catch (\Throwable $e) {
                // Log but don't fail - reinit will happen anyway
                $this->logger->debug('[NanoConsumer] Error cancelling consumer for reinit', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
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

    /**
     * Handle RabbitMQ infrastructure errors with logging and metrics
     * Resets connection state and allows retry loop to continue
     *
     * @param \Throwable $e The RabbitMQ exception
     * @param ConsumerErrorType $errorType Error type for metrics
     * @return void
     */
    private function handleRabbitMQError(\Throwable $e, ConsumerErrorType $errorType): void
    {
        $this->logger->error('[NanoConsumer] RabbitMQ error, will retry', [
            'microservice' => $this->getEnv(self::MICROSERVICE_NAME),
            'error' => $e->getMessage(),
            'error_class' => get_class($e),
            'error_type' => $errorType->getValue(),
        ]);

        // Track connection error metric
        $this->statsD->increment('rmq_consumer_error_total', [
            'nano_service_name' => $this->getEnv(self::MICROSERVICE_NAME),
            'error_type' => $errorType->getValue(),
        ]);

        // Reset connection state (forces reconnection on next iteration)
        $this->reset();
        $this->rabbitMQInitialized = false;

        // Reset connection tracking on RabbitMQ error
        $this->connectionJobsProcessed = 0;

        // Brief pause before retry (let connection state settle)
        sleep(2);
    }

    /**
     * Check if connection lifecycle threshold has been exceeded
     *
     * Checks job-based threshold to determine if connections should be reinitialized.
     *
     * @return bool True if threshold exceeded, false otherwise
     */
    private function shouldReinitializeConnection(): bool
    {
        // Feature disabled
        if ($this->connectionMaxJobs === 0) {
            return false;
        }

        // Check jobs threshold
        if ($this->connectionJobsProcessed >= $this->connectionMaxJobs) {
            $this->logger->info('[NanoConsumer] Connection max jobs exceeded, will reinitialize', [
                'jobs_processed' => $this->connectionJobsProcessed,
                'max_jobs' => $this->connectionMaxJobs,
                'microservice' => $this->getEnv(self::MICROSERVICE_NAME),
            ]);
            return true;
        }

        return false;
    }

    /**
     * Reinitialize both RabbitMQ and database connections
     *
     * Called when connection lifecycle threshold is exceeded (max jobs processed).
     * Closes existing connections and resets state to force fresh connections.
     *
     * @return void
     */
    private function reinitializeConnections(): void
    {
        $startTime = microtime(true);

        try {
            // Emit metric before reinitializing
            if ($this->statsD && $this->statsD->isEnabled()) {
                $this->statsD->increment('rmq_consumer_connection_reinit_total', [
                    'nano_service_name' => $this->getEnv(self::MICROSERVICE_NAME),
                    'reason' => 'max_jobs',
                ]);
            }

            $this->logger->info('[NanoConsumer] Reinitializing connections', [
                'jobs_processed' => $this->connectionJobsProcessed,
                'max_jobs' => $this->connectionMaxJobs,
                'microservice' => $this->getEnv(self::MICROSERVICE_NAME),
            ]);

            // 1. Reset RabbitMQ connections (calls reset() which clears static shared connection)
            $this->reset();
            $this->rabbitMQInitialized = false;

            // 2. Reset database connection
            EventRepository::getInstance()->resetConnection();

            // 3. Reset tracking counters
            $this->connectionJobsProcessed = 0;

            $duration = microtime(true) - $startTime;

            $this->logger->info('[NanoConsumer] Connections reinitialized successfully', [
                'duration_ms' => round($duration * 1000, 2),
                'microservice' => $this->getEnv(self::MICROSERVICE_NAME),
            ]);

            // Emit success metric
            if ($this->statsD && $this->statsD->isEnabled()) {
                $this->statsD->timing('rmq_consumer_connection_reinit_duration_ms',
                    (int)round($duration * 1000),
                    ['nano_service_name' => $this->getEnv(self::MICROSERVICE_NAME)]
                );
            }

        } catch (\Throwable $e) {
            // Log error but don't throw - let circuit breaker handle it
            $this->logger->error('[NanoConsumer] Failed to reinitialize connections', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'microservice' => $this->getEnv(self::MICROSERVICE_NAME),
            ]);

            // Emit error metric
            if ($this->statsD && $this->statsD->isEnabled()) {
                $this->statsD->increment('rmq_consumer_error_total', [
                    'nano_service_name' => $this->getEnv(self::MICROSERVICE_NAME),
                    'error_type' => 'connection_reinit_error',
                ]);
            }

            // Reset state anyway to force retry
            $this->reset();
            $this->rabbitMQInitialized = false;
            $this->connectionJobsProcessed = 0;
        }
    }

}
