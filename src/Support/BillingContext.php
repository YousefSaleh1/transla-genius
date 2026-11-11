<?php

namespace CodingPartners\TranslaGenius\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * The record a translation job is working on, for billed-request events a
 * provider dispatches itself (a truncated, unusable or failed reply): a
 * provider knows the field and locales, not the model.
 *
 * Set by TranslateFields around its translation calls; null outside a job
 * (the facade, direct TranslatorInterface use).
 *
 * @internal Support\ is package wiring; not covered by the backward compatibility promise.
 */
final class BillingContext
{
    private static ?Model $model = null;

    /**
     * Run $callback with $model as the record being translated.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function for(Model $model, Closure $callback): mixed
    {
        $previous = self::$model;
        self::$model = $model;

        try {
            return $callback();
        } finally {
            self::$model = $previous;
        }
    }

    /** @return class-string<Model>|null */
    public static function modelClass(): ?string
    {
        return self::$model === null ? null : get_class(self::$model);
    }

    public static function modelId(): int|string|null
    {
        $key = self::$model?->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }
}
