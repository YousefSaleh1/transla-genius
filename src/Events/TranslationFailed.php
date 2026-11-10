<?php

namespace CodingPartners\TranslaGenius\Events;

use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once by TranslateFields::failed() - the job's single, well-defined
 * "this model's translation is permanently done, and it failed" point
 * (Phase 2c's replacement for the old catch-and-swallow behavior).
 *
 * Deliberately not fired on every retryable throw inside handle(): Phase 2c
 * already retries transient failures ($tries/backoff()) before a job ever
 * reaches failed(), so most of those attempts self-resolve and firing an
 * event per attempt would mean up to `$tries` events for one logical
 * translation, none of which the listener could act on any differently
 * than the final one. A consumer that wants per-attempt visibility instead
 * of per-model-failure visibility already has Laravel's own
 * Illuminate\Queue\Events\JobProcessing / JobFailed as a lower-level seam.
 *
 * Only carries a TranslationFailedException, not `failed()`'s wider
 * Throwable parameter - if the job died for some other reason (e.g. a
 * TranslationWriter persistence failure), that isn't a "translation
 * failed" in the domain sense this event names, so it isn't dispatched for
 * that case. See TranslateFields::failed() for how the two are told apart.
 *
 * @see \CodingPartners\TranslaGenius\Jobs\TranslateFields::failed()
 * @see \CodingPartners\TranslaGenius\Exceptions\TranslationFailedException
 */
final readonly class TranslationFailed
{
    use Dispatchable;

    /**
     * @param string $modelClass Fully-qualified class of the model being translated.
     * @param int|string|null $modelId Primary key of the model being
     *        translated, or null if it couldn't be resolved (e.g. the
     *        model was deleted before the job's terminal failure).
     * @param TranslationFailedException $exception The exception that
     *        caused the job to permanently fail.
     */
    public function __construct(
        public string $modelClass,
        public int|string|null $modelId,
        public TranslationFailedException $exception,
    ) {
    }
}
