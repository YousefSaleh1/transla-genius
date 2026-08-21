<?php

namespace CodingPartners\TranslaGenius\Services;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;

/**
 * Thin entry point for translating text: resolves to whichever
 * TranslationProviderInterface implementation is bound (see
 * translaGenius.default / translaGenius.providers.* and
 * TranslaGeniusServiceProvider) and delegates.
 *
 * This is the class TranslateFields and the TranslaGenius facade depend on.
 * Swapping providers means rebinding TranslationProviderInterface (or
 * changing translaGenius.default) - never touching this class, the job, or
 * the Translatable trait.
 */
class Translator
{
    public function __construct(protected TranslationProviderInterface $provider)
    {
    }

    /**
     * @param string $text
     * @param string $sourceLocale
     * @param string $targetLocale
     * @return TranslationResult
     *
     * @throws \CodingPartners\TranslaGenius\Exceptions\TranslationFailedException
     */
    public function translate(string $text, string $sourceLocale, string $targetLocale): TranslationResult
    {
        return $this->provider->translate(new TranslationRequest($text, $sourceLocale, $targetLocale));
    }
}
