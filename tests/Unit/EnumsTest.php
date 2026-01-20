<?php

namespace AlexFN\NanoService\Tests\Unit;

use AlexFN\NanoService\Clients\StatsDClient\Enums\EventExitStatusTag;
use AlexFN\NanoService\Clients\StatsDClient\Enums\EventRetryStatusTag;
use AlexFN\NanoService\Enums\PublishErrorType;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for all enum classes
 *
 * Tests enum values, getValue() method, and type safety.
 */
class EnumsTest extends TestCase
{
    // ==========================================
    // EventExitStatusTag Tests
    // ==========================================

    public function testEventExitStatusTagHasSuccessCase(): void
    {
        $this->assertEquals('success', EventExitStatusTag::SUCCESS->value);
    }

    public function testEventExitStatusTagHasFailedCase(): void
    {
        $this->assertEquals('failed', EventExitStatusTag::FAILED->value);
    }

    public function testEventExitStatusTagCasesAreBounded(): void
    {
        $cases = EventExitStatusTag::cases();

        $this->assertCount(2, $cases);
        $this->assertContains(EventExitStatusTag::SUCCESS, $cases);
        $this->assertContains(EventExitStatusTag::FAILED, $cases);
    }

    // ==========================================
    // EventRetryStatusTag Tests
    // ==========================================

    public function testEventRetryStatusTagHasFirstCase(): void
    {
        $this->assertEquals('first', EventRetryStatusTag::FIRST->value);
    }

    public function testEventRetryStatusTagHasRetryCase(): void
    {
        $this->assertEquals('retry', EventRetryStatusTag::RETRY->value);
    }

    public function testEventRetryStatusTagHasLastCase(): void
    {
        $this->assertEquals('last', EventRetryStatusTag::LAST->value);
    }

    public function testEventRetryStatusTagCasesAreBounded(): void
    {
        $cases = EventRetryStatusTag::cases();

        $this->assertCount(3, $cases);
        $this->assertContains(EventRetryStatusTag::FIRST, $cases);
        $this->assertContains(EventRetryStatusTag::RETRY, $cases);
        $this->assertContains(EventRetryStatusTag::LAST, $cases);
    }

    // ==========================================
    // PublishErrorType Tests
    // ==========================================

    public function testPublishErrorTypeConnectionError(): void
    {
        $errorType = PublishErrorType::CONNECTION_ERROR;

        $this->assertEquals('connection_error', $errorType->value);
        $this->assertEquals('connection_error', $errorType->getValue());
    }

    public function testPublishErrorTypeChannelError(): void
    {
        $errorType = PublishErrorType::CHANNEL_ERROR;

        $this->assertEquals('channel_error', $errorType->value);
        $this->assertEquals('channel_error', $errorType->getValue());
    }

    public function testPublishErrorTypeTimeout(): void
    {
        $errorType = PublishErrorType::TIMEOUT;

        $this->assertEquals('timeout', $errorType->value);
        $this->assertEquals('timeout', $errorType->getValue());
    }

    public function testPublishErrorTypeEncodingError(): void
    {
        $errorType = PublishErrorType::ENCODING_ERROR;

        $this->assertEquals('encoding_error', $errorType->value);
        $this->assertEquals('encoding_error', $errorType->getValue());
    }

    public function testPublishErrorTypeConfigError(): void
    {
        $errorType = PublishErrorType::CONFIG_ERROR;

        $this->assertEquals('config_error', $errorType->value);
        $this->assertEquals('config_error', $errorType->getValue());
    }

    public function testPublishErrorTypeUnknown(): void
    {
        $errorType = PublishErrorType::UNKNOWN;

        $this->assertEquals('unknown', $errorType->value);
        $this->assertEquals('unknown', $errorType->getValue());
    }

    public function testPublishErrorTypeCasesAreBounded(): void
    {
        $cases = PublishErrorType::cases();

        $this->assertCount(6, $cases);
        $this->assertContains(PublishErrorType::CONNECTION_ERROR, $cases);
        $this->assertContains(PublishErrorType::CHANNEL_ERROR, $cases);
        $this->assertContains(PublishErrorType::TIMEOUT, $cases);
        $this->assertContains(PublishErrorType::ENCODING_ERROR, $cases);
        $this->assertContains(PublishErrorType::CONFIG_ERROR, $cases);
        $this->assertContains(PublishErrorType::UNKNOWN, $cases);
    }

    public function testPublishErrorTypeGetValueReturnsString(): void
    {
        foreach (PublishErrorType::cases() as $case) {
            $this->assertIsString($case->getValue());
            $this->assertEquals($case->value, $case->getValue());
        }
    }
}
