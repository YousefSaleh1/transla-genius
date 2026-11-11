<?php

namespace CodingPartners\TranslaGenius\Support;

use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use Illuminate\Support\Facades\Log;

/**
 * A queue connection hands a reserved job to another worker once it has
 * been running longer than the connection's `retry_after` (Laravel's
 * database and Redis connections default to 90 seconds), while a
 * translation job may run up to its timeout (up to 900 seconds by
 * default). With retry_after at or below that, a slow job is redelivered
 * while it's still running. Used by translagenius:check and, once per
 * process, by the job itself.
 *
 * @internal Support\ is package wiring; not covered by the backward compatibility promise.
 */
final class QueueTimeouts
{
    private static bool $warned = false;

    /** The queue connection translation jobs go to. */
    public static function connectionName(): string
    {
        $configured = trim((string) config('translagenius.queue.connection'));

        return $configured !== '' ? $configured : (string) config('queue.default');
    }

    /** The connection's retry_after in seconds, or null when it has none (sync, null). */
    public static function retryAfter(string $connection): ?int
    {
        return Setting::nullableInt(config("queue.connections.{$connection}.retry_after"));
    }

    /** TRANSLATION_JOB_TIMEOUT when set, otherwise the most a derived timeout can be. */
    public static function longestJobTimeout(): int
    {
        $configured = Setting::nullableInt(config('translagenius.queue.timeout'));

        return $configured ?? TranslateFields::MAX_TIMEOUT;
    }

    /** A retry_after that clears $jobTimeout. */
    public static function suggestedRetryAfter(int $jobTimeout): int
    {
        return $jobTimeout + 30;
    }

    /**
     * Log one warning per process when $connection's retry_after is at or
     * below $jobTimeout (0: no job timeout, nothing to compare).
     */
    public static function warnIfTooShort(?string $connection, int $jobTimeout): void
    {
        if (self::$warned || $connection === null || $jobTimeout <= 0) {
            return;
        }

        $retryAfter = self::retryAfter($connection);
        if ($retryAfter === null || $retryAfter > $jobTimeout) {
            return;
        }

        self::$warned = true;
        Log::warning("TranslateFields: retry_after ({$retryAfter} s) on queue connection [{$connection}] is not above this job's timeout ({$jobTimeout} s): a job still running then is handed to another worker. Raise retry_after in config/queue.php, or lower TRANSLATION_JOB_TIMEOUT.", [
            'connection' => $connection,
            'retry_after' => $retryAfter,
            'job_timeout' => $jobTimeout,
            'suggested_retry_after' => self::suggestedRetryAfter($jobTimeout),
        ]);
    }

    /** @internal Forget the once-per-process warning (tests). */
    public static function resetWarning(): void
    {
        self::$warned = false;
    }
}
