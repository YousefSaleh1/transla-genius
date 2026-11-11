<?php

namespace CodingPartners\TranslaGenius\Facades;

use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use Illuminate\Support\Facades\Facade;

/**
 * Convenience facade over TranslatorInterface, for translating arbitrary
 * text outside the automatic model-save flow (the Translatable trait +
 * TranslateFields job cover that already and don't need this facade).
 *
 * @method static \CodingPartners\TranslaGenius\DTOs\TranslationResult translate(string $text, string $sourceLocale, string $targetLocale, ?string $field = null, ?string $model = null)
 *
 * @see \CodingPartners\TranslaGenius\Contracts\TranslatorInterface
 */
class TranslaGenius extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TranslatorInterface::class;
    }
}
