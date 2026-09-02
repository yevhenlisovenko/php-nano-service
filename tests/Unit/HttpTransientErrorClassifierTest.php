<?php

namespace AlexFN\NanoService\Tests\Unit;

use AlexFN\NanoService\Classifiers\HttpTransientErrorClassifier;
use PHPUnit\Framework\TestCase;

class HttpTransientErrorClassifierTest extends TestCase
{
    private HttpTransientErrorClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new HttpTransientErrorClassifier;
    }

    public function testConnectCurlErrorInMessageIsTransient(): void
    {
        // The folded-result format (event2hook style): plain RuntimeException with the cURL text
        $e = new \RuntimeException('Webhook delivery failed: cURL error 7: Failed to connect to host (HTTP 0)');
        $this->assertTrue(($this->classifier)($e));
    }

    public function testGatewayStatusInMessageIsTransient(): void
    {
        $e = new \RuntimeException('Webhook delivery failed: Server error (HTTP 502)');
        $this->assertTrue(($this->classifier)($e));
    }

    public function testAppServerErrorIsNotTransient(): void
    {
        // 500 = the target application is broken, not our infra — must dead-letter normally.
        $e = new \RuntimeException('Webhook delivery failed: Server error (HTTP 500)');
        $this->assertFalse(($this->classifier)($e));
    }

    public function testBusinessErrorIsNotTransient(): void
    {
        $this->assertFalse(($this->classifier)(new \RuntimeException('Owner not found')));
    }

    public function testWalksThePreviousChain(): void
    {
        $inner = new \RuntimeException('cURL error 28: Operation timed out');
        $outer = new \LogicException('delivery wrapper', 0, $inner);
        $this->assertTrue(($this->classifier)($outer));
    }

    public function testCurlCodeOutsideConnectClassIsNotTransient(): void
    {
        // cURL error 22 (HTTP returned error) is a response, not a connect failure.
        $e = new \RuntimeException('cURL error 22: The requested URL returned error');
        $this->assertFalse(($this->classifier)($e));
    }
}
