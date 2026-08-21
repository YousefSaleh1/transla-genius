<?php

namespace CodingPartners\TranslaGenius\Contracts;

use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;

/**
 * The extension point for swapping translation backends (OpenRouter, a
 * different LLM, DeepL, Google Translate, ...) without touching
 * TranslateFields, the Translatable trait, or the Translator that resolves
 * whichever provider is configured.
 *
 * Deliberately shaped around plain text + locale codes in, translated text
 * out - not around any one provider's request/response format (no "chat
 * messages", no "model name" in the contract itself). A provider that isn't
 * an LLM at all (DeepL, Google Translate) implements this exactly as easily
 * as one that is.
 *
 * Register a custom implementation via config - see translaGenius.providers
 * in config/translaGenius.php - no code changes to this package required.
 */
interface TranslationProviderInterface
{
    /**
     * Translate the given request, or throw
     * CodingPartners\TranslaGenius\Exceptions\TranslationFailedException on
     * failure (setting `retryable` appropriately for the failure mode).
     *
     * @param TranslationRequest $request
     * @return TranslationResult
     */
    public function translate(TranslationRequest $request): TranslationResult;
}
