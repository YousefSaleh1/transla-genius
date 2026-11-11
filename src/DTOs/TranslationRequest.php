<?php

namespace CodingPartners\TranslaGenius\DTOs;

/**
 * One piece of text to translate, and the locale pair to translate it
 * between. Passed to TranslationProviderInterface::translate() - a provider
 * implementation never sees raw job/model state, only this.
 *
 * `field` and `model` are optional, descriptive context (e.g. "name" on a
 * "Product") that a provider may use to pick better wording - the
 * OpenAI-compatible provider offers them as prompt placeholders. Providers
 * that don't need them ignore them. Neither ever contains field content.
 */
final readonly class TranslationRequest
{
    /**
     * @param string $text The source text to translate.
     * @param string $sourceLocale The locale $text is written in.
     * @param string $targetLocale The locale to translate $text into.
     * @param string|null $field The attribute being translated (e.g. "name"), if known.
     * @param string|null $model The owning model's class basename (e.g. "Product"), if known.
     */
    public function __construct(
        public string $text,
        public string $sourceLocale,
        public string $targetLocale,
        public ?string $field = null,
        public ?string $model = null,
    ) {
    }
}
