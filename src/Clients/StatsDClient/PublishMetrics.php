<?php

declare(strict_types=1);

namespace AlexFN\NanoService\Clients\StatsDClient;

/**
 * Job/Message Publishing Metrics Helper
 *
 * Simplifies metrics collection for async job processing:
 * - Automatic timing via try-finally pattern
 * - Memory usage tracking
 * - Retry attempt tracking
 * - Publish-specific latency buckets
 *
 * Usage:
 * ```php
 * $metrics = new PublishMetrics($statsd, $job->event_name);
 * $metrics->start();
 *
 * try {
 *     // ... publish job
 *     $metrics->recordSuccess();
 * } catch (\Exception $e) {
 *     $metrics->recordFailure($job->attempts + 1);
 *     throw $e;
 * } finally {
 *     $metrics->finish();
 * }
 * ```
 *
 * @package AlexFN\NanoService\Clients\StatsDClient
 */
final class PublishMetrics
{
    private StatsDClient $statsd;
    private string $eventName;
    private string $status = 'success';
    private int $startMemory;
    private bool $started = false;
    private ?int $retryAttempts = null;

    /**
     * Create publish metrics helper
     *
     * @param StatsDClient $statsd StatsD client instance
     * @param string $eventName Full event name (e.g., "webhook.stripe")
     */
    public function __construct(
        StatsDClient $statsd,
        string $eventName
    ) {
        $this->statsd = $statsd;
        $this->eventName = $eventName;
    }

    /**
     * Start tracking publish metrics
     *
     * Call this before attempting to publish.
     * Must be paired with finish() in a finally block.
     */
    public function start(): void
    {
        $this->statsd->startTimer('publish_job');
        $this->startMemory = memory_get_usage(true);
        $this->started = true;
    }

    /**
     * Record successful publish
     */
    public function recordSuccess(): void
    {
        $this->status = 'success';
    }

    /**
     * Record failed publish with retry tracking
     *
     * @param int $attempts Current attempt count (after increment)
     */
    public function recordFailure(int $attempts): void
    {
        $this->status = 'failed';
        $this->retryAttempts = $attempts;
    }

    /**
     * Finish tracking and record all metrics
     *
     * MUST be called in a finally block to ensure metrics
     * are always recorded, even on exceptions.
     */
    public function finish(): void
    {
        if (!$this->started) {
            return;
        }

        $durationMs = $this->statsd->endTimer('publish_job') ?? 0;
        $memoryUsed = memory_get_usage(true) - $this->startMemory;
        $memoryMb = round($memoryUsed / 1024 / 1024, 2);

        $baseTags = [
            'event_name' => $this->eventName,
            'status' => $this->status,
        ];

        // Core job metrics
        $this->statsd->timing('publish_job_duration_ms', $durationMs, $baseTags);
        $this->statsd->gauge('publish_job_memory_mb', (int) ($memoryMb * 100), $baseTags);
        $this->statsd->increment('publish_job_processed', 1, 1, $baseTags);

        // Track retry attempts for failed jobs
        if ($this->retryAttempts !== null) {
            $this->statsd->gauge('publish_job_retry_attempts', $this->retryAttempts, [
                'event_name' => $this->eventName,
            ]);
        }

        // SLO tracking: publish latency buckets
        $latencyBucket = MetricsBuckets::getPublishLatencyBucket($durationMs);
        $this->statsd->increment('publish_job_by_latency_bucket', 1, 1, [
            'event_name' => $this->eventName,
            'latency_bucket' => $latencyBucket,
        ]);
    }
}
