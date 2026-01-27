<?php

declare(strict_types=1);

namespace AlexFN\NanoService\Clients\StatsDClient;

/**
 * HTTP Request Metrics Helper
 *
 * Simplifies HTTP request metrics collection with:
 * - Automatic timing via try-finally pattern
 * - Memory usage tracking
 * - Status code and latency bucketing
 * - Content type and payload size tracking
 * - Error categorization
 *
 * Usage:
 * ```php
 * $metrics = new HttpMetrics($statsd, 'hook2event', 'stripe', 'POST');
 * $metrics->start();
 *
 * try {
 *     // ... process request
 *     $metrics->trackContentType($contentType);
 *     $metrics->trackPayloadSize($payloadBytes);
 *     return $response;
 * } catch (ValidationException $e) {
 *     $metrics->setStatus('validation_error', 422);
 *     throw $e;
 * } catch (\Exception $e) {
 *     $metrics->recordError($e, 500);
 *     throw $e;
 * } finally {
 *     $metrics->finish();
 * }
 * ```
 *
 * @package AlexFN\NanoService\Clients\StatsDClient
 */
final class HttpMetrics
{
    private StatsDClient $statsd;
    private string $service;
    private string $provider;
    private string $method;
    private string $status = 'success';
    private int $httpStatusCode = 200;
    private int $startMemory;
    private bool $started = false;

    /**
     * Create HTTP metrics helper
     *
     * @param StatsDClient $statsd StatsD client instance
     * @param string $service Service name (e.g., "hook2event")
     * @param string $provider Provider/endpoint name (e.g., "stripe", "payment")
     * @param string $method HTTP method (GET, POST, etc.)
     */
    public function __construct(
        StatsDClient $statsd,
        string $service,
        string $provider,
        string $method
    ) {
        $this->statsd = $statsd;
        $this->service = $service;
        $this->provider = $provider;
        $this->method = $method;
    }

    /**
     * Start tracking HTTP request metrics
     *
     * Call this at the beginning of request handling.
     * Must be paired with finish() in a finally block.
     */
    public function start(): void
    {
        $this->statsd->startTimer('http_request');
        $this->startMemory = memory_get_usage(true);
        $this->started = true;
    }

    /**
     * Set request status (for non-error cases)
     *
     * @param string $status Status string (e.g., "success", "validation_error")
     * @param int $httpStatusCode HTTP status code
     */
    public function setStatus(string $status, int $httpStatusCode): void
    {
        $this->status = $status;
        $this->httpStatusCode = $httpStatusCode;
    }

    /**
     * Record an error and set appropriate status
     *
     * @param \Exception $e The exception that occurred
     * @param int $httpStatusCode HTTP status code (default: 500)
     */
    public function recordError(\Exception $e, int $httpStatusCode = 500): void
    {
        $this->status = 'failed';
        $this->httpStatusCode = $httpStatusCode;

        $errorReason = MetricsBuckets::categorizeError($e);
        $this->statsd->increment('http_request_errors', [
            'service' => $this->service,
            'provider' => $this->provider,
            'error_reason' => $errorReason,
        ]);
    }

    /**
     * Track content type distribution
     *
     * @param string|null $contentType Content-Type header value
     */
    public function trackContentType(?string $contentType): void
    {
        $normalized = MetricsBuckets::normalizeContentType($contentType);
        $this->statsd->increment('http_request_by_content_type', [
            'service' => $this->service,
            'provider' => $this->provider,
            'content_type' => $normalized,
        ]);
    }

    /**
     * Track payload size metrics
     *
     * @param int $bytes Payload size in bytes
     */
    public function trackPayloadSize(int $bytes): void
    {
        $this->statsd->gauge('http_payload_size_bytes', $bytes, [
            'service' => $this->service,
            'provider' => $this->provider,
        ]);

        $sizeCategory = MetricsBuckets::getPayloadSizeCategory($bytes);
        $this->statsd->increment('http_payload_by_size_category', [
            'service' => $this->service,
            'provider' => $this->provider,
            'size_category' => $sizeCategory,
        ]);
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

        $durationMs = $this->statsd->endTimer('http_request') ?? 0;
        $memoryUsed = memory_get_usage(true) - $this->startMemory;
        $memoryMb = round($memoryUsed / 1024 / 1024, 2);

        $baseTags = [
            'service' => $this->service,
            'provider' => $this->provider,
            'method' => $this->method,
            'status' => $this->status,
        ];

        // Core request metrics
        $this->statsd->timing('http_request_duration_ms', $durationMs, $baseTags);
        $this->statsd->gauge('http_request_memory_mb', (int) ($memoryMb * 100), $baseTags);
        $this->statsd->increment('http_request_total', $baseTags);

        // Business metrics: per-provider throughput
        $this->statsd->increment('http_webhooks_received_by_provider', [
            'service' => $this->service,
            'provider' => $this->provider,
            'status' => $this->status,
        ]);

        // SLO tracking: latency buckets
        $latencyBucket = MetricsBuckets::getHttpLatencyBucket($durationMs);
        $this->statsd->increment('http_request_by_latency_bucket', [
            'service' => $this->service,
            'provider' => $this->provider,
            'latency_bucket' => $latencyBucket,
        ]);

        // HTTP status code metrics (for SLI/SLO tracking)
        $this->statsd->increment('http_response_status_total', [
            'service' => $this->service,
            'provider' => $this->provider,
            'method' => $this->method,
            'status_code' => (string) $this->httpStatusCode,
            'status_class' => MetricsBuckets::getStatusClass($this->httpStatusCode),
        ]);
    }
}
