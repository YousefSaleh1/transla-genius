<?php

namespace CodingPartners\TranslaGenius\Support;

/**
 * Numeric config values, which usually come from .env as strings. One rule
 * for all of them: an empty or non-numeric value (`TRANSLATION_API_TIMEOUT=`,
 * `TRANSLATION_JOB_TRIES=three`) means "not set", so the default applies.
 * Casting it instead would turn it into 0, which for a timeout means no
 * timeout at all and for a cache TTL means forever.
 *
 * @internal Support\ is package wiring; not covered by the backward compatibility promise.
 */
final class Setting
{
    /** An int setting with a default, never below $min. */
    public static function int(mixed $value, int $default, int $min = 0): int
    {
        $number = self::number($value);

        return $number === null ? $default : max($min, (int) $number);
    }

    /** An optional int setting: null when not set. */
    public static function nullableInt(mixed $value, int $min = 0): ?int
    {
        $number = self::number($value);

        return $number === null ? null : max($min, (int) $number);
    }

    /** An optional float setting: null when not set. */
    public static function nullableFloat(mixed $value): ?float
    {
        $number = self::number($value);

        return $number === null ? null : (float) $number;
    }

    /**
     * A list of ints, from an array or a comma-separated string. Entries that
     * aren't numbers are dropped; an empty result gives $default.
     *
     * @param  array<int, int>  $default
     * @return array<int, int>
     */
    public static function intList(mixed $value, array $default, int $min = 0): array
    {
        $items = is_array($value) ? $value : explode(',', is_scalar($value) ? (string) $value : '');

        $ints = [];
        foreach ($items as $item) {
            $number = self::number($item);
            if ($number !== null) {
                $ints[] = max($min, (int) $number);
            }
        }

        return $ints === [] ? $default : $ints;
    }

    /** The value as an int or float, or null when it isn't a number. */
    private static function number(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return trim($value) + 0;
        }

        return null;
    }
}
