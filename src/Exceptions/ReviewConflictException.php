<?php

namespace CodingPartners\TranslaGenius\Exceptions;

use CodingPartners\TranslaGenius\Models\TranslationReview;
use RuntimeException;

/**
 * The published value of this field/locale was changed (by hand) after the
 * translation was produced. Approving would overwrite that edit, so nothing
 * was published and the review stays pending; approve with force: true to
 * publish anyway, or reject it.
 */
class ReviewConflictException extends RuntimeException
{
    public function __construct(public readonly TranslationReview $review, public readonly string $currentValue)
    {
        parent::__construct(sprintf(
            'Translation review #%d would overwrite a value of %s.%s that changed since it was produced. Nothing was published; approve with force: true to overwrite it.',
            $review->id,
            $review->field,
            $review->locale
        ));
    }
}
