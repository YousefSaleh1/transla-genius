<?php

namespace CodingPartners\TranslaGenius\DTOs;

/**
 * The outcome of a successful TranslationProviderInterface::translate() call.
 * Only `text` is guaranteed - the token-usage fields are provider metadata
 * that not every provider will report (a non-LLM provider like DeepL has no
 * concept of "tokens"), so they're nullable rather than forcing every
 * provider to fabricate a value.
 */
final readonly class TranslationResult
{
    public function __construct(
        public string $text,
        public ?string $provider = null,
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
    ) {
    }
}
