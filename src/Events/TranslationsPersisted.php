<?php

namespace CodingPartners\TranslaGenius\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once per translation job, after its last write, when it saved at
 * least one translation onto the record.
 *
 * The job writes each field/locale pair as soon as it's translated, without
 * model events, so observers aren't called once per pair (a job of 3
 * fields x 8 languages would re-index a search engine 24 times). This is
 * the one notification instead: re-index, bust a cache or audit here.
 *
 *     Event::listen(TranslationsPersisted::class, fn ($event) => $event->model->searchable());
 *
 * Set TRANSLATION_FIRE_MODEL_EVENTS=true to also have the model's own
 * `updated` and `saved` events fire once, for observers you can't change.
 */
final class TranslationsPersisted
{
    use Dispatchable;

    /**
     * @param  Model  $model  The record, read again after the last write.
     * @param  array<int, array{field: string, locale: string}>  $pairs  What this job wrote, in order.
     */
    public function __construct(
        public readonly Model $model,
        public readonly array $pairs,
    ) {}
}
