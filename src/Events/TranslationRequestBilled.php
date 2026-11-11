<?php

namespace CodingPartners\TranslaGenius\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A provider response that reported token usage (so it was billed) but whose
 * output didn't become a saved translation. Successful pairs are logged from
 * TranslationCompleted; this covers the rest, so the usage log accounts for
 * every billed request exactly once.
 *
 * Outcomes:
 *  - truncated: stopped at the output token limit (single or batch).
 *  - unusable: a reply nothing could be taken from (a batch with no valid
 *    locale, or an empty single reply).
 *  - failed: an error response that still reported usage.
 *  - discarded: a valid translation that wasn't saved because the source
 *    text changed again while it was being made.
 *
 * Internal plumbing for usage tracking (Listeners\LogTranslationUsage); also
 * a public event, so apps can watch wasted spend.
 */
class TranslationRequestBilled
{
    use Dispatchable;

    public const TRUNCATED = 'truncated';
    public const UNUSABLE = 'unusable';
    public const FAILED = 'failed';
    public const DISCARDED = 'discarded';

    /**
     * @param string $outcome One of the constants above.
     * @param array<int, string> $targetLocales Locale(s) the request was for.
     */
    public function __construct(
        public string $outcome,
        public ?string $provider,
        public ?string $requestId,
        public ?int $promptTokens,
        public ?int $completionTokens,
        public string $sourceLocale,
        public array $targetLocales,
        public ?string $field = null,
        public ?string $modelClass = null,
        public int|string|null $modelId = null,
    ) {
    }
}
