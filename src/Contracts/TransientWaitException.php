<?php

namespace AlexFN\NanoService\Contracts;

// Marker: retryable consumer exceptions implementing this are metered as status=requeued instead of failed.
interface TransientWaitException
{
}
