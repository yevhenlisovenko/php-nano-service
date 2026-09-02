<?php

namespace AlexFN\NanoService\Classifiers;

use Throwable;

/**
 * Opt-in classifier for consumers whose handlers call INTERNAL HTTP targets:
 * register via NanoConsumer::transientWhen(new HttpTransientErrorClassifier()).
 * NOT auto-enabled — for external endpoints blanket transient retry is wrong.
 *
 * Matches, walking the getPrevious() chain:
 *  - Guzzle ConnectException (when guzzle is installed)
 *  - Guzzle BadResponseException with 502/503/504
 *  - message fragments "cURL error 6|7|28|35|52|56" and "(HTTP 502|503|504)" — the
 *    standard reason format of services that fold an HTTP result into a plain
 *    RuntimeException instead of throwing the client exception (e.g. event2hook).
 */
final class HttpTransientErrorClassifier
{
    private const CURL_CODES = '/cURL error (6|7|28|35|52|56)\b/';

    private const GATEWAY_HTTP = '/\(HTTP (502|503|504)\)/';

    public function __invoke(Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if (class_exists(\GuzzleHttp\Exception\ConnectException::class)
                && $cur instanceof \GuzzleHttp\Exception\ConnectException
            ) {
                return true;
            }

            if (class_exists(\GuzzleHttp\Exception\BadResponseException::class)
                && $cur instanceof \GuzzleHttp\Exception\BadResponseException
                && in_array($cur->getResponse()->getStatusCode(), [502, 503, 504], true)
            ) {
                return true;
            }

            $msg = $cur->getMessage();
            if (preg_match(self::CURL_CODES, $msg) === 1 || preg_match(self::GATEWAY_HTTP, $msg) === 1) {
                return true;
            }
        }

        return false;
    }
}
