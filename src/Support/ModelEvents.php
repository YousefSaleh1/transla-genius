<?php

namespace CodingPartners\TranslaGenius\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * The model events a translation job fires once, after its writes
 * (TRANSLATION_FIRE_MODEL_EVENTS), and the marker that keeps the package's
 * own `updated` hook from treating them as an edit.
 *
 * @internal Support\ is package wiring; not covered by the backward compatibility promise.
 */
final class ModelEvents
{
    private static int $firing = 0;

    /** True while the package itself is firing a model's events. */
    public static function firing(): bool
    {
        return self::$firing > 0;
    }

    /**
     * Fire `updated` then `saved` on $model, as a save of $fields would:
     * observers, listeners and $dispatchesEvents all run, and getChanges()
     * lists the translated columns. Nothing is written: the translations
     * are already stored.
     *
     * @param  array<int, string>  $fields
     */
    public static function fireAfterTranslating(Model $model, array $fields): void
    {
        self::$firing++;

        try {
            Closure::bind(function () use ($fields) {
                $this->changes = array_intersect_key($this->getAttributes(), array_flip($fields));
                $this->fireModelEvent('updated', false);
                $this->fireModelEvent('saved', false);
            }, $model, Model::class)();
        } finally {
            self::$firing--;
        }
    }
}
