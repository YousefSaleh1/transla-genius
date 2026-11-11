<?php

namespace CodingPartners\TranslaGenius\Events;

use CodingPartners\TranslaGenius\Models\TranslationReview;
use Illuminate\Foundation\Events\Dispatchable;

/** A translation was produced and is waiting for a human decision. */
class TranslationPendingReview
{
    use Dispatchable;

    public function __construct(public TranslationReview $review)
    {
    }
}
