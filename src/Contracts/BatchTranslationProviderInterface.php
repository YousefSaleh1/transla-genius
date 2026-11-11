<?php

namespace CodingPartners\TranslaGenius\Contracts;

use CodingPartners\TranslaGenius\DTOs\TranslationBatchRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationBatchResult;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;

/**
 * Optional capability: translate one text into several locales in a single
 * request. A provider that only implements TranslationProviderInterface is
 * never asked to batch - every locale is translated one call at a time.
 */
interface BatchTranslationProviderInterface extends TranslationProviderInterface
{
    /**
     * Whether this instance will batch right now (e.g. batching may be
     * switched off in its config). When false, translateBatch() isn't called.
     */
    public function supportsBatching(): bool;

    /**
     * Translate $request->text into every locale in $request->targetLocales.
     *
     * Returns only the locales that came back valid; the rest are translated
     * one at a time by the caller. A reply that can't be used at all returns
     * an empty set of translations (with its token counts, if any) rather than
     * throwing; so does a truncated reply, flagged `truncated` (one-at-a-time
     * requests have much smaller replies, so they're worth trying). Throws
     * TranslationFailedException for request-level failures (HTTP errors),
     * exactly like translate().
     *
     *
     * @throws TranslationFailedException
     */
    public function translateBatch(TranslationBatchRequest $request): TranslationBatchResult;
}
