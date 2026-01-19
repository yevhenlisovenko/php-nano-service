<?php

namespace AlexFN\NanoService\Config;

/**
 * Configuration for StatsD metrics collection
 *
 * Provides centralized configuration management for StatsD metrics,
 * including host, port, namespace, and sampling rates.
 *
 * @package AlexFN\NanoService\Config
 */
class StatsDConfig
{
    private bool $enabled;
    private string $host;
    private int $port;
    private string $namespace;
    private array $sampling;

    /**
     * Create a new StatsDConfig instance
     *
     * @param array $config Configuration array with optional overrides
     *                      Keys: enabled, host, port, namespace, sampling
     */
    public function __construct(array $config = [])
    {
        // Metrics are disabled by default for safe production rollout (opt-in)
        // Services must explicitly set STATSD_ENABLED=true to enable metrics
        $this->enabled = $config['enabled'] ?? $this->envBool('STATSD_ENABLED', false);
        $this->host = $config['host'] ?? $this->env('STATSD_HOST', '127.0.0.1');
        $this->port = $config['port'] ?? (int)$this->env('STATSD_PORT', '9125');
        $this->namespace = $config['namespace'] ?? $this->env('STATSD_NAMESPACE', 'nano');
        $this->sampling = $config['sampling'] ?? [
            'ok_events' => (float)$this->env('STATSD_SAMPLE_OK', '1.0'),
            'error_events' => 1.0, // Always track errors
            'latency' => 1.0, // Always track latency for accuracy
            'payload' => (float)$this->env('STATSD_SAMPLE_PAYLOAD', '0.1'),
        ];
    }

    /**
     * Get environment variable with fallback
     *
     * @param string $key Environment variable name
     * @param string $default Default value if not set
     * @return string Environment variable value or default
     */
    private function env(string $key, string $default = ''): string
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }

    /**
     * Get boolean environment variable with fallback
     *
     * @param string $key Environment variable name
     * @param bool $default Default value if not set
     * @return bool Environment variable value as boolean
     */
    private function envBool(string $key, bool $default): bool
    {
        $value = $this->env($key, '');
        if ($value === '') {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Check if StatsD metrics are enabled
     *
     * @return bool True if metrics should be collected
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get StatsD server host
     *
     * @return string Host address (IP or hostname)
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Get StatsD server port
     *
     * @return int Port number (usually 8125)
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Get metrics namespace prefix
     *
     * @return string Namespace for metric names
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Get sampling rate for a specific metric type
     *
     * @param string $type Metric type (ok_events, error_events, latency, payload)
     * @return float Sampling rate between 0.0 and 1.0
     */
    public function getSampleRate(string $type): float
    {
        return $this->sampling[$type] ?? 1.0;
    }

    /**
     * Get full configuration array for League\StatsD\Client
     *
     * @return array Configuration array with host, port, namespace
     */
    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'namespace' => $this->namespace,
        ];
    }
}
