<?php

namespace CodingPartners\TranslaGenius\Contracts;

use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;

/**
 * The contract for a job class returned by Translatable::translationJobClass().
 *
 * The package builds one TranslationJobPayload per dispatch (the model, the
 * fields, the source locale captured at dispatch, the re-translation plan and
 * options) and constructs the job with it. Extending Jobs\TranslateFields is
 * the easy way to satisfy this; implement the interface directly for a job
 * that does something else entirely. Use Illuminate\Bus\Queueable so the
 * queue settings (TRANSLATION_QUEUE_CONNECTION / TRANSLATION_QUEUE / after
 * commit) apply to it.
 */
interface TranslationJob
{
    public function __construct(TranslationJobPayload $payload);
}
