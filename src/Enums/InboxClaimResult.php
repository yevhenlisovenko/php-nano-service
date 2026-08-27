<?php

namespace AlexFN\NanoService\Enums;

/**
 * Outcome of the inbox insert/claim step for one delivery.
 */
enum InboxClaimResult
{
    /** Row inserted or stale lock claimed — this worker owns the message */
    case OWNED;

    /** Row already marked processed — ACK and skip (idempotency) */
    case PROCESSED;

    /** Row is locked by another worker whose lock is not stale yet — owner may be alive or dead */
    case LOCKED;
}
