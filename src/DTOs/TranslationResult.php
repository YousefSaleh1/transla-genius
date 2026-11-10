<?php

namespace CodingPartners\TranslaGenius\DTOs;

use InvalidArgumentException;

/**
 * The outcome of a successful TranslationProviderInterface::translate() call.
 * Only `text` is guaranteed - the token-usage fields are provider metadata
 * that not every provider will report (a non-LLM provider like DeepL has no
 * concept of "tokens"), so they're nullable rather than forcing every
 * provider to fabricate a value.
 *
 * `text` being non-empty is enforced here, in the constructor, rather than
 * left as a documented-but-unenforced expectation each provider is trusted
 * to uphold on its own. A provider that has nothing to return has a
 * TranslationFailedException to throw instead (see
 * TranslationProviderInterface); constructing this DTO at all is itself
 * the guarantee "there is a real result." This closes a real LSP gap: the
 * interface's return type alone didn't previously rule out a
 * well-formed-but-empty result silently passing through
 * TranslateFields::prepareTranslations()'s empty() check as "still
 * missing" instead of surfacing as the contract violation it actually is.
 */
final readonly class TranslationResult
{
    /**
     * @param string $text The translated text. Must be non-empty.
     * @param string|null $provider Identifier of the provider that produced
     *        this result (e.g. "openrouter"), if the provider reports one.
     * @param int|null $promptTokens Prompt token usage, if the provider
     *        reports token-level usage metadata.
     * @param int|null $completionTokens Completion token usage, if the
     *        provider reports token-level usage metadata.
     *
     * @throws \InvalidArgumentException If $text is empty or all whitespace.
     */
    public function __construct(
        public string $text,
        public ?string $provider = null,
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
    ) {
        if (trim($text) === '') {
            throw new InvalidArgumentException(
                'TranslationResult::$text must not be empty - throw TranslationFailedException instead of constructing an empty result.'
            );
        }
    }
}
