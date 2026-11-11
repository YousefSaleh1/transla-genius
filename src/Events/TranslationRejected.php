<?php

namespace CodingPartners\TranslaGenius\Events;

use CodingPartners\TranslaGenius\Models\TranslationReview;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** A reviewed translation was rejected; nothing was published. */
class TranslationRejected
{
    use Dispatchable;

    public function __construct(
        public TranslationReview $review,
        public ?Model $reviewer,
        public ?string $note,
    ) {}
}
