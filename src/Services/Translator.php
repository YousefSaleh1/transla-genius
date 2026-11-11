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
    /**
     * @param TranslationProviderInterface $provider The backend to delegate
     *        translate() calls to - whichever implementation is bound (see
     *        translaGenius.default / translaGenius.providers.*).
     */
    public function __construct(protected TranslationProviderInterface $provider)
    {
    }

    /**
     * Translate a piece of text by delegating to the bound
     * TranslationProviderInterface.
     *
     * @param string $text The source text to translate.
     * @param string $sourceLocale The locale $text is written in.
     * @param string $targetLocale The locale to translate $text into.
     * @param string|null $field Optional context: the attribute being translated.
     * @param string|null $model Optional context: the owning model's class basename.
     * @return TranslationResult
     *
     * @throws \CodingPartners\TranslaGenius\Exceptions\TranslationFailedException
     *         If the underlying provider cannot complete the translation.
     */
    public function translate(
        string $text,
        string $sourceLocale,
        string $targetLocale,
        ?string $field = null,
        ?string $model = null,
    ): TranslationResult {
        return $this->provider->translate(new TranslationRequest($text, $sourceLocale, $targetLocale, $field, $model));
    }
}
