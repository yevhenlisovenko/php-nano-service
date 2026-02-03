<?php

namespace AlexFN\NanoService;

use AlexFN\NanoService\Clients\StatsDClient\StatsDClient;
use AlexFN\NanoService\Contracts\NanoPublisher as NanoPublisherContract;
use AlexFN\NanoService\Contracts\NanoServiceMessage as NanoServiceMessageContract;
use AlexFN\NanoService\Enums\PublishErrorType;
use Exception;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Wire\AMQPTable;

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
    const PUBLISHER_ENABLED = 'AMQP_PUBLISHER_ENABLED';

    private NanoServiceMessageContract $message;

    private ?int $delay = null;

    private array $meta = [];

    // ⚠️ IMPORTANT: Do NOT redeclare $statsD property here!
    // It is inherited from parent NanoServiceClass as protected.
    // Redeclaring as private causes fatal error in PHP 8.x
    // See docs/BUGFIXES.md - "Duplicate Property Visibility" for details
    // REMOVED (2026-01-20): private StatsDClient $statsD;

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
     * Publish a message to PostgreSQL outbox table
     *
     * New default publish method - writes to pg2event.outbox table instead of RabbitMQ.
     * The pg2event dispatcher will read from the table and relay to RabbitMQ.
     *
     * Architecture: Service → PostgreSQL → pg2event → RabbitMQ
     *
     * Stores the complete NanoServiceMessage structure:
     * - payload (from message->getPayload())
     * - meta (from message->getMeta())
     * - status (from message data)
     * - system (from message data)
     *
     * All stored as single JSONB column for full compatibility.
     *
     * Error Handling:
     * - Event is always stored in the outbox table first (guarantees persistence)
     * - Attempts immediate publish to RabbitMQ
     * - On RabbitMQ failure: marks event as 'failed' and returns false (no exception thrown)
     * - On RabbitMQ success: marks event as 'published' and returns true
     * - Failed events can be retried later by pg2event dispatcher
     *
     * @param string $event Event name (routing key)
     * @return bool True if published to RabbitMQ successfully, false if RabbitMQ publish failed
     * @throws \RuntimeException Only if database operations fail (insertOutbox, markAsPublished, markAsFailed)
     */
    public function publish(string $event): bool
    {
        // Validate AMQP-specific environment variables
        if (!isset($_ENV['AMQP_MICROSERVICE_NAME'])) {
            throw new \RuntimeException("Missing required environment variables: AMQP_MICROSERVICE_NAME");
        }

        if (!isset($_ENV['DB_BOX_SCHEMA'])) {
            throw new \RuntimeException("Missing required environment variables: DB_BOX_SCHEMA");
        }

        // Prepare message
        $this->prepareMessageForPublish($event);

        // Get message ID for tracking
        $messageId = $this->message->get('message_id');

        // Get full message body (contains payload, meta, status, system)
        $messageBody = $this->message->getBody();

        // Use EventRepository to insert message into outbox
        $repository = EventRepository::getInstance();
        $repository->insertOutbox(
            $_ENV['AMQP_MICROSERVICE_NAME'],  // producer_service
            $event,                            // event_type (routing key)
            $messageBody,                      // message_body (full NanoServiceMessage as JSONB)
            $messageId,                        // message_id (UUID for tracking)
            null,                              // partition_key (optional)
            $_ENV['DB_BOX_SCHEMA']            // schema
        );

        // Publish message directly to RabbitMQ with error handling
        try {
            $this->publishToRabbit($event);
            // Mark as published in database after successful RabbitMQ publish
            $repository->markAsPublished($messageId, $_ENV['DB_BOX_SCHEMA']);
            return true;
        } catch (Exception $e) {
            // Mark as pending for retry by cronjob if RabbitMQ publish fails
            // Build error message with exception class and message
            $exceptionClass = get_class($e);
            $exceptionMessage = $e->getMessage();
            $errorMessage = $exceptionClass . ($exceptionMessage ? ': ' . $exceptionMessage : '');
            $repository->markAsPending($messageId, $_ENV['DB_BOX_SCHEMA'], $errorMessage);
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
        if ((bool) $this->getEnv(self::PUBLISHER_ENABLED) !== true) {
            return;
        }

        // Prepare message
        $this->prepareMessageForPublish($event);

        // Metrics tags
        $tags = [
            'service' => $this->getEnv(self::MICROSERVICE_NAME),
            'event' => $event,
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
                $tags,
                $this->statsD->getSampleRate('payload')
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
                    $tags,
                    $this->statsD->getSampleRate('latency')
                );
            }
            $this->statsD->increment('rmq_publish_success_total', $tags, $sampleRate);

        } catch (AMQPChannelClosedException $e) {
            $this->handlePublishError($e, $tags, PublishErrorType::CHANNEL_ERROR, $timerKey);
            throw $e;
        } catch (AMQPConnectionClosedException | AMQPIOException $e) {
            $this->handlePublishError($e, $tags, PublishErrorType::CONNECTION_ERROR, $timerKey);
            throw $e;
        } catch (AMQPTimeoutException $e) {
            $this->handlePublishError($e, $tags, PublishErrorType::TIMEOUT, $timerKey);
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
            $this->statsD->timing('rmq_publish_duration_ms', $duration, $errorTags, 1.0);
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
}
