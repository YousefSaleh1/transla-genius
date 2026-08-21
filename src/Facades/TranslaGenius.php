<?php

namespace CodingPartners\TranslaGenius\Facades;

use CodingPartners\TranslaGenius\Services\Translator;
use Illuminate\Support\Facades\Facade;

/**
 * Convenience facade over Translator, for translating arbitrary text
 * outside the automatic model-save flow (the Translatable trait +
 * TranslateFields job cover that already and don't need this facade).
 *
 * @method static \CodingPartners\TranslaGenius\DTOs\TranslationResult translate(string $text, string $sourceLocale, string $targetLocale)
 *
 * @see \CodingPartners\TranslaGenius\Services\Translator
 */
class TranslaGenius extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Translator::class;
    }
}
