<?php

namespace AlexFN\NanoService\Enums;

/**
 * Bounded set of consumer error types for metrics tagging
 *
 * This enum provides a controlled vocabulary for categorizing RabbitMQ
 * consumer errors, preventing cardinality explosion in metrics while
 * maintaining useful error categorization.
 *
 * @package AlexFN\NanoService\Enums
 */
enum ConsumerErrorType: string
{
    /**
     * Message validation failed (missing required fields, invalid JSON)
     */
    case VALIDATION_ERROR = 'validation_error';

    /**
     * Failed to insert message into inbox table
     */
    case INBOX_INSERT_ERROR = 'inbox_insert_error';

    /**
     * Failed to update inbox status (processed, failed, retry count)
     */
    case INBOX_UPDATE_ERROR = 'inbox_update_error';

    /**
     * User-defined catchCallback or failedCallback threw exception
     */
    case USER_CALLBACK_ERROR = 'user_callback_error';

    /**
     * Failed to republish message for retry
     */
    case RETRY_REPUBLISH_ERROR = 'retry_republish_error';

    /**
     * Failed to publish message to DLX (dead-letter exchange)
     */
    case DLX_PUBLISH_ERROR = 'dlx_publish_error';

    /**
     * Failed to acknowledge message in RabbitMQ
     * Note: Already tracked separately with rmq_consumer_ack_failed_total
     */
    case ACK_ERROR = 'ack_error';

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
