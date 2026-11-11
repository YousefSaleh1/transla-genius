<?php

namespace CodingPartners\TranslaGenius\Exceptions;

use CodingPartners\TranslaGenius\Models\TranslationReview;
use RuntimeException;

/**
 * The source text changed since this translation was produced, so publishing
 * it would publish stale text. The review has been marked superseded; a newer
 * translation of the current source replaces it.
 */
class StaleReviewException extends RuntimeException
{
    public function __construct(public readonly TranslationReview $review)
    {
        parent::__construct(sprintf(
            'Translation review #%d is stale: the %s source text of %s #%s changed since it was produced. It has been marked superseded.',
            $review->id,
            $review->field,
            $review->translatable_type,
            $review->translatable_id
        ));
    }
}
