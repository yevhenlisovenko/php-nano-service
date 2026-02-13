<?php

namespace AlexFN\NanoService\Config;

class StatsDConfig
{
    private bool $enabled = false;
    private string $host = '';
    private int $port = 0;
    private string $namespace = '';
    private array $defaultTags = [];

    // $config allows overriding env vars in unit tests (via putenv() is also fine, this is a shortcut)
    public function __construct(array $config = [])
    {
        $this->enabled = ($config['enabled'] ?? getenv('STATSD_ENABLED')) === 'true';

        if (!$this->enabled) {
            return;
        }

        $this->host = $config['host'] ?? $this->require('STATSD_HOST');
        $this->port = $config['port'] ?? (int)$this->require('STATSD_PORT');
        $this->namespace = $config['namespace'] ?? $this->require('STATSD_NAMESPACE');
        $this->defaultTags = [
            'nano_service_name' => $config['nano_service_name'] ?? $this->require('AMQP_MICROSERVICE_NAME'),
            'env' => $config['env'] ?? ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'unknown'),
        ];
    }

    private function require(string $key): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            throw new \RuntimeException("Missing required environment variable: {$key}");
        }
        return $value;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function getDefaultTags(): array
    {
        return $this->defaultTags;
    }

    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'namespace' => $this->namespace,
            'tags' => $this->defaultTags,
        ];
    }
}
