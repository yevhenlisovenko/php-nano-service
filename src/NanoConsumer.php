<?php

namespace AlexFN\NanoService;

use AlexFN\NanoService\Clients\StatsDClient\Enums\EventExitStatusTag;
use AlexFN\NanoService\Clients\StatsDClient\Enums\EventRetryStatusTag;
use AlexFN\NanoService\Clients\StatsDClient\StatsDClient;
use AlexFN\NanoService\Contracts\NanoConsumer as NanoConsumerContract;
use AlexFN\NanoService\SystemHandlers\SystemPing;
use ErrorException;
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
     * Process incoming RabbitMQ message with enhanced metrics
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

            // Try to ACK message
            try {
                $message->ack();
            } catch (Throwable $e) {
                // Track ACK failure
                $this->statsD->increment('rmq_consumer_ack_failed_total', $tags);
                throw $e;
            }

            $this->statsD->end(EventExitStatusTag::SUCCESS, $eventRetryStatusTag);

        } catch (Throwable $exception) {

            $retryCount = $newMessage->getRetryCount() + 1;
            if ($retryCount < $this->tries) {

                try {
                    if (is_callable($this->catchCallback)) {
                        call_user_func($this->catchCallback, $exception, $newMessage);
                    }
                } catch (Throwable $e) {}

                $headers = new AMQPTable([
                    'x-delay' => $this->getBackoff($retryCount),
                    'x-retry-count' => $retryCount
                ]);
                $newMessage->set('application_headers', $headers);
                $this->getChannel()->basic_publish($newMessage, $this->queue, $key);
                $message->ack();

                $this->statsD->end(EventExitStatusTag::FAILED, $eventRetryStatusTag);

            } else {

                // Max retries exceeded - send to DLX
                try {
                    if (is_callable($this->failedCallback)) {
                        call_user_func($this->failedCallback, $exception, $newMessage);
                    }
                } catch (Throwable $e) {}

                // Track DLX event
                $dlxTags = array_merge($tags, ['reason' => 'max_retries_exceeded']);
                $this->statsD->increment('rmq_consumer_dlx_total', $dlxTags);

                $headers = new AMQPTable([
                    'x-retry-count' => $retryCount
                ]);
                $newMessage->set('application_headers', $headers);
                $newMessage->setConsumerError($exception->getMessage());
                $this->getChannel()->basic_publish($newMessage, '', $this->queue . self::FAILED_POSTFIX);
                $message->ack();
                //$message->reject(false);

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
