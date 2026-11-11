<?php

namespace CodingPartners\TranslaGenius\Exceptions;

/**
 * A translation job kept finding its record busy with another translation
 * job (Support\OneJobPerRecord) and was re-queued the maximum number of
 * times (translagenius.queue.max_requeues / TRANSLATION_JOB_MAX_REQUEUES).
 * Usually a worker that hung while holding the record's lock. Not
 * retryable; backfill can request the record again later.
 */
class RecordBusyException extends TranslationFailedException
{
    public function __construct(string $modelClass, int|string|null $modelKey, int $requeues)
    {
        parent::__construct(
            "The record {$modelClass} #{$modelKey} stayed busy with another translation job through {$requeues} re-queues; giving up on this job.",
            retryable: false,
        );
    }
}
