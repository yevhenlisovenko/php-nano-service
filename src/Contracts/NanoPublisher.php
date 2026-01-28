<?php

namespace AlexFN\NanoService\Contracts;

interface NanoPublisher
{
    /**
     * Set message
     */
    public function setMessage(NanoServiceMessage $message): self;

    /**
     * Set tenant credentials
     */
    public function setMeta(array $data): self;

    /**
     * Set delay
     */
    public function delay(int $delay): self;

    /**
     * Publish message to PostgreSQL outbox table
     *
     * Default method - writes to pg2event.outbox for reliable delivery.
     *
     * @param string $event Event name (routing key)
     * @return void
     */
    public function publish(string $event): void;

    /**
     * Publish message directly to RabbitMQ
     *
     * Used by pg2event dispatcher to relay outbox messages.
     * Regular services should use publish() instead.
     *
     * @param string $event Event name (routing key)
     * @return void
     */
    public function publishToRabbit(string $event): void;
}
