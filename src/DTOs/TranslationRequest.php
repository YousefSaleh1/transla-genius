<?php

namespace CodingPartners\TranslaGenius\DTOs;

/**
 * One piece of text to translate, and the locale pair to translate it
 * between. Passed to TranslationProviderInterface::translate() - a provider
 * implementation never sees raw job/model state, only this.
 */
final readonly class TranslationRequest
{
    public function __construct(
        public string $text,
        public string $sourceLocale,
        public string $targetLocale,
    ) {
    }
}
