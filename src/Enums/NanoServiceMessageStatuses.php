<?php

namespace AlexFN\NanoService\Enums;

use MyCLabs\Enum\Enum;

final class NanoServiceMessageStatuses extends Enum
{
    private const UNKNOWN = 'unknown';

    private const SUCCESS = 'success';

    private const ERROR = 'error';

    private const WARNING = 'warning';

    private const INFO = 'info';

    private const DEBUG = 'debug';

    public function isStatusSuccess(): bool
    {
        return $this->value === self::SUCCESS;
    }

    // IDE autocompletion
    public static function UNKNOWN(): NanoServiceMessageStatuses
    {
        return new NanoServiceMessageStatuses(self::UNKNOWN);
    }

    public static function SUCCESS(): NanoServiceMessageStatuses
    {
        return new NanoServiceMessageStatuses(self::SUCCESS);
    }

    public static function ERROR(): NanoServiceMessageStatuses
    {
        return new NanoServiceMessageStatuses(self::ERROR);
    }

    public static function WARNING(): NanoServiceMessageStatuses
    {
        return new NanoServiceMessageStatuses(self::WARNING);
    }

    public static function INFO(): NanoServiceMessageStatuses
    {
        return new NanoServiceMessageStatuses(self::INFO);
    }

    public static function DEBUG(): NanoServiceMessageStatuses
    {
        return new NanoServiceMessageStatuses(self::DEBUG);
    }
}
