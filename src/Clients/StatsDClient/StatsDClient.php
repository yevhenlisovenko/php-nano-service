<?php

namespace AlexFN\NanoService\Clients\StatsDClient;

use AlexFN\NanoService\Clients\StatsDClient\Enums\EventExitStatusTag;
use AlexFN\NanoService\Clients\StatsDClient\Enums\EventRetryStatusTag;
use AlexFN\NanoService\Config\StatsDConfig;
use League\StatsD\Client;

/**
 * StatsD client for sending metrics to statsd-exporter
 *
 * Provides methods for tracking events, timings, counters, gauges, and histograms
 * with configurable sampling rates and tag support.
 *
 * @package AlexFN\NanoService\Clients\StatsDClient
 */
class StatsDClient
{
    private bool $canStartService;

    private ?Client $statsd = null;

    private StatsDConfig $config;

    private float $start;

    private array $tags = [];

    private array $timers = [];

    /**
     * Create a new StatsD client instance
     *
     * @param StatsDConfig|array|null $config Configuration object or array, or null to auto-configure
     */
    public function __construct($config = null)
    {
        // Support both old array format and new StatsDConfig
        if ($config instanceof StatsDConfig) {
            $this->config = $config;
        } elseif (is_array($config)) {
            // Legacy support: array config
            $this->config = new StatsDConfig($config);
        } else {
            // Auto-configure from environment
            $this->config = new StatsDConfig();
        }

        $this->canStartService = $this->config->isEnabled();

        if ($this->canStartService) {
            $this->statsd = new Client();
            $this->statsd->configure($this->config->toArray());
        }
    }

    /**
     * Start tracking an event (existing consumer method)
     *
     * @param array $tags Tags to attach to the metric
     * @param EventRetryStatusTag $eventRetryStatusTag Retry status tag
     * @return void
     */
    public function start(array $tags, EventRetryStatusTag $eventRetryStatusTag): void
    {
        if (!$this->canStartService) {
            return;
        }

        $this->tags = $tags;
        $this->addTags([
            'retry' => $eventRetryStatusTag->value
        ]);
        $this->start = microtime(true);
        $this->statsd->increment("event_started_count", 1, 1, $this->tags);
    }

    /**
     * End tracking an event (existing consumer method)
     *
     * @param EventExitStatusTag $eventExitStatusTag Exit status tag
     * @param EventRetryStatusTag $eventRetryStatusTag Retry status tag
     * @return void
     */
    public function end(EventExitStatusTag $eventExitStatusTag, EventRetryStatusTag $eventRetryStatusTag): void
    {
        if (!$this->canStartService) {
            return;
        }

        $this->addTags([
            'status' => $eventExitStatusTag->value,
            'retry' => $eventRetryStatusTag->value
        ]);
        $this->statsd->timing(
            "event_processed_duration",
            (microtime(true) - $this->start) * 1000,
            $this->tags
        );
    }

    /**
     * Increment a counter metric
     *
     * @param string $metric Metric name
     * @param array $tags Tags to attach
     * @param float $sampleRate Sampling rate (0.0 to 1.0)
     * @param int $value Value to increment by
     * @return void
     */
    public function increment(string $metric, array $tags = [], float $sampleRate = 1.0, int $value = 1): void
    {
        if (!$this->canStartService) {
            return;
        }
        $this->statsd->increment($metric, $value, $sampleRate, $tags);
    }

    /**
     * Send a timing metric
     *
     * @param string $metric Metric name
     * @param int $time Time in milliseconds
     * @param array $tags Tags to attach
     * @param float $sampleRate Sampling rate (0.0 to 1.0)
     * @return void
     */
    public function timing(string $metric, int $time, array $tags = [], float $sampleRate = 1.0): void
    {
        if (!$this->canStartService) {
            return;
        }
        $this->statsd->timing($metric, $time, $sampleRate, $tags);
    }

    /**
     * Send a gauge metric (absolute value)
     *
     * @param string $metric Metric name
     * @param int $value Gauge value
     * @param array $tags Tags to attach
     * @return void
     */
    public function gauge(string $metric, int $value, array $tags = []): void
    {
        if (!$this->canStartService) {
            return;
        }
        $this->statsd->gauge($metric, $value, $tags);
    }

    /**
     * Send a histogram metric (for distributions)
     *
     * @param string $metric Metric name
     * @param int $value Value to record
     * @param array $tags Tags to attach
     * @param float $sampleRate Sampling rate (0.0 to 1.0)
     * @return void
     */
    public function histogram(string $metric, int $value, array $tags = [], float $sampleRate = 1.0): void
    {
        if (!$this->canStartService) {
            return;
        }
        // StatsD doesn't have native histograms, use timing as approximation
        $this->statsd->timing($metric, $value, $sampleRate, $tags);
    }

    /**
     * Start a named timer
     *
     * @param string $key Timer identifier
     * @return void
     */
    public function startTimer(string $key): void
    {
        $this->timers[$key] = microtime(true);
    }

    /**
     * End a named timer and return the duration
     *
     * @param string $key Timer identifier
     * @return int|null Duration in milliseconds, or null if timer not found
     */
    public function endTimer(string $key): ?int
    {
        if (!isset($this->timers[$key])) {
            return null;
        }
        $duration = (int)((microtime(true) - $this->timers[$key]) * 1000);
        unset($this->timers[$key]);
        return $duration;
    }

    /**
     * Get configured sampling rate for a metric type
     *
     * @param string $type Metric type (ok_events, error_events, latency, payload)
     * @return float Sampling rate between 0.0 and 1.0
     */
    public function getSampleRate(string $type): float
    {
        return $this->config->getSampleRate($type);
    }

    /**
     * Check if StatsD metrics are enabled
     *
     * @return bool True if metrics should be sent
     */
    public function isEnabled(): bool
    {
        return $this->canStartService;
    }

    /**
     * Add tags to the current tag set
     *
     * @param array $tags Tags to merge
     * @return void
     */
    private function addTags(array $tags): void
    {
        $this->tags = array_merge($this->tags, $tags);
    }
}
