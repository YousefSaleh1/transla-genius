<?php

namespace CodingPartners\TranslaGenius\DTOs;

use InvalidArgumentException;

/**
 * One piece of text to translate into several target locales in a single
 * provider request. See BatchTranslationProviderInterface.
 *
 * `field` and `model` are the same optional, descriptive context as on
 * TranslationRequest; neither ever contains field content.
 */
final readonly class TranslationBatchRequest
{
    /**
     * @param  string  $text  The source text to translate.
     * @param  string  $sourceLocale  The locale $text is written in.
     * @param  array<int, string>  $targetLocales  Locales to translate into (at least one).
     * @param  string|null  $field  The attribute being translated, if known.
     * @param  string|null  $model  The owning model's class basename, if known.
     *
     * @throws InvalidArgumentException If $targetLocales is empty.
     */
    public function __construct(
        public string $text,
        public string $sourceLocale,
        public array $targetLocales,
        public ?string $field = null,
        public ?string $model = null,
    ) {
        if ($targetLocales === []) {
            throw new InvalidArgumentException('TranslationBatchRequest needs at least one target locale.');
        }
    }
}
