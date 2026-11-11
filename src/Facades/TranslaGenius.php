<?php

namespace CodingPartners\TranslaGenius\Facades;

use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\Review\ReviewManager;
use Illuminate\Support\Facades\Facade;

/**
 * Convenience facade over TranslatorInterface, for translating arbitrary
 * text outside the automatic model-save flow (the Translatable trait +
 * TranslateFields job cover that already and don't need this facade).
 *
 * @method static \CodingPartners\TranslaGenius\DTOs\TranslationResult translate(string $text, string $sourceLocale, string $targetLocale, ?string $field = null, ?string $model = null)
 * @method static \CodingPartners\TranslaGenius\Review\ReviewManager reviews()
 *
 * @see TranslatorInterface
 */
class TranslaGenius extends Facade
{
    /**
     * The review/approval workflow's primitives (pending(), approve(),
     * reject(), retranslate(), approveAll(), prune()).
     */
    public static function reviews(): ReviewManager
    {
        return app(ReviewManager::class);
    }

    protected static function getFacadeAccessor(): string
    {
        return TranslatorInterface::class;
    }
}
