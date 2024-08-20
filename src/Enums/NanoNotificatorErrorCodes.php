<?php

namespace AlexFN\NanoService\Enums;

use MyCLabs\Enum\Enum;

final class NanoNotificatorErrorCodes extends Enum
{
    private const DELIVERED = 0;
    private const UNKNOWN = 1;
    private const ABSENT_SUBSCRIBER_TEMPORARY = 2;
    private const ABSENT_SUBSCRIBER_PERMANENT = 3;
    private const CALL_BARRED_BY_USER = 4;
    private const PORTABILITY_ERROR = 5;
    private const ANTI_SPAM_REJECTION = 6;
    private const HANDSET_BUSY = 7;
    private const NETWORK_ERROR = 8;
    private const ILLEGAL_NUMBER = 9;
    private const ILLEGAL_MESSAGE = 10;
    private const UNREACHABLE = 11;
    private const DESTINATION = 12;
    private const SUBSCRIBER_AGE_RESTRICTION = 13;
    private const NUMBER_BLOCKED_BY_CARRIER = 14;
    private const PREPAID_INSUFFICIENT_FUNDS = 15;
    private const GATEWAY_QUOTA_EXCEEDED = 16;
    private const ABNORMAL_SEQUENTIAL_DIALING_DETECTED = 21;
    private const ABNORMAL_TRAFFIC_BURST_DETECTED = 22;
    private const ILLEGAL_SENDER_ADDRESS_FOR_US_DESTINATION = 39;
    private const DAILY_LIMIT_SURPASSED = 41;
    private const ENTITY_FILTER = 50;
    private const HEADER_FILTER = 51;
    private const CONTENT_FILTER = 52;
    private const CONSENT_FILTER = 53;
    private const REGULATION_ERROR = 54;
    private const INVALID_CREDENTIALS = 98;
    private const GENERAL_ERROR = 99;
    
    // IDE autocompletion

    /**
     * Message was delivered successfully
     */
    public static function DELIVERED(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::DELIVERED);
    }

    /**
     * Message was not delivered, and no reason could be determined
     */
    public static function UNKNOWN(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::UNKNOWN);
    }

    /**
     * Message was not delivered because handset was temporarily unavailable - retry
     */
    public static function ABSENT_SUBSCRIBER_TEMPORARY(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::ABSENT_SUBSCRIBER_TEMPORARY);
    }

    /**
     * The number is no longer active and should be removed from your database
     */
    public static function ABSENT_SUBSCRIBER_PERMANENT(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::ABSENT_SUBSCRIBER_PERMANENT);
    }

    /**
     * This is a permanent error:the number should be removed from your database and the user must contact their network operator to remove the bar
     */
    public static function CALL_BARRED_BY_USER(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::CALL_BARRED_BY_USER);
    }

    /**
     * There is an issue relating to portability of the number and you should contact the network operator to resolve it
     */
    public static function PORTABILITY_ERROR(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::PORTABILITY_ERROR);
    }

    /**
     * The message has been blocked by a carrier's anti-spam filter
     */
    public static function ANTI_SPAM_REJECTION(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::ANTI_SPAM_REJECTION);
    }

    /**
     * The handset was not available at the time the message was sent - retry
     */
    public static function HANDSET_BUSY(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::HANDSET_BUSY);
    }

    /**
     * The message failed due to a network error - retry
     */
    public static function NETWORK_ERROR(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::NETWORK_ERROR);
    }

    /**
     * The user has specifically requested not to receive messages from a specific service
     */
    public static function ILLEGAL_NUMBER(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::ILLEGAL_NUMBER);
    }

    /**
     * There is an error in a message parameter, e.g. wrong encoding flag
     */
    public static function ILLEGAL_MESSAGE(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::ILLEGAL_MESSAGE);
    }

    /**
     * EasyWeek cannot find a suitable route to deliver the message - contact mailto:
     */
    public static function UNREACHABLE(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::UNREACHABLE);
    }

    /**
     * A route to the number cannot be found - confirm the recipient's number
     */
    public static function DESTINATION(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::DESTINATION);
    }

    /**
     * The target cannot receive your message due to their age
     */
    public static function SUBSCRIBER_AGE_RESTRICTION(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::SUBSCRIBER_AGE_RESTRICTION);
    }

    /**
     * The recipient should ask their carrier to enable SMS on their plan
     */
    public static function NUMBER_BLOCKED_BY_CARRIER(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::NUMBER_BLOCKED_BY_CARRIER);
    }

    /**
     * The recipient is on a prepaid plan and does not have enough credit to receive your message
     */
    public static function PREPAID_INSUFFICIENT_FUNDS(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::PREPAID_INSUFFICIENT_FUNDS);
    }

    /**
     * Message delivery failed because the allowed number of requests per period was exceeded. NB: This error is shown for accounts registered in the US and France only.
     */
    public static function GATEWAY_QUOTA_EXCEEDED(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::GATEWAY_QUOTA_EXCEEDED);
    }

    /**
     * The High Density Contact Number Range threshold has been exceeded
     */
    public static function ABNORMAL_SEQUENTIAL_DIALING_DETECTED(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::ABNORMAL_SEQUENTIAL_DIALING_DETECTED);
    }

    /**
     * The Relative Increase threshold has been exceeded
     */
    public static function ABNORMAL_TRAFFIC_BURST_DETECTED(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::ABNORMAL_TRAFFIC_BURST_DETECTED);
    }

    /**
     * All messages sent to the US must originate from either a U.S. pre-approved long number or short code that is associated with your EasyWeek account.
     */
    public static function ILLEGAL_SENDER_ADDRESS_FOR_US_DESTINATION(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::ILLEGAL_SENDER_ADDRESS_FOR_US_DESTINATION);
    }

    /**
     * Submission Control throttled due to max volume reached for the period
     */
    public static function DAILY_LIMIT_SURPASSED(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::DAILY_LIMIT_SURPASSED);
    }

    /**
     * The message failed due to entity-id being incorrect or not provided. More information on country specific regulations
     */
    public static function ENTITY_FILTER(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::ENTITY_FILTER);
    }

    /**
     * The message failed because the header ID (from phone number) was incorrect or missing. More information on country specific regulations
     */
    public static function HEADER_FILTER(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::HEADER_FILTER);
    }

    /**
     * The message failed due to content-id being incorrect or not provided. More information on country specific regulations
     */
    public static function CONTENT_FILTER(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::CONTENT_FILTER);
    }

    /**
     * The message failed due to consent not being authorized. More information on country specific regulations
     */
    public static function CONSENT_FILTER(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::CONSENT_FILTER);
    }

    /**
     * Unexpected regulation error
     */
    public static function REGULATION_ERROR(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::REGULATION_ERROR);
    }

    /**
     * Invalid Credentials	Some of the credentials are invalid.
     */
    public static function INVALID_CREDENTIALS(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::INVALID_CREDENTIALS);
    }

    /**
     * Typically refers to an error in the route
     */
    public static function GENERAL_ERROR(): NanoNotificatorErrorCodes
    {
        return new NanoNotificatorErrorCodes(self::GENERAL_ERROR);
    }
}
