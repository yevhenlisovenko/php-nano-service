<?php

namespace AlexFN\NanoService\Contracts;

interface NanoConsumer
{
    /**
     * Register consumer to queues
     */
    public function events(string ...$events): self;

    /**
     * Set number of attempts
     */
    public function tries(int $attempts): self;

    /**
     * Set backoff time
     */
    public function backoff(int $seconds): self;

    /**
     * Add failed queue for consumer
     */
    public function failed(callable $callback): self;

    /**
     * Set callback for catch exception
     */
    public function catch(callable $callback): self;

    /**
     * Consume from queues
     */
    public function consume(callable $callback, ?callable $debugCallback): void;
}
