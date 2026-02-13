<?php

declare(strict_types=1);

namespace AlexFN\NanoService\Clients\StatsDClient;

/**
 * HTTP Request Metrics Helper
 *
 * Drop-in replacement for manual StatsD middleware. Preserves the same metric names
 * (`incoming_request`, `http_request`) so existing Grafana dashboards keep working,
 * while adding richer observability (latency buckets, status class, error categorization).
 *
 * Usage in Laravel middleware:
 * ```php
 * $metrics = new HttpMetrics($statsd, $routeName, $request->method());
 * $metrics->start();
 *
 * try {
 *     $response = $next($request);
 *     $metrics->setStatusCode($response->getStatusCode());
 *     return $response;
 * } catch (\Exception $e) {
 *     $metrics->recordError($e, 500);
 *     throw $e;
 * } finally {
 *     $metrics->finish();
 * }
 * ```
 *
 * Metrics sent:
 * - `incoming_request` (counter) — tags: `method`, `route`
 * - `http_request` (timing, ms) — tags: `code`, `route`, `method`
 * - `http_response_status_total` (counter) — tags: `route`, `method`, `status_code`, `status_class`
 * - `http_request_by_latency_bucket` (counter) — tags: `route`, `latency_bucket`
 * - `http_request_errors` (counter, only on error) — tags: `route`, `error_reason`
 *
 * @package AlexFN\NanoService\Clients\StatsDClient
 */
final class HttpMetrics
{
    private StatsDClient $statsd;
    private string $route;
    private string $method;
    private int $statusCode = 200;
    private bool $started = false;

    /**
     * @param StatsDClient $statsd StatsD client instance
     * @param string $route Route/action name (e.g., "App\Http\Controllers\WebhookController@handle")
     * @param string $method HTTP method (GET, POST, etc.)
     */
    public function __construct(
        StatsDClient $statsd,
        string $route,
        string $method
    ) {
        $this->statsd = $statsd;
        $this->route = $route;
        $this->method = $method;
    }

    /**
     * Start tracking — records incoming_request counter
     */
    public function start(): void
    {
        $this->statsd->startTimer('http_request');
        $this->started = true;

        $this->statsd->increment('incoming_request', 1, 1, [
            'method' => $this->method,
            'route' => $this->route,
        ]);
    }

    /**
     * Set HTTP response status code
     *
     * @param int $statusCode HTTP status code (e.g., 200, 404, 500)
     */
    public function setStatusCode(int $statusCode): void
    {
        $this->statusCode = $statusCode;
    }

    /**
     * Record an error — sets status code and sends error metric
     *
     * @param \Exception $e The exception that occurred
     * @param int $statusCode HTTP status code (default: 500)
     */
    public function recordError(\Exception $e, int $statusCode = 500): void
    {
        $this->statusCode = $statusCode;

        $this->statsd->increment('http_request_errors', 1, 1, [
            'route' => $this->route,
            'error_reason' => MetricsBuckets::categorizeError($e),
        ]);
    }

    /**
     * Finish tracking — records http_request timing + extra metrics
     *
     * MUST be called in a finally block.
     */
    public function finish(): void
    {
        if (!$this->started) {
            return;
        }

        $durationMs = $this->statsd->endTimer('http_request') ?? 0;

        // Core metric: same as old middleware (preserves Grafana dashboards)
        $this->statsd->timing('http_request', (float) $durationMs, [
            'code' => (string) $this->statusCode,
            'route' => $this->route,
            'method' => $this->method,
        ]);

        // Status class tracking (2xx, 4xx, 5xx)
        $this->statsd->increment('http_response_status_total', 1, 1, [
            'route' => $this->route,
            'method' => $this->method,
            'status_code' => (string) $this->statusCode,
            'status_class' => MetricsBuckets::getStatusClass($this->statusCode),
        ]);

        // SLO tracking: latency buckets
        $this->statsd->increment('http_request_by_latency_bucket', 1, 1, [
            'route' => $this->route,
            'latency_bucket' => MetricsBuckets::getHttpLatencyBucket($durationMs),
        ]);

        $this->started = false;
    }
}
