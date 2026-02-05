<?php

namespace AlexFN\NanoService;

use AlexFN\NanoService\Clients\StatsDClient\Enums\EventExitStatusTag;
use AlexFN\NanoService\Clients\StatsDClient\Enums\EventRetryStatusTag;
use AlexFN\NanoService\Clients\StatsDClient\StatsDClient;
use AlexFN\NanoService\Contracts\NanoConsumer as NanoConsumerContract;
use AlexFN\NanoService\SystemHandlers\SystemPing;
use ErrorException;
use Exception;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
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
        'system.ping.1' => SystemPing::class,
    ];

    // ⚠️ IMPORTANT: Do NOT redeclare $statsD property here!
    // It is inherited from parent NanoServiceClass as protected.
    // Redeclaring as private causes fatal error in PHP 8.x
    // See docs/BUGFIXES.md - "Duplicate Property Visibility" for details
    // REMOVED (2026-01-20): private StatsDClient $statsD;

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
        $newMessage = new NanoServiceMessage($message->getBody(), $message->get_properties());
        $newMessage->setDeliveryTag($message->getDeliveryTag());
        $newMessage->setChannel($message->getChannel());

        $key = $message->get('type');

        // Check system handlers
        if (array_key_exists($key, $this->handlers)) {
            (new $this->handlers[$key]())($newMessage);
            $message->ack();
            return;
        }

        // Environment variables validated in init() - safe to use here
        $messageId = $newMessage->getId();
        $consumerService = $_ENV['AMQP_MICROSERVICE_NAME'];
        $schema = $_ENV['DB_BOX_SCHEMA'];

        $repository = EventRepository::getInstance();

        // Check if message already exists in inbox and already processed
        if ($repository->existsInInboxAndProcessed($messageId, $consumerService, $schema)) {
            // Message already in inbox and processed - ACK and skip (idempotent behavior)
            $message->ack();
            return;
        }

        // Insert into inbox with status 'processing' and handle duplicates
        //
        // NOTE: Check-then-insert pattern creates TOCTOU race condition where multiple workers
        // could check simultaneously and both attempt insertion. This is handled gracefully:
        // 1. Database unique constraint on (message_id, consumer_service) prevents duplicates
        // 2. insertInbox() returns false on duplicate key (caught and handled below)
        // 3. Losing worker ACKs and exits (idempotent - message already being processed)
        //
        // This approach is safe because idempotency ensures duplicate processing is prevented,
        // even if ACK fails and message is redelivered.
        try {
            if (!$repository->existsInInbox($messageId, $consumerService, $schema)) {
                $initialRetryCount = $newMessage->getRetryCount() + 1; // First attempt = 1, retries = 2, 3, etc.

                $inserted = $repository->insertInbox(
                    $consumerService,                  // consumer_service
                    $newMessage->getPublisherName(),   // producer_service
                    $newMessage->getEventName(),       // event_type
                    $message->getBody(),               // message_body (full message)
                    $messageId,                        // message_id
                    $schema,                           // schema
                    'processing',                      // status
                    $initialRetryCount                 // retry_count
                );

                if (!$inserted) {
                    // Race condition - another worker inserted it between existence check and insert
                    // ACK and skip (idempotent behavior)
                    $message->ack();
                    return;
                }
            }
        } catch (\RuntimeException $e) {
            // Critical DB error (not duplicate) - don't ACK, let RabbitMQ redeliver
            error_log(sprintf(
                "[NanoConsumer] Failed to insert inbox for message %s: %s",
                $messageId,
                $e->getMessage()
            ));
            throw $e;
        }

        // User handler
        $callback = $newMessage->getDebug() && is_callable($this->debugCallback) ? $this->debugCallback : $this->callback;

        $retryCount = $newMessage->getRetryCount() + 1;
        $eventRetryStatusTag = $this->getRetryTag($retryCount);

        // Metric tags
        $tags = [
            'nano_service_name' => $this->getEnv(self::MICROSERVICE_NAME),
            'event_name' => $newMessage->getEventName()
        ];

        // Track payload size
        $payloadSize = strlen($message->getBody());
        $this->statsD->histogram(
            'rmq_consumer_payload_bytes',
            $payloadSize,
            $tags,
            $this->statsD->getSampleRate('payload')
        );

        // Start event processing metrics (existing)
        $this->statsD->start($tags, $eventRetryStatusTag);

        try {

            call_user_func($callback, $newMessage);

            // Try to ACK message first (critical - remove from RabbitMQ)
            try {
                $message->ack();
            } catch (Throwable $e) {
                // Track ACK failure
                $this->statsD->increment('rmq_consumer_ack_failed_total', $tags);
                // Critical - if ACK fails, don't mark as processed
                throw $e;
            }

            // Mark as processed in inbox (best effort, log if fails)
            $marked = $repository->markInboxAsProcessed($messageId, $consumerService, $schema);

            if (!$marked) {
                // Event processed and ACKed but not marked in DB - duplicate risk
                // Message stays in "processing" state, might be picked up by cleanup job
                error_log(sprintf(
                    "[NanoConsumer] Event %s processed and ACKed but not marked as processed (duplicate risk)",
                    $messageId
                ));
            }

            $this->statsD->end(EventExitStatusTag::SUCCESS, $eventRetryStatusTag);

        } catch (Throwable $exception) {

            $retryCount = $newMessage->getRetryCount() + 1;
            if ($retryCount < $this->tries) {

                try {
                    if (is_callable($this->catchCallback)) {
                        call_user_func($this->catchCallback, $exception, $newMessage);
                    }
                } catch (Throwable $e) {
                    // Log catchCallback failures - these are errors in user-defined error handlers
                    error_log(sprintf(
                        "[NanoConsumer] catchCallback failed for message %s: %s",
                        $messageId,
                        $e->getMessage()
                    ));
                }

                try {
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
                    $newMessage->set('application_headers', $headers);
                    $this->getChannel()->basic_publish($newMessage, $this->queue, $key);
                    $message->ack();

                    // Update retry count in inbox (best effort)
                    $updated = $repository->updateInboxRetryCount($messageId, $consumerService, $retryCount, $schema);

                    if (!$updated) {
                        // Failed to update retry count in DB, but message is republished
                        error_log(sprintf(
                            "[NanoConsumer] Failed to update retry_count for message %s (retry %d)",
                            $messageId,
                            $retryCount
                        ));
                    }
                    
                    // Note: Don't update inbox status on retry
                    // Message stays in "processing" until final success/failure

                } catch (Throwable $e) {
                    // Republish failed - don't ACK, let RabbitMQ redeliver
                    error_log(sprintf(
                        "[NanoConsumer] Retry republish failed for message %s: %s",
                        $messageId,
                        $e->getMessage()
                    ));
                    throw $e;
                }

                $this->statsD->end(EventExitStatusTag::FAILED, $eventRetryStatusTag);

            } else {

                // Max retries exceeded - send to DLX
                try {
                    if (is_callable($this->failedCallback)) {
                        call_user_func($this->failedCallback, $exception, $newMessage);
                    }
                } catch (Throwable $e) {
                    // Log failedCallback failures - these are errors in user-defined DLX handlers
                    error_log(sprintf(
                        "[NanoConsumer] failedCallback failed for message %s: %s",
                        $messageId,
                        $e->getMessage()
                    ));
                }

                // Track DLX event
                $dlxTags = array_merge($tags, ['reason' => 'max_retries_exceeded']);
                $this->statsD->increment('rmq_consumer_dlx_total', $dlxTags);

                try {
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
                    $newMessage->set('application_headers', $headers);
                    $newMessage->setConsumerError($exception->getMessage());
                    $this->getChannel()->basic_publish($newMessage, '', $this->queue . self::FAILED_POSTFIX);
                    $message->ack();

                    // Mark as failed in inbox (best effort)
                    $exceptionClass = get_class($exception);
                    $exceptionMessage = $exception->getMessage();
                    $errorMessage = $exceptionClass . ($exceptionMessage ? ': ' . $exceptionMessage : '');
                    $marked = $repository->markInboxAsFailed($messageId, $consumerService, $schema, $errorMessage);

                    if (!$marked) {
                        // Event sent to DLX but not marked as failed in DB
                        error_log(sprintf(
                            "[NanoConsumer] Event %s sent to DLX but not marked as failed in inbox",
                            $messageId
                        ));
                    }

                } catch (Throwable $e) {
                    // DLX publish failed - don't ACK, let RabbitMQ redeliver
                    error_log(sprintf(
                        "[NanoConsumer] DLX publish failed for message %s: %s",
                        $messageId,
                        $e->getMessage()
                    ));
                    throw $e;
                }

                $this->statsD->end(EventExitStatusTag::FAILED, $eventRetryStatusTag);

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
}
