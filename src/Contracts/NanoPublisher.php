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
     * Publish in exchange
     *
     * @return mixed
     */
    public function publish(string $event): void;
}
