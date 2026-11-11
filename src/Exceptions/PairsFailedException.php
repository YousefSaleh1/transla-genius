<?php

namespace CodingPartners\TranslaGenius\Exceptions;

/**
 * Some field/locale pairs of a translation job failed. The job kept going
 * after each failure, so every other pair was requested and saved; this
 * lists the ones that weren't.
 *
 * Retryable if any failure was: the retry requests only the pairs still
 * missing. getRetryAfter() is the shortest Retry-After among the retryable
 * failures when each of them gave one.
 */
class PairsFailedException extends TranslationFailedException
{
    /**
     * $failures has one entry per failed pair, in the order they were tried.
     *
     * @param  array<int, array{field: string, locale: string, exception: TranslationFailedException}>  $failures
     */
    public function __construct(protected readonly array $failures)
    {
        $statuses = array_values(array_unique(array_map(fn (array $f) => $f['exception']->getStatusCode(), $failures)));

        parent::__construct(
            self::buildMessage($failures),
            statusCode: count($statuses) === 1 ? $statuses[0] : null,
            retryable: self::anyRetryable($failures),
            previous: $failures[0]['exception'] ?? null,
            retryAfter: self::shortestRetryAfter($failures),
        );
    }

    /**
     * Every failed pair, with its own exception.
     *
     * @return array<int, array{field: string, locale: string, exception: TranslationFailedException}>
     */
    public function getFailures(): array
    {
        return $this->failures;
    }

    /** @param array<int, array{field: string, locale: string, exception: TranslationFailedException}> $failures */
    private static function buildMessage(array $failures): string
    {
        $summary = implode('; ', array_map(
            fn (array $f) => "{$f['field']}/{$f['locale']}: {$f['exception']->getMessage()}",
            $failures
        ));

        return sprintf('%d translation pair(s) failed: %s', count($failures), $summary);
    }

    /** @param array<int, array{field: string, locale: string, exception: TranslationFailedException}> $failures */
    private static function anyRetryable(array $failures): bool
    {
        foreach ($failures as $failure) {
            if ($failure['exception']->isRetryable()) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array{field: string, locale: string, exception: TranslationFailedException}> $failures */
    private static function shortestRetryAfter(array $failures): ?int
    {
        $waits = [];

        foreach ($failures as $failure) {
            if (! $failure['exception']->isRetryable()) {
                continue;
            }

            $wait = $failure['exception']->getRetryAfter();
            if ($wait === null) {
                return null;
            }

            $waits[] = $wait;
        }

        return $waits === [] ? null : min($waits);
    }
}
