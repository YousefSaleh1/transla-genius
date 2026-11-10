<?php

namespace CodingPartners\TranslaGenius\Events;

use CodingPartners\TranslaGenius\DTOs\TranslationResult;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by TranslateFields immediately after TranslatorInterface returns a
 * successful TranslationResult for one field/locale, before that result is
 * persisted (persistence happens later, in one batched TranslationWriter
 * call - see TranslateFields::handle()).
 *
 * Dispatched from the automatic model-save lifecycle only - see
 * TranslationStarted for why direct TranslatorInterface/facade use outside
 * the job isn't covered. Carries the full TranslationResult, including its
 * token-count fields, so this is the intended hook for a usage-tracking
 * listener (Phase 3's ranked-feature list) without that listener needing to
 * touch TranslateFields or TranslationWriter itself.
 *
 * @see \CodingPartners\TranslaGenius\Jobs\TranslateFields
 * @see \CodingPartners\TranslaGenius\DTOs\TranslationResult
 */
final readonly class TranslationCompleted
{
    use Dispatchable;

    /**
     * @param string $modelClass Fully-qualified class of the model being translated.
     * @param int|string $modelId Primary key of the model being translated.
     * @param string $field The translatable attribute name (e.g. "name").
     * @param string $sourceLocale The locale the source text was written in.
     * @param string $targetLocale The locale the text was translated into.
     * @param TranslationResult $result The provider's result, including any
     *        token-usage metadata it reported.
     */
    public function __construct(
        public string $modelClass,
        public int|string $modelId,
        public string $field,
        public string $sourceLocale,
        public string $targetLocale,
        public TranslationResult $result,
    ) {
    }
}
