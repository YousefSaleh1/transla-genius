<?php

namespace CodingPartners\TranslaGenius\Exceptions;

/**
 * The package's configuration can't work: an unknown provider name (a
 * TRANSLATION_PROVIDER typo), a missing API key or URL, a fallback chain
 * that is missing, too short or loops, or a driver class that doesn't exist
 * or isn't a provider.
 *
 * Never retryable: the next attempt would read the same config. A
 * translation job fails on its first attempt and fires
 * Events\TranslationFailed once. It is a TranslationFailedException, so code
 * that catches translation failures catches it too.
 */
class TranslationConfigurationException extends TranslationFailedException
{
    public function __construct(string $message)
    {
        parent::__construct($message, retryable: false);
    }
}
