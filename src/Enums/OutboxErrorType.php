<?php

namespace AlexFN\NanoService\Enums;

/**
 * Bounded set of outbox error types for metrics tagging
 *
 * This enum provides a controlled vocabulary for categorizing outbox pattern
 * errors in the publisher, preventing cardinality explosion in metrics while
 * maintaining useful error categorization.
 *
 * @package AlexFN\NanoService\Enums
 */
enum OutboxErrorType: string
{
    /**
     * Message validation failed (message not set, empty message ID)
     */
    case VALIDATION_ERROR = 'validation_error';

    /**
     * Failed to insert event trace for distributed tracing
     */
    case TRACE_INSERT_ERROR = 'trace_insert_error';

    /**
     * Failed to update outbox status (published, pending)
     */
    case OUTBOX_UPDATE_ERROR = 'outbox_update_error';

    /**
     * Get the error type value as string
     *
     * @return string Error type identifier
     */
    public function getValue(): string
    {
        return $this->value;
    }
}
