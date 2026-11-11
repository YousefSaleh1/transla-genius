<?php

namespace CodingPartners\TranslaGenius\Events;

use CodingPartners\TranslaGenius\Models\TranslationReview;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** A reviewed translation was approved and published (possibly edited). */
class TranslationApproved
{
    use Dispatchable;

    public function __construct(
        public TranslationReview $review,
        public ?Model $reviewer,
        public bool $edited,
    ) {
    }
}
