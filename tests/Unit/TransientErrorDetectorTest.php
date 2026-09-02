<?php

namespace AlexFN\NanoService\Tests\Unit;

use AlexFN\NanoService\Contracts\TransientWaitException;
use AlexFN\NanoService\Exceptions\TransientInfraException;
use AlexFN\NanoService\TransientErrorDetector;
use PHPUnit\Framework\TestCase;

class TransientErrorDetectorTest extends TestCase
{
    private function pdo(string $message, string $sqlState = 'HY000', ?int $driverCode = null): \PDOException
    {
        $e = new \PDOException($message);
        $e->errorInfo = [$sqlState, $driverCode, $message];

        return $e;
    }

    public function testMysqlConnectionRefusedIsTransient(): void
    {
        // Connect-phase failure: errorInfo not populated, code only in the message
        $e = new \PDOException('SQLSTATE[HY000] [2002] Connection refused');
        $this->assertTrue(TransientErrorDetector::isTransient($e));
    }

    public function testMysqlGoneAwayDriverCodeIsTransient(): void
    {
        $this->assertTrue(TransientErrorDetector::isTransient(
            $this->pdo('MySQL server has gone away', 'HY000', 2006)
        ));
    }

    public function testPostgresConnectionExceptionClassIsTransient(): void
    {
        $this->assertTrue(TransientErrorDetector::isTransient(
            $this->pdo('server closed the connection unexpectedly', '08006')
        ));
    }

    public function testWrappedInsideFrameworkExceptionChainIsTransient(): void
    {
        // Laravel wraps PDOException into QueryException; detector must walk the chain
        $inner = new \PDOException('SQLSTATE[HY000] [2002] Connection refused');
        $outer = new \RuntimeException('query failed', 0, $inner);
        $this->assertTrue(TransientErrorDetector::isTransient($outer));
    }

    public function testConstraintViolationIsNotTransient(): void
    {
        $this->assertFalse(TransientErrorDetector::isTransient(
            $this->pdo('duplicate key value violates unique constraint', '23505', 7)
        ));
    }

    public function testBusinessExceptionIsNotTransient(): void
    {
        $this->assertFalse(TransientErrorDetector::isTransient(new \RuntimeException('Owner not found')));
    }

    public function testWrapIfTransientWrapsAndPreservesPrevious(): void
    {
        $e = new \PDOException('SQLSTATE[HY000] [2013] Lost connection to MySQL server');
        $wrapped = TransientErrorDetector::wrapIfTransient($e);
        $this->assertInstanceOf(TransientInfraException::class, $wrapped);
        $this->assertInstanceOf(TransientWaitException::class, $wrapped);
        $this->assertSame($e, $wrapped->getPrevious());
    }

    public function testWrapIfTransientKeepsExistingTransientAsIs(): void
    {
        $e = TransientInfraException::wrap(new \RuntimeException('x'));
        $this->assertSame($e, TransientErrorDetector::wrapIfTransient($e));
    }

    public function testWrapIfTransientLeavesBusinessErrorsUntouched(): void
    {
        $e = new \LogicException('bad payload');
        $this->assertSame($e, TransientErrorDetector::wrapIfTransient($e));
    }
}
