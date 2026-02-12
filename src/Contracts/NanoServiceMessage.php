<?php

namespace AlexFN\NanoService\Contracts;

interface NanoServiceMessage
{
    public function getPayload(): array;

    public function getPayloadAttribute(string $attribute, mixed $default = null): mixed;

    public function addPayload(array $payload, bool $replace = false): self;

    public function addPayloadAttribute(string $attribute, array $data, bool $replace = false): self;

    public function getStatusCode(): string;

    public function setStatusCode(string $code): self;

    public function getStatusData(): array;

    public function setStatusData(array $data): self;

    public function getConsumerError(): string;

    public function setConsumerError(string $msg): self;

    public function getCreatedAt(): string;

    public function setCreatedAt(string $date): self;

    public function getMeta(): array;

    public function getMetaAttribute(string $attribute, mixed $default = null): mixed;

    public function addMeta(array $payload, bool $replace = false): self;

    public function addMetaAttribute(string $attribute, array $data, bool $replace = false): self;

    public function getDebug(): bool;

    public function setDebug(bool $debug = true): self;

    public function setEvent(string $event): self;

    public function getEncryptedAttribute(string $attribute, mixed $default = null): ?string;

    public function setEncryptedAttribute(string $attribute, string $value): self;

    public function getTenantProduct(): ?string;

    public function getTenantEnv(): ?string;

    public function getTenantSlug(): ?string;

    /**
     * @deprecated use setMessageId for hashed id
     */
    public function setId(string $id): self;

    public function setMessageId(string $id): self;

    public function getId(): string;

    public function setTraceId(array $traceId): self;

    /**
     * Append a message ID to the trace chain
     *
     * @param string $messageId Message ID to append
     * @return self Fluent interface
     */
    public function appendTraceId(string $messageId): self;

    public function getTraceId(): array;

    public function getEventName(): string;

    public function getPublisherName(): string;

    public function getRetryCount(): int;
}
