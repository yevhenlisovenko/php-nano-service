<?php

namespace AlexFN\NanoService;

use AlexFN\NanoService\Contracts\TransientWaitException;
use AlexFN\NanoService\Exceptions\TransientInfraException;
use Throwable;

/**
 * Recognizes connection-class DB errors (the service's OWN infra being down) so the
 * consumer waits the outage out instead of burning `tries` and dead-lettering
 * (2026-09-01: a 2h MySQL outage dead-lettered 95 events incl. a Stripe payment).
 * Deliberately narrow: only connection establishment/loss, never SQL/constraint errors.
 */
final class TransientErrorDetector
{
    // MySQL client errors: can't connect / gone away / lost mid-query / connect timeout.
    private const MYSQL_CODES = [2002, 2006, 2013, 2003];

    // SQLSTATE class 08* = connection exception (PG + ANSI); 57P0* = PG shutdown/cannot-connect.
    private const SQLSTATE_PREFIXES = ['08', '57P'];

    /**
     * @param array<callable(Throwable): bool> $classifiers consumer-registered policies
     *        (NanoConsumer::transientWhen) composed with the built-in PDO detection
     */
    public static function isTransient(Throwable $e, array $classifiers = []): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($cur instanceof TransientWaitException) {
                return true;
            }
            if ($cur instanceof \PDOException && self::isConnectionError($cur)) {
                return true;
            }
        }

        foreach ($classifiers as $classifier) {
            if ($classifier($e) === true) {
                return true;
            }
        }

        return false;
    }

    // Wrap so downstream instanceof TransientWaitException checks fire; already-transient stays as is.
    public static function wrapIfTransient(Throwable $e, array $classifiers = []): Throwable
    {
        if ($e instanceof TransientWaitException || !self::isTransient($e, $classifiers)) {
            return $e;
        }

        return TransientInfraException::wrap($e);
    }

    private static function isConnectionError(\PDOException $e): bool
    {
        $sqlState = is_string($e->getCode()) ? $e->getCode() : (string) ($e->errorInfo[0] ?? '');
        foreach (self::SQLSTATE_PREFIXES as $prefix) {
            if ($sqlState !== '' && str_starts_with($sqlState, $prefix)) {
                return true;
            }
        }

        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        if (in_array($driverCode, self::MYSQL_CODES, true)) {
            return true;
        }

        // HY000 + connect-phase messages: PDO reports "SQLSTATE[HY000] [2002] Connection refused"
        // with errorInfo not yet populated when the *connect* itself fails.
        $msg = $e->getMessage();
        foreach (self::MYSQL_CODES as $code) {
            if (str_contains($msg, "[$code]")) {
                return true;
            }
        }

        return false;
    }
}
