<?php

namespace AlexFN\NanoService\Clients\StatsDClient\Enums;

enum EventExitStatusTag: string
{
    case SUCCESS = 'success';

    case FAILED = 'failed';

    // Transient wait: message was republished for retry after a TransientWaitException.
    case REQUEUED = 'requeued';
}
