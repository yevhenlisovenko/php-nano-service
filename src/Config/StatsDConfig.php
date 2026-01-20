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
        // Metrics are ONLY enabled when STATSD_ENABLED === "true" (string)
        // Any other value (false, empty, missing, etc.) = disabled
        $this->enabled = $config['enabled'] ?? $this->envBool('STATSD_ENABLED', false);

        // Only load and validate env vars if metrics are enabled
        if ($this->enabled) {
            $this->validateRequiredEnvVars();
            $this->host = $config['host'] ?? $this->envRequired('STATSD_HOST');
            $this->port = $config['port'] ?? (int)$this->envRequired('STATSD_PORT');
            $this->namespace = $config['namespace'] ?? $this->envRequired('STATSD_NAMESPACE');
            $this->sampling = $config['sampling'] ?? [
                'ok_events' => (float)$this->envRequired('STATSD_SAMPLE_OK'),
                'error_events' => 1.0, // Always track errors
                'latency' => 1.0, // Always track latency for accuracy
                'payload' => (float)$this->envRequired('STATSD_SAMPLE_PAYLOAD'),
            ];
        }
        // If disabled, properties remain uninitialized (never accessed)
    }

    /**
     * Get required environment variable - throws if missing
     *
     * @param string $key Environment variable name
     * @return string Environment variable value
     * @throws \RuntimeException If environment variable is not set
     */
    private function envRequired(string $key): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === '') {
            throw new \RuntimeException("Missing required environment variable: {$key}");
        }
        return $value;
    }

    /**
     * Get boolean environment variable - ONLY true if value === "true" (string)
     *
     * @param string $key Environment variable name
     * @param bool $default Default value if not set
     * @return bool True ONLY if env var is exactly "true" (string)
     */
    private function envBool(string $key, bool $default): bool
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        // ONLY "true" (string) enables metrics
        return $value === 'true';
    }

    /**
     * Validate all required StatsD environment variables are set
     *
     * @throws \RuntimeException If any required variable is missing
     */
    private function validateRequiredEnvVars(): void
    {
        $required = ['STATSD_HOST', 'STATSD_PORT', 'STATSD_NAMESPACE', 'STATSD_SAMPLE_OK', 'STATSD_SAMPLE_PAYLOAD'];
        $missing = [];

        foreach ($required as $var) {
            $value = $_ENV[$var] ?? getenv($var);
            if ($value === false || $value === '') {
                $missing[] = $var;
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException(
                'StatsD is enabled but missing required environment variables: ' . implode(', ', $missing)
            );
        }
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
