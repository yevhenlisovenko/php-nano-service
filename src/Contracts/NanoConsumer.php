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
     * Set backoff time (single value or array for progressive backoff)
     */
    public function backoff(int|array $seconds): self;

    /**
     * Register a transient-error policy (repeatable): fn(Throwable): bool. Composed with
     * the built-in PDO connection-error detection; every consume path inherits it.
     */
    public function transientWhen(callable $classifier): self;

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
    public function consume(callable $callback, ?callable $debugCallback = null): void;
}
