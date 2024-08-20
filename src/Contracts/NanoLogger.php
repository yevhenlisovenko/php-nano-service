<?php

namespace AlexFN\NanoService\Contracts;

use AlexFN\NanoService\Contracts\NanoLogger as NanoLoggerContract;
use AlexFN\NanoService\Enums\NanoNotificatorErrorCodes;
use AlexFN\NanoService\NanoServiceMessage;

interface NanoLogger
{
    /**
     * Set message
     */
    public function setLog(NanoServiceMessage $message): self;

    /**
     * Set tenant credentials
     */
    public function processed(NanoNotificatorErrorCodes $code, string $debug = null): self;

    public function failed(NanoNotificatorErrorCodes $code, string $debug = null): self;

    public function delivered(NanoNotificatorErrorCodes $code = null, string $debug = null): self;

    public function rejected(NanoNotificatorErrorCodes $code, string $debug = null): self;

    public function spam_report(NanoNotificatorErrorCodes $code, string $debug = null): self;

    public function expired(NanoNotificatorErrorCodes $code, string $debug = null): self;

    public function open(NanoNotificatorErrorCodes $code, string $debug = null): self;

    public function click(NanoNotificatorErrorCodes $code, string $debug = null): self;

    public function unknown(NanoNotificatorErrorCodes $code, string $debug = null): self;

    public function deferred(NanoNotificatorErrorCodes $code, string $debug = null): self;

    public function publishFallback(): void;
}
