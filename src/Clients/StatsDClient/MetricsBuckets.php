<?php

declare(strict_types=1);

namespace AlexFN\NanoService\Clients\StatsDClient;

/**
 * Utility class for metric bucketing and categorization
 *
 * Provides consistent bucketing for:
 * - HTTP request latency (SLO tracking)
 * - Publish/job latency (async operations)
 * - Payload sizes (capacity planning)
 * - HTTP status codes (SLI tracking)
 * - Content types (API monitoring)
 * - Error categorization (root cause analysis)
 *
 * @package AlexFN\NanoService\Clients\StatsDClient
 */
final class MetricsBuckets
{
    /**
     * Get latency bucket for HTTP requests (stricter thresholds)
     *
     * Buckets optimized for synchronous HTTP requests where
     * low latency is critical for user experience.
     *
     * @param float $ms Duration in milliseconds
     * @return string Bucket name for metrics tagging
     */
    public static function getHttpLatencyBucket(float $ms): string
    {
        if ($ms < 10) {
            return 'fast_lt_10ms';
        }
        if ($ms < 50) {
            return 'good_10_50ms';
        }
        if ($ms < 100) {
            return 'acceptable_50_100ms';
        }
        if ($ms < 500) {
            return 'slow_100_500ms';
        }
        if ($ms < 1000) {
            return 'very_slow_500ms_1s';
        }

        return 'critical_gt_1s';
    }

    /**
     * Get latency bucket for async publish/job operations (relaxed thresholds)
     *
     * Buckets optimized for background jobs and message publishing
     * where higher latency is acceptable.
     *
     * @param float $ms Duration in milliseconds
     * @return string Bucket name for metrics tagging
     */
    public static function getPublishLatencyBucket(float $ms): string
    {
        if ($ms < 50) {
            return 'fast_lt_50ms';
        }
        if ($ms < 100) {
            return 'good_50_100ms';
        }
        if ($ms < 500) {
            return 'acceptable_100_500ms';
        }
        if ($ms < 1000) {
            return 'slow_500ms_1s';
        }
        if ($ms < 5000) {
            return 'very_slow_1_5s';
        }

        return 'critical_gt_5s';
    }

    /**
     * Get payload size category for histogram visualization
     *
     * Categories based on typical webhook/API payload sizes.
     *
     * @param int $bytes Payload size in bytes
     * @return string Category name for metrics tagging
     */
    public static function getPayloadSizeCategory(int $bytes): string
    {
        if ($bytes < 1024) {
            return 'tiny_lt_1kb';
        }
        if ($bytes < 10240) {
            return 'small_1_10kb';
        }
        if ($bytes < 102400) {
            return 'medium_10_100kb';
        }
        if ($bytes < 1048576) {
            return 'large_100kb_1mb';
        }
        if ($bytes < 10485760) {
            return 'xlarge_1_10mb';
        }

        return 'huge_gt_10mb';
    }

    /**
     * Get HTTP status class (2xx, 4xx, 5xx) for aggregated metrics
     *
     * Useful for SLI calculations where you need success rate
     * without tracking individual status codes.
     *
     * @param int $statusCode HTTP status code
     * @return string Status class (2xx, 3xx, 4xx, 5xx, or unknown)
     */
    public static function getStatusClass(int $statusCode): string
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return '2xx';
        }
        if ($statusCode >= 300 && $statusCode < 400) {
            return '3xx';
        }
        if ($statusCode >= 400 && $statusCode < 500) {
            return '4xx';
        }
        if ($statusCode >= 500 && $statusCode < 600) {
            return '5xx';
        }

        return 'unknown';
    }

    /**
     * Normalize content type to common categories
     *
     * Maps various MIME types to simplified categories for
     * consistent metric tagging and analysis.
     *
     * @param string|null $contentType Raw Content-Type header value
     * @return string Normalized category name
     */
    public static function normalizeContentType(?string $contentType): string
    {
        if (!$contentType) {
            return 'none';
        }

        $contentType = strtolower($contentType);

        if (str_contains($contentType, 'application/json')) {
            return 'json';
        }
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            return 'form_urlencoded';
        }
        if (str_contains($contentType, 'multipart/form-data')) {
            return 'multipart';
        }
        if (str_contains($contentType, 'text/plain')) {
            return 'plain_text';
        }
        if (str_contains($contentType, 'application/xml') || str_contains($contentType, 'text/xml')) {
            return 'xml';
        }

        return 'other';
    }

    /**
     * Categorize exceptions for error tracking
     *
     * Analyzes exception message to determine root cause category.
     * Used for automated error classification in dashboards.
     *
     * @param \Exception $e Exception to categorize
     * @return string Error category for metrics tagging
     */
    public static function categorizeError(\Exception $e): string
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'database') || str_contains($message, 'connection refused') || str_contains($message, 'pdo')) {
            return 'database_error';
        }
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
            return 'timeout';
        }
        if (str_contains($message, 'disk') || str_contains($message, 'space') || str_contains($message, 'no space left')) {
            return 'disk_full';
        }
        if (str_contains($message, 'memory') || str_contains($message, 'allowed memory')) {
            return 'out_of_memory';
        }
        if (str_contains($message, 'rabbitmq') || str_contains($message, 'amqp')) {
            return 'rabbitmq_error';
        }

        return 'unknown';
    }

    /**
     * Extract provider/service name from event name
     *
     * Parses event names like "webhook.stripe" to extract "stripe".
     *
     * @param string $eventName Full event name
     * @param string $prefix Event name prefix to remove (default: "webhook.")
     * @return string Extracted provider name or "unknown"
     */
    public static function extractProvider(string $eventName, string $prefix = 'webhook.'): string
    {
        if (str_starts_with($eventName, $prefix)) {
            return substr($eventName, strlen($prefix));
        }

        return 'unknown';
    }
}
