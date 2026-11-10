<?php

namespace CodingPartners\TranslaGenius\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by TranslateFields immediately before it asks the bound
 * TranslatorInterface to translate one field into one target locale.
 *
 * Dispatched from the automatic model-save lifecycle only (see
 * TranslateFields::prepareTranslations()) - not from direct
 * TranslatorInterface/facade use outside that job, since that context has
 * no model/field to report. A consuming app can use this (paired with
 * TranslationCompleted or the job's own failure handling) to build a
 * per-field translation audit trail or progress indicator.
 *
 * @see \CodingPartners\TranslaGenius\Jobs\TranslateFields
 * @see \CodingPartners\TranslaGenius\Events\TranslationCompleted
 */
final readonly class TranslationStarted
{
    use Dispatchable;

    /**
     * @param string $modelClass Fully-qualified class of the model being translated.
     * @param int|string $modelId Primary key of the model being translated.
     * @param string $field The translatable attribute name (e.g. "name").
     * @param string $sourceLocale The locale $text is written in.
     * @param string $targetLocale The locale $text is being translated into.
     * @param string $text The source text being sent for translation.
     */
    public function __construct(
        public string $modelClass,
        public int|string $modelId,
        public string $field,
        public string $sourceLocale,
        public string $targetLocale,
        public string $text,
    ) {
    }
}
