<?php

namespace CodingPartners\TranslaGenius\Contracts;

use CodingPartners\TranslaGenius\DTOs\TranslationBatchResult;

/**
 * Optional capability of a TranslatorInterface: hand several target locales
 * of one text to the provider in a single request. TranslateFields batches
 * only through a translator implementing this; any other TranslatorInterface
 * is called once per locale, as before.
 */
interface BatchTranslatorInterface extends TranslatorInterface
{
    /**
     * Whether translateBatch() will actually batch (the bound provider can,
     * and batching is enabled for it).
     *
     * @return bool
     */
    public function canBatch(): bool;

    /**
     * @param string $text
     * @param string $sourceLocale
     * @param array<int, string> $targetLocales
     * @param string|null $field
     * @param string|null $model
     * @return TranslationBatchResult Only the locales that came back valid.
     *
     * @throws \CodingPartners\TranslaGenius\Exceptions\TranslationFailedException
     */
    public function translateBatch(
        string $text,
        string $sourceLocale,
        array $targetLocales,
        ?string $field = null,
        ?string $model = null,
    ): TranslationBatchResult;
}
