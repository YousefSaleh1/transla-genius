<?php

namespace CodingPartners\TranslaGenius\DTOs;

/**
 * What one batch request produced: a translation for each locale that came
 * back valid. Locales missing from `translations` were missing or invalid in
 * the reply (or the reply wasn't usable at all) and are translated one at a
 * time instead.
 *
 * Token counts cover the whole request, however many locales it yielded -
 * see resultFor() for how they are attributed to exactly one pair.
 */
final readonly class TranslationBatchResult
{
    /**
     * @param array<string, string> $translations Valid locale => non-empty text.
     * @param string|null $provider Config key of the provider that served it.
     * @param int|null $promptTokens Prompt tokens for the whole request.
     * @param int|null $completionTokens Completion tokens for the whole request.
     * @param string|null $requestId Identifies the request.
     * @param bool $truncated The reply stopped at the output token limit, so
     *        nothing was taken from it. The caller translates every locale one
     *        at a time instead (smaller replies), or a fallback chain tries
     *        its next member. Token counts are still what the request billed.
     * @param array<int, string> $cachedLocales Locales served from the cache
     *        (no request; the token counts never cover them).
     */
    public function __construct(
        public array $translations,
        public ?string $provider = null,
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
        public ?string $requestId = null,
        public bool $truncated = false,
        public array $cachedLocales = [],
    ) {
    }

    /** Whether $locale's translation came from the cache rather than a request. */
    public function isCached(string $locale): bool
    {
        return in_array($locale, $this->cachedLocales, true);
    }

    /** A batch nothing could be taken from (e.g. no member could batch). */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * The TranslationResult for one locale. The request's token counts are
     * carried by exactly one pair - pass $withTokens for it - and the other
     * pairs get null, so summing usage across pairs equals what was billed.
     *
     * @param string $locale A key of $translations.
     * @param bool $withTokens Whether this pair carries the request's tokens.
     * @return TranslationResult
     */
    public function resultFor(string $locale, bool $withTokens): TranslationResult
    {
        $cached = $this->isCached($locale);

        return new TranslationResult(
            text: $this->translations[$locale],
            provider: $this->provider,
            promptTokens: $withTokens && !$cached ? $this->promptTokens : null,
            completionTokens: $withTokens && !$cached ? $this->completionTokens : null,
            requestId: $cached ? null : $this->requestId,
            cached: $cached,
        );
    }

    /** Whether the request reported any token usage. */
    public function hasTokens(): bool
    {
        return $this->promptTokens !== null || $this->completionTokens !== null;
    }
}
