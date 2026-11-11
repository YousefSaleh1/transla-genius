<?php

namespace CodingPartners\TranslaGenius\Exceptions;

use CodingPartners\TranslaGenius\Models\TranslationReview;
use RuntimeException;

/** A decision was requested for a review that is no longer pending. */
class InvalidReviewStateException extends RuntimeException
{
    public function __construct(public readonly TranslationReview $review)
    {
        parent::__construct("Translation review #{$review->id} is {$review->status}, not pending.");
    }
}
