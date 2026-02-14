<?php

namespace AlexFN\NanoService\Validators;

use AlexFN\NanoService\Clients\StatsDClient\StatsDClient;
use AlexFN\NanoService\Enums\ConsumerErrorType;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

/**
 * Message structure validator
 *
 * Validates incoming RabbitMQ messages for:
 * - Required fields (type, message_id, app_id)
 * - Valid JSON payload
 * - Tracks validation errors in metrics
 */
class MessageValidator
{
    private StatsDClient $statsD;
    private LoggerInterface $logger;
    private string $microserviceName;

    public function __construct(
        StatsDClient $statsD,
        LoggerInterface $logger,
        string $microserviceName
    ) {
        $this->statsD = $statsD;
        $this->logger = $logger;
        $this->microserviceName = $microserviceName;
    }

    /**
     * Validate incoming message structure and required fields
     *
     * Validates:
     * - type (event name) - required
     * - message_id - required
     * - app_id (publisher name) - required
     * - Valid JSON payload
     *
     * @param AMQPMessage $message RabbitMQ message
     * @return bool True if valid, false if invalid
     */
    public function validateMessage(AMQPMessage $message): bool
    {
        $errors = [];

        // Check type (event name)
        if (!$message->has('type') || empty($message->get('type'))) {
            $errors[] = 'Missing or empty type';
        }

        // Check message_id
        if (!$message->has('message_id') || empty($message->get('message_id'))) {
            $errors[] = 'Missing or empty message_id';
        }

        // Check app_id (publisher name)
        if (!$message->has('app_id') || empty($message->get('app_id'))) {
            $errors[] = 'Missing or empty app_id';
        }

        // Check valid JSON payload
        $body = $message->getBody();
        if (!empty($body)) {
            json_decode($body);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = 'Invalid JSON payload: ' . json_last_error_msg();
            }
        }

        if (!empty($errors)) {
            // Track validation error
            $this->statsD->increment('rmq_consumer_error_total', 1, 1, [
                'error_type' => ConsumerErrorType::VALIDATION_ERROR->getValue(),
            ]);

            $this->logger->error('nano_consumer_message_invalid', [
                'source' => 'nano-service',
                'message_id' => $message->has('message_id') ? $message->get('message_id') : 'unknown',
                'event' => $message->has('type') ? $message->get('type') : 'unknown',
                'reason' => implode(', ', $errors),
                'extra' => [
                    'publisher' => $message->has('app_id') ? $message->get('app_id') : 'unknown',
                ],
            ]);
            return false;
        }

        return true;
    }
}
