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

class NanoConsumer extends NanoServiceClass implements NanoConsumerContract
{
    const FAILED_POSTFIX = '.failed';
    protected array $handlers = [
        'system.ping.1' => SystemPing::class,
    ];

    private StatsDClient $statsD;

    private $callback;

    private $catchCallback;

    private $failedCallback;

    private $debugCallback;

    private array $events;

    private int $tries = 3;

    private int|array $backoff = 0;

    public function init(): NanoConsumerContract
    {
        $this->statsD = new StatsDClient([
            'host' => $this->getEnv('STATSD_HOST'),
            'port' => $this->getEnv('STATSD_PORT'),
            'namespace' => $this->getEnv('STATSD_NAMESPACE'),
        ]);

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
    public function consume(callable $callback, callable $debugCallback = null): void
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

        $this->statsD->start([
            'nano_service_name' => $this->getEnv(self::MICROSERVICE_NAME),
            'event_name' => $newMessage->getEventName()
        ], $eventRetryStatusTag);

        try {

            call_user_func($callback, $newMessage);
            $message->ack();

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

                try {
                    if (is_callable($this->failedCallback)) {
                        call_user_func($this->failedCallback, $exception, $newMessage);
                    }
                } catch (Throwable $e) {}

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
