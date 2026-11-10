<?php

namespace CodingPartners\TranslaGenius\Tests\Support;

use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;

/**
 * A hand-written TranslatorInterface implementation for tests - not a
 * mock, not the concrete Translator class. Proves the seam Phase 4a Task 1
 * extracted is real: anything implementing TranslatorInterface can be
 * bound in and drive the whole automatic flow, not just Translator itself.
 */
class FakeTranslator implements TranslatorInterface
{
    public array $calls = [];

    public function __construct(protected string $prefix = 'TRANSLATOR-FAKE:')
    {
    }

    public function translate(string $text, string $sourceLocale, string $targetLocale): TranslationResult
    {
        $this->calls[] = [$text, $sourceLocale, $targetLocale];

        return new TranslationResult("{$this->prefix}{$text}:{$targetLocale}");
    }
}
