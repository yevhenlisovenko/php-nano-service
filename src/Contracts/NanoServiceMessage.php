<?php

namespace AlexFN\NanoService\Contracts;

interface NanoServiceMessage
{
    public function getPayload(): array;

    /**
     * @return mixed
     */
    public function getPayloadAttribute($attribute, $default = null);

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

    /**
     * @return mixed
     */
    public function getMetaAttribute(string $attribute, $default = null);

    public function addMeta(array $payload, bool $replace = false): self;

    public function addMetaAttribute(string $attribute, array $data, bool $replace = false): self;

    public function getDebug(): bool;

    public function setDebug(bool $debug = true): self;

    public function setEvent(string $event): self;

    /**
     * @param  null  $default
     */
    public function getEncryptedAttribute(string $attribute, $default = null): string;

    public function setEncryptedAttribute(string $attribute, string $value): self;

    public function getTenantProduct(): ?string;

    public function getTenantEnv(): ?string;

    public function getTenantSlug(): ?string;
}
