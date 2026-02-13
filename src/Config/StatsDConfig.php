<?php

namespace AlexFN\NanoService\Config;

class StatsDConfig
{
    private bool $enabled = false;
    private string $host = '';
    private int $port = 0;
    private string $namespace = '';
    private array $sampling = [];
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
        $this->sampling = $config['sampling'] ?? [
            'ok_events' => (float)$this->require('STATSD_SAMPLE_OK'),
            'error_events' => 1.0,
            'latency' => 1.0,
            'payload' => (float)$this->require('STATSD_SAMPLE_PAYLOAD'),
        ];
        $this->defaultTags = [
            'nano_service_name' => $config['nano_service_name'] ?? $this->require('AMQP_MICROSERVICE_NAME'),
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

    public function getSampleRate(string $type): float
    {
        return $this->sampling[$type] ?? 1.0;
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
