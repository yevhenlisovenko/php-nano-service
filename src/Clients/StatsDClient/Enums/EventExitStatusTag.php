<?php

namespace AlexFN\NanoService\Clients\StatsDClient\Enums;

enum EventExitStatusTag: string
{
    case SUCCESS = 'success';

    case FAILED = 'failed';
}
