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
        memory_reset_peak_usage();
        $this->increment("event_started_count", 1, 1, $this->tags);
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

        // Track processing duration
        $this->timing(
            "event_processed_duration",
            (microtime(true) - $this->start) * 1000,
            $this->tags
        );

        // Track peak memory during event processing, then reset for next event (PHP 8.2+)
        $this->gauge(
            "event_processed_memory_bytes",
            memory_get_peak_usage(true),
            $this->tags
        );
        memory_reset_peak_usage();
    }

    public function increment(string $metric, int $delta = 1, float $sampleRate = 1, array $tags = []): void
    {
        if (!$this->canStartService) {
            return;
        }
        $this->statsd->increment($metric, $delta, $sampleRate, $tags);
    }

    public function decrement(string $metric, int $delta = 1, float $sampleRate = 1, array $tags = []): void
    {
        if (!$this->canStartService) {
            return;
        }
        $this->statsd->decrement($metric, $delta, $sampleRate, $tags);
    }

    public function timing(string $metric, float $time, array $tags = []): void
    {
        if (!$this->canStartService) {
            return;
        }
        $this->statsd->timing($metric, $time, $tags);
    }

    public function gauge(string $metric, $value, array $tags = []): void
    {
        if (!$this->canStartService) {
            return;
        }
        $this->statsd->gauge($metric, $value, $tags);
    }

    public function set(string $metric, $value, array $tags = []): void
    {
        if (!$this->canStartService) {
            return;
        }
        $this->statsd->set($metric, $value, $tags);
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
     * Check if StatsD metrics are enabled
     *
     * @return bool True if metrics should be sent
     */
    public function isEnabled(): bool
    {
        return $this->canStartService;
    }

    /**
     * Get the configured namespace (service name)
     *
     * Useful for passing to HttpMetrics/PublishMetrics as service tag
     *
     * @return string The namespace from STATSD_NAMESPACE
     */
    public function getNamespace(): string
    {
        return $this->config->getNamespace();
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
