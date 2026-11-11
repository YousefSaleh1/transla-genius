<?php

namespace CodingPartners\TranslaGenius\Exceptions;

use CodingPartners\TranslaGenius\Models\TranslationReview;

/** A decision was requested for a review that is no longer pending. */
class InvalidReviewStateException extends TranslaGeniusException
{
    public function __construct(public readonly TranslationReview $review)
    {
        parent::__construct("Translation review #{$review->id} is {$review->status}, not pending.");
    }
}
