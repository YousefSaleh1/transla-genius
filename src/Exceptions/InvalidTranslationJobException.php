<?php

namespace CodingPartners\TranslaGenius\Exceptions;

use CodingPartners\TranslaGenius\Contracts\TranslationJob;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use InvalidArgumentException;

/** A translationJobClass() that doesn't implement the TranslationJob contract. */
class InvalidTranslationJobException extends InvalidArgumentException
{
    public function __construct(public readonly string $jobClass)
    {
        parent::__construct(sprintf(
            'Translation job class [%s] must implement %s (a constructor taking a TranslationJobPayload) - for example by extending %s.',
            $jobClass,
            TranslationJob::class,
            TranslateFields::class
        ));
    }
}
