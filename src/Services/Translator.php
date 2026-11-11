<?php

namespace CodingPartners\TranslaGenius\Services;

use CodingPartners\TranslaGenius\Contracts\BatchTranslationProviderInterface;
use CodingPartners\TranslaGenius\Contracts\BatchTranslatorInterface;
use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationBatchRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationBatchResult;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;

/**
 * Default TranslatorInterface implementation: resolves to whichever
 * TranslationProviderInterface implementation is bound (see
 * translagenius.default / translagenius.providers.* and
 * TranslaGeniusServiceProvider) and delegates.
 *
 * TranslateFields and the TranslaGenius facade depend on TranslatorInterface,
 * not this class - swapping providers means rebinding
 * TranslationProviderInterface (or changing translagenius.default);
 * replacing translation-resolution itself entirely (caching, fallback
 * chaining, ...) means rebinding TranslatorInterface to a different
 * implementation - neither touches the job or the Translatable trait.
 *
 * @see TranslationProviderInterface
 */
class Translator implements BatchTranslatorInterface
{
    /**
     * $provider is the backend to delegate to. Without one, the bound
     * TranslationProviderInterface is resolved when a translation is first
     * requested, not when this object is built: a configuration error then
     * surfaces as a TranslationConfigurationException inside the job's
     * handle(), where it fails the job once, instead of while the queue is
     * resolving the job's dependencies.
     */
    public function __construct(protected ?TranslationProviderInterface $provider = null) {}

    /** The provider to delegate to. */
    protected function provider(): TranslationProviderInterface
    {
        return $this->provider ?? app(TranslationProviderInterface::class);
    }

    /**
     * Translate a piece of text by delegating to the bound
     * TranslationProviderInterface.
     *
     * @param  string  $text  The source text to translate.
     * @param  string  $sourceLocale  The locale $text is written in.
     * @param  string  $targetLocale  The locale to translate $text into.
     * @param  string|null  $field  Optional context: the attribute being translated.
     * @param  string|null  $model  Optional context: the owning model's class basename.
     *
     * @throws TranslationFailedException
     *                                    If the underlying provider cannot complete the translation.
     */
    public function translate(
        string $text,
        string $sourceLocale,
        string $targetLocale,
        ?string $field = null,
        ?string $model = null,
    ): TranslationResult {
        return $this->provider()->translate(new TranslationRequest($text, $sourceLocale, $targetLocale, $field, $model));
    }

    /**
     * True when the bound provider implements BatchTranslationProviderInterface
     * and has batching enabled. A single-call-only provider never batches:
     * TranslateFields then translates each locale with its own translate().
     */
    public function canBatch(): bool
    {
        $provider = $this->provider();

        return $provider instanceof BatchTranslationProviderInterface && $provider->supportsBatching();
    }

    /**
     * Translate one text into several locales in one provider request. When
     * the provider can't batch, nothing is requested and an empty result is
     * returned, so the caller translates every locale one at a time.
     *
     * @param  array<int, string>  $targetLocales
     *
     * @throws TranslationFailedException
     */
    public function translateBatch(
        string $text,
        string $sourceLocale,
        array $targetLocales,
        ?string $field = null,
        ?string $model = null,
    ): TranslationBatchResult {
        $provider = $this->provider();

        if (! $provider instanceof BatchTranslationProviderInterface || ! $provider->supportsBatching()) {
            return TranslationBatchResult::empty();
        }

        return $provider->translateBatch(
            new TranslationBatchRequest($text, $sourceLocale, array_values($targetLocales), $field, $model)
        );
    }
}
