<?php

namespace CodingPartners\TranslaGenius\Events;

use CodingPartners\TranslaGenius\Models\TranslationReview;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A pending translation became obsolete before a decision: its source text
 * changed, or a newer translation of the same field/locale replaced it.
 */
class TranslationSuperseded
{
    use Dispatchable;

    public const SOURCE_CHANGED = 'source_changed';

    public const REPLACED = 'replaced';

    public function __construct(
        public TranslationReview $review,
        public string $reason,
    ) {}
}
