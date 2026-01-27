<?php

namespace AlexFN\NanoService\Tests\Unit;

use AlexFN\NanoService\Clients\StatsDClient\MetricsBuckets;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MetricsBuckets utility class
 *
 * Tests bucketing and categorization functions for metrics.
 */
class MetricsBucketsTest extends TestCase
{
    // ===========================================
    // HTTP Latency Bucket Tests
    // ===========================================

    /**
     * @dataProvider httpLatencyBucketProvider
     */
    public function testGetHttpLatencyBucket(float $ms, string $expectedBucket): void
    {
        $this->assertEquals($expectedBucket, MetricsBuckets::getHttpLatencyBucket($ms));
    }

    public static function httpLatencyBucketProvider(): array
    {
        return [
            // fast_lt_10ms
            [0, 'fast_lt_10ms'],
            [5, 'fast_lt_10ms'],
            [9.99, 'fast_lt_10ms'],

            // good_10_50ms
            [10, 'good_10_50ms'],
            [25, 'good_10_50ms'],
            [49.99, 'good_10_50ms'],

            // acceptable_50_100ms
            [50, 'acceptable_50_100ms'],
            [75, 'acceptable_50_100ms'],
            [99.99, 'acceptable_50_100ms'],

            // slow_100_500ms
            [100, 'slow_100_500ms'],
            [250, 'slow_100_500ms'],
            [499.99, 'slow_100_500ms'],

            // very_slow_500ms_1s
            [500, 'very_slow_500ms_1s'],
            [750, 'very_slow_500ms_1s'],
            [999.99, 'very_slow_500ms_1s'],

            // critical_gt_1s
            [1000, 'critical_gt_1s'],
            [5000, 'critical_gt_1s'],
            [10000, 'critical_gt_1s'],
        ];
    }

    // ===========================================
    // Publish Latency Bucket Tests
    // ===========================================

    /**
     * @dataProvider publishLatencyBucketProvider
     */
    public function testGetPublishLatencyBucket(float $ms, string $expectedBucket): void
    {
        $this->assertEquals($expectedBucket, MetricsBuckets::getPublishLatencyBucket($ms));
    }

    public static function publishLatencyBucketProvider(): array
    {
        return [
            // fast_lt_50ms
            [0, 'fast_lt_50ms'],
            [25, 'fast_lt_50ms'],
            [49.99, 'fast_lt_50ms'],

            // good_50_100ms
            [50, 'good_50_100ms'],
            [75, 'good_50_100ms'],
            [99.99, 'good_50_100ms'],

            // acceptable_100_500ms
            [100, 'acceptable_100_500ms'],
            [250, 'acceptable_100_500ms'],
            [499.99, 'acceptable_100_500ms'],

            // slow_500ms_1s
            [500, 'slow_500ms_1s'],
            [750, 'slow_500ms_1s'],
            [999.99, 'slow_500ms_1s'],

            // very_slow_1_5s
            [1000, 'very_slow_1_5s'],
            [2500, 'very_slow_1_5s'],
            [4999.99, 'very_slow_1_5s'],

            // critical_gt_5s
            [5000, 'critical_gt_5s'],
            [10000, 'critical_gt_5s'],
            [60000, 'critical_gt_5s'],
        ];
    }

    // ===========================================
    // Payload Size Category Tests
    // ===========================================

    /**
     * @dataProvider payloadSizeCategoryProvider
     */
    public function testGetPayloadSizeCategory(int $bytes, string $expectedCategory): void
    {
        $this->assertEquals($expectedCategory, MetricsBuckets::getPayloadSizeCategory($bytes));
    }

    public static function payloadSizeCategoryProvider(): array
    {
        return [
            // tiny_lt_1kb
            [0, 'tiny_lt_1kb'],
            [512, 'tiny_lt_1kb'],
            [1023, 'tiny_lt_1kb'],

            // small_1_10kb
            [1024, 'small_1_10kb'],
            [5120, 'small_1_10kb'],
            [10239, 'small_1_10kb'],

            // medium_10_100kb
            [10240, 'medium_10_100kb'],
            [51200, 'medium_10_100kb'],
            [102399, 'medium_10_100kb'],

            // large_100kb_1mb
            [102400, 'large_100kb_1mb'],
            [512000, 'large_100kb_1mb'],
            [1048575, 'large_100kb_1mb'],

            // xlarge_1_10mb
            [1048576, 'xlarge_1_10mb'],
            [5242880, 'xlarge_1_10mb'],
            [10485759, 'xlarge_1_10mb'],

            // huge_gt_10mb
            [10485760, 'huge_gt_10mb'],
            [52428800, 'huge_gt_10mb'],
            [104857600, 'huge_gt_10mb'],
        ];
    }

    // ===========================================
    // Status Class Tests
    // ===========================================

    /**
     * @dataProvider statusClassProvider
     */
    public function testGetStatusClass(int $statusCode, string $expectedClass): void
    {
        $this->assertEquals($expectedClass, MetricsBuckets::getStatusClass($statusCode));
    }

    public static function statusClassProvider(): array
    {
        return [
            // 2xx
            [200, '2xx'],
            [201, '2xx'],
            [204, '2xx'],
            [299, '2xx'],

            // 3xx
            [300, '3xx'],
            [301, '3xx'],
            [302, '3xx'],
            [399, '3xx'],

            // 4xx
            [400, '4xx'],
            [401, '4xx'],
            [404, '4xx'],
            [422, '4xx'],
            [499, '4xx'],

            // 5xx
            [500, '5xx'],
            [502, '5xx'],
            [503, '5xx'],
            [599, '5xx'],

            // unknown
            [100, 'unknown'],
            [199, 'unknown'],
            [600, 'unknown'],
            [0, 'unknown'],
        ];
    }

    // ===========================================
    // Content Type Normalization Tests
    // ===========================================

    /**
     * @dataProvider contentTypeProvider
     */
    public function testNormalizeContentType(?string $contentType, string $expectedNormalized): void
    {
        $this->assertEquals($expectedNormalized, MetricsBuckets::normalizeContentType($contentType));
    }

    public static function contentTypeProvider(): array
    {
        return [
            // null/empty
            [null, 'none'],
            ['', 'none'],

            // JSON
            ['application/json', 'json'],
            ['application/json; charset=utf-8', 'json'],
            ['APPLICATION/JSON', 'json'],

            // Form URL encoded
            ['application/x-www-form-urlencoded', 'form_urlencoded'],
            ['application/x-www-form-urlencoded; charset=UTF-8', 'form_urlencoded'],

            // Multipart
            ['multipart/form-data', 'multipart'],
            ['multipart/form-data; boundary=----WebKitFormBoundary', 'multipart'],

            // Plain text
            ['text/plain', 'plain_text'],
            ['text/plain; charset=utf-8', 'plain_text'],

            // XML
            ['application/xml', 'xml'],
            ['text/xml', 'xml'],
            ['application/xml; charset=utf-8', 'xml'],

            // Other
            ['application/octet-stream', 'other'],
            ['image/png', 'other'],
            ['unknown/type', 'other'],
        ];
    }

    // ===========================================
    // Error Categorization Tests
    // ===========================================

    /**
     * @dataProvider errorCategorizeProvider
     */
    public function testCategorizeError(string $message, string $expectedCategory): void
    {
        $exception = new \Exception($message);
        $this->assertEquals($expectedCategory, MetricsBuckets::categorizeError($exception));
    }

    public static function errorCategorizeProvider(): array
    {
        return [
            // Database errors
            ['Database connection failed', 'database_error'],
            ['Connection refused to mysql:3306', 'database_error'],
            ['PDO exception occurred', 'database_error'],

            // Timeout errors
            ['Operation timed out', 'timeout'],
            ['Request timeout after 30 seconds', 'timeout'],

            // Disk errors
            ['No space left on device', 'disk_full'],
            ['Disk quota exceeded', 'disk_full'],

            // Memory errors
            ['Allowed memory size exhausted', 'out_of_memory'],
            ['Out of memory', 'out_of_memory'],

            // RabbitMQ errors
            ['RabbitMQ connection lost', 'rabbitmq_error'],
            ['AMQP channel error', 'rabbitmq_error'],

            // Redis errors
            ['Redis connection failed', 'redis_error'],

            // Unknown
            ['Something went wrong', 'unknown'],
            ['Undefined error', 'unknown'],
            ['', 'unknown'],
        ];
    }

    // ===========================================
    // Provider Extraction Tests
    // ===========================================

    /**
     * @dataProvider providerExtractionProvider
     */
    public function testExtractProvider(string $eventName, string $prefix, string $expectedProvider): void
    {
        $this->assertEquals($expectedProvider, MetricsBuckets::extractProvider($eventName, $prefix));
    }

    public static function providerExtractionProvider(): array
    {
        return [
            // Standard webhook prefix
            ['webhook.stripe', 'webhook.', 'stripe'],
            ['webhook.paypal', 'webhook.', 'paypal'],
            ['webhook.plaid', 'webhook.', 'plaid'],

            // Custom prefix
            ['event.user.created', 'event.', 'user.created'],
            ['custom.provider.action', 'custom.', 'provider.action'],

            // No match - returns unknown
            ['stripe.webhook', 'webhook.', 'unknown'],
            ['notification.email', 'webhook.', 'unknown'],

            // Empty event name
            ['webhook.', 'webhook.', ''],

            // Default prefix (webhook.)
            ['webhook.test', 'webhook.', 'test'],
        ];
    }

    public function testExtractProviderWithDefaultPrefix(): void
    {
        $this->assertEquals('stripe', MetricsBuckets::extractProvider('webhook.stripe'));
        $this->assertEquals('unknown', MetricsBuckets::extractProvider('event.something'));
    }

    // ===========================================
    // Edge Cases
    // ===========================================

    public function testHttpLatencyBucketWithNegativeValue(): void
    {
        // Negative values should fall into fastest bucket
        $this->assertEquals('fast_lt_10ms', MetricsBuckets::getHttpLatencyBucket(-10));
    }

    public function testPublishLatencyBucketWithNegativeValue(): void
    {
        // Negative values should fall into fastest bucket
        $this->assertEquals('fast_lt_50ms', MetricsBuckets::getPublishLatencyBucket(-100));
    }

    public function testPayloadSizeCategoryWithZeroBytes(): void
    {
        $this->assertEquals('tiny_lt_1kb', MetricsBuckets::getPayloadSizeCategory(0));
    }

    public function testCategorizeErrorCaseInsensitive(): void
    {
        // Error messages should be matched case-insensitively
        $exception = new \Exception('DATABASE CONNECTION FAILED');
        $this->assertEquals('database_error', MetricsBuckets::categorizeError($exception));

        $exception = new \Exception('RABBITMQ Error');
        $this->assertEquals('rabbitmq_error', MetricsBuckets::categorizeError($exception));
    }
}
