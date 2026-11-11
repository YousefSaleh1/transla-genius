<?php

namespace CodingPartners\TranslaGenius\Contracts;

use CodingPartners\TranslaGenius\DTOs\TranslationResult;

/**
 * The seam between "something that needs a piece of text translated"
 * (TranslateFields, the TranslaGenius facade) and however that actually
 * gets resolved to a provider. TranslationProviderInterface made the
 * *backend* swappable; this does the same one layer up, so a consumer can
 * decorate or replace translation-resolution itself (caching, fallback
 * chaining, per-tenant provider selection, ...) without the job or the
 * Translatable trait depending on the concrete Translator class.
 */
interface TranslatorInterface
{
    /**
     * Translate a piece of text from one locale to another.
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
    ): TranslationResult;
}
