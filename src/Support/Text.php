<?php

namespace CodingPartners\TranslaGenius\Support;

/**
 * The package's one rule for whether a translation (or source text) is
 * there: blank means null, or a string that is empty after trim(). "0" is a
 * value. Anything else that isn't a string is not blank; callers that need
 * a string check that separately.
 *
 * @internal Support\ is package wiring; not covered by the backward compatibility promise.
 */
final class Text
{
    public static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
