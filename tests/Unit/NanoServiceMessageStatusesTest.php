<?php

namespace AlexFN\NanoService\Tests\Unit;

use AlexFN\NanoService\Enums\NanoServiceMessageStatuses;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NanoServiceMessageStatuses enum
 *
 * Tests status values, factory methods, and isStatusSuccess() behavior.
 */
class NanoServiceMessageStatusesTest extends TestCase
{
    // ==========================================
    // Factory Method Tests
    // ==========================================

    public function testUnknownStatus(): void
    {
        $status = NanoServiceMessageStatuses::UNKNOWN();

        $this->assertInstanceOf(NanoServiceMessageStatuses::class, $status);
        $this->assertEquals('unknown', $status->getValue());
    }

    public function testSuccessStatus(): void
    {
        $status = NanoServiceMessageStatuses::SUCCESS();

        $this->assertInstanceOf(NanoServiceMessageStatuses::class, $status);
        $this->assertEquals('success', $status->getValue());
    }

    public function testErrorStatus(): void
    {
        $status = NanoServiceMessageStatuses::ERROR();

        $this->assertInstanceOf(NanoServiceMessageStatuses::class, $status);
        $this->assertEquals('error', $status->getValue());
    }

    public function testWarningStatus(): void
    {
        $status = NanoServiceMessageStatuses::WARNING();

        $this->assertInstanceOf(NanoServiceMessageStatuses::class, $status);
        $this->assertEquals('warning', $status->getValue());
    }

    public function testInfoStatus(): void
    {
        $status = NanoServiceMessageStatuses::INFO();

        $this->assertInstanceOf(NanoServiceMessageStatuses::class, $status);
        $this->assertEquals('info', $status->getValue());
    }

    public function testDebugStatus(): void
    {
        $status = NanoServiceMessageStatuses::DEBUG();

        $this->assertInstanceOf(NanoServiceMessageStatuses::class, $status);
        $this->assertEquals('debug', $status->getValue());
    }

    // ==========================================
    // isStatusSuccess Tests
    // ==========================================

    public function testIsStatusSuccessReturnsTrueForSuccess(): void
    {
        $status = NanoServiceMessageStatuses::SUCCESS();

        $this->assertTrue($status->isStatusSuccess());
    }

    public function testIsStatusSuccessReturnsFalseForUnknown(): void
    {
        $status = NanoServiceMessageStatuses::UNKNOWN();

        $this->assertFalse($status->isStatusSuccess());
    }

    public function testIsStatusSuccessReturnsFalseForError(): void
    {
        $status = NanoServiceMessageStatuses::ERROR();

        $this->assertFalse($status->isStatusSuccess());
    }

    public function testIsStatusSuccessReturnsFalseForWarning(): void
    {
        $status = NanoServiceMessageStatuses::WARNING();

        $this->assertFalse($status->isStatusSuccess());
    }

    public function testIsStatusSuccessReturnsFalseForInfo(): void
    {
        $status = NanoServiceMessageStatuses::INFO();

        $this->assertFalse($status->isStatusSuccess());
    }

    public function testIsStatusSuccessReturnsFalseForDebug(): void
    {
        $status = NanoServiceMessageStatuses::DEBUG();

        $this->assertFalse($status->isStatusSuccess());
    }

    // ==========================================
    // From Value Tests
    // ==========================================

    public function testFromStringValue(): void
    {
        $status = NanoServiceMessageStatuses::from('success');

        $this->assertInstanceOf(NanoServiceMessageStatuses::class, $status);
        $this->assertEquals('success', $status->getValue());
    }

    public function testFromErrorValue(): void
    {
        $status = NanoServiceMessageStatuses::from('error');

        $this->assertEquals('error', $status->getValue());
    }

    // ==========================================
    // Equality Tests
    // ==========================================

    public function testSameStatusesAreEqual(): void
    {
        $status1 = NanoServiceMessageStatuses::SUCCESS();
        $status2 = NanoServiceMessageStatuses::SUCCESS();

        $this->assertTrue($status1->equals($status2));
    }

    public function testDifferentStatusesAreNotEqual(): void
    {
        $status1 = NanoServiceMessageStatuses::SUCCESS();
        $status2 = NanoServiceMessageStatuses::ERROR();

        $this->assertFalse($status1->equals($status2));
    }
}
