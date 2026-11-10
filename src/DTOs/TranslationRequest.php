<?php

namespace CodingPartners\TranslaGenius\DTOs;

/**
 * One piece of text to translate, and the locale pair to translate it
 * between. Passed to TranslationProviderInterface::translate() - a provider
 * implementation never sees raw job/model state, only this.
 */
final readonly class TranslationRequest
{
    /**
     * @param string $text The source text to translate.
     * @param string $sourceLocale The locale $text is written in.
     * @param string $targetLocale The locale to translate $text into.
     */
    public function __construct(
        public string $text,
        public string $sourceLocale,
        public string $targetLocale,
    ) {
    }
}
