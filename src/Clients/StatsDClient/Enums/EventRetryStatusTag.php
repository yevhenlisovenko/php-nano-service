<?php

namespace AlexFN\NanoService\Clients\StatsDClient\Enums;

enum EventRetryStatusTag: string
{
    case FIRST = 'first';
    case RETRY = 'retry';
    case LAST = 'last';
}
