<?php

namespace AlexFN\NanoService\Exceptions;

use AlexFN\NanoService\Contracts\TransientWaitException;
use RuntimeException;

// Infra outage (DB/broker unreachable) wrapper: retried past `tries` up to TRANSIENT_MAX_TRIES.
class TransientInfraException extends RuntimeException implements TransientWaitException
{
    public static function wrap(\Throwable $e): self
    {
        return new self('transient infra error: ' . $e->getMessage(), (int) $e->getCode(), $e);
    }
}
