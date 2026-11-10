<?php

namespace CodingPartners\TranslaGenius\Services;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;

/**
 * Default TranslatorInterface implementation: resolves to whichever
 * TranslationProviderInterface implementation is bound (see
 * translaGenius.default / translaGenius.providers.* and
 * TranslaGeniusServiceProvider) and delegates.
 *
 * TranslateFields and the TranslaGenius facade depend on TranslatorInterface,
 * not this class - swapping providers means rebinding
 * TranslationProviderInterface (or changing translaGenius.default);
 * replacing translation-resolution itself entirely (caching, fallback
 * chaining, ...) means rebinding TranslatorInterface to a different
 * implementation - neither touches the job or the Translatable trait.
 *
 * @see \CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface
 */
class Translator implements TranslatorInterface
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
