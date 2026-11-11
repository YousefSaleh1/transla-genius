<?php

namespace CodingPartners\TranslaGenius\Support;

use CodingPartners\TranslaGenius\Contracts\HasTranslatableAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Translatable values as stored, never as the app reads them.
 *
 * Spatie's getTranslation() runs the model's accessor (a trim, a
 * strip_tags) and its missing-key callback, so it can return something
 * other than what's in the column. Every snapshot and comparison the
 * package makes (the job's plan, the writer's checks under the lock, the
 * review rules, backfill) reads through here, so they all compare the same
 * thing: the stored value. Otherwise a translation of "Blue shirt\n" would
 * be compared with "Blue shirt" and thrown away, on every attempt.
 *
 * @internal Support\ is package wiring; not covered by the backward compatibility promise.
 */
final class StoredTranslations
{
    /**
     * Every locale's stored value of $field (getTranslations() is raw).
     *
     * @param  Model&HasTranslatableAttributes  $model
     * @return array<string, mixed>
     */
    public static function all(Model $model, string $field): array
    {
        return $model->getTranslations($field);
    }

    /**
     * One locale's stored value, or null when there is none.
     *
     * @param  Model&HasTranslatableAttributes  $model
     */
    public static function get(Model $model, string $field, string $locale): ?string
    {
        $value = self::all($model, $field)[$locale] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
