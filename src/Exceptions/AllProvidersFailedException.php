<?php

namespace CodingPartners\TranslaGenius\Exceptions;

/**
 * Raised by Providers\FallbackProvider when every provider in its chain
 * fails. A TranslationFailedException subclass (not a plain aggregate
 * exception type) so it's still caught by TranslateFields::handle()'s
 * existing `catch (TranslationFailedException $e)` and still triggers
 * Events\TranslationFailed from failed() exactly like a single-provider
 * failure would - FallbackProvider is a drop-in TranslationProviderInterface
 * implementation, and callers shouldn't need special-case handling for it.
 *
 * Deliberately carries every provider's individual failure rather than just
 * the last one - when a chain has 2+ providers, "the last one failed" on
 * its own doesn't say whether the earlier ones failed the same way (a
 * shared misconfiguration) or differently (each genuinely down), which is
 * exactly the information worth having to diagnose a fully-failed chain.
 *
 * @see \CodingPartners\TranslaGenius\Providers\FallbackProvider
 */
class AllProvidersFailedException extends TranslationFailedException
{
    /**
     * @param array<int, array{provider: class-string, message: string, status_code: int|null, retryable: bool}> $failures
     *        One entry per provider in the chain, in the order they were
     *        tried.
     */
    public function __construct(protected readonly array $failures)
    {
        parent::__construct(
            self::buildMessage($failures),
            retryable: self::anyRetryable($failures),
        );
    }

    /**
     * Every provider's individual failure, in try order - for logging or
     * building a more detailed error report than getMessage() alone gives.
     *
     * @return array<int, array{provider: class-string, message: string, status_code: int|null, retryable: bool}>
     */
    public function getFailures(): array
    {
        return $this->failures;
    }

    /**
     * @param array<int, array{provider: class-string, message: string, status_code: int|null, retryable: bool}> $failures
     * @return string
     */
    private static function buildMessage(array $failures): string
    {
        $summary = implode('; ', array_map(
            fn (array $failure) => "{$failure['provider']}: {$failure['message']}",
            $failures
        ));

        return sprintf('All %d configured translation provider(s) failed: %s', count($failures), $summary);
    }

    /**
     * The chain as a whole is worth a queue retry if any individual
     * provider's failure was itself retryable - a transient blip on one
     * provider might clear up by the next attempt, even if another
     * provider in the same chain failed for a permanent reason (bad key).
     * Only when every provider failed non-retryably is retrying the whole
     * chain again guaranteed to be futile.
     *
     * @param array<int, array{provider: class-string, message: string, status_code: int|null, retryable: bool}> $failures
     * @return bool
     */
    private static function anyRetryable(array $failures): bool
    {
        foreach ($failures as $failure) {
            if ($failure['retryable']) {
                return true;
            }
        }

        return false;
    }
}
