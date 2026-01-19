<?php

namespace AlexFN\NanoService\Enums;

/**
 * Bounded set of publish error types for metrics tagging
 *
 * This enum provides a controlled vocabulary for categorizing RabbitMQ
 * publish errors, preventing cardinality explosion in metrics while
 * maintaining useful error categorization.
 *
 * @package AlexFN\NanoService\Enums
 */
enum PublishErrorType: string
{
    /**
     * Failed to establish connection to RabbitMQ server
     * Examples: Network unreachable, connection refused, DNS lookup failed
     */
    case CONNECTION_ERROR = 'connection_error';

    /**
     * RabbitMQ channel operation failed
     * Examples: Channel closed unexpectedly, channel error, channel exception
     */
    case CHANNEL_ERROR = 'channel_error';

    /**
     * Publish operation timed out
     * Examples: Network timeout, slow acknowledgment, server overload
     */
    case TIMEOUT = 'timeout';

    /**
     * Message encoding or serialization failed
     * Examples: JSON encode error, invalid payload structure
     */
    case ENCODING_ERROR = 'encoding_error';

    /**
     * Invalid configuration prevented publish
     * Examples: Missing exchange, invalid routing key, misconfigured channel
     */
    case CONFIG_ERROR = 'config_error';

    /**
     * Unknown or uncategorized error
     * Use this sparingly - investigate and add new types as needed
     */
    case UNKNOWN = 'unknown';

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
