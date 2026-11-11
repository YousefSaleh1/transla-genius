<?php

namespace CodingPartners\TranslaGenius\Review;

use Illuminate\Database\Eloquent\Model;

/**
 * Reads translaGenius.review: whether review is on, which model / field /
 * locale it applies to, what stays visible while a translation is pending,
 * and what a rejection does. Nothing here touches the database.
 */
class ReviewSettings
{
    public const KEEP = 'keep';
    public const CLEAR = 'clear';
    public const LEAVE = 'leave';
    public const RETRANSLATE = 'retranslate';

    public static function enabled(): bool
    {
        return (bool) (filter_var(config('translaGenius.review.enabled', false), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false);
    }

    /**
     * Whether a translation of $field into $locale on $model goes through
     * review. With `models` and `fields` both empty, every field does
     * (`locales` still narrows it). A configured class also covers its
     * subclasses.
     */
    public static function appliesTo(Model $model, string $field, string $locale): bool
    {
        if (!self::enabled()) {
            return false;
        }

        $locales = self::list(config('translaGenius.review.locales', []));
        if ($locales !== [] && !in_array($locale, $locales, true)) {
            return false;
        }

        $models = (array) config('translaGenius.review.models', []);
        $fields = self::list(config('translaGenius.review.fields', []));

        if ($models === [] && $fields === []) {
            return true;
        }

        if (in_array($field, $fields, true)) {
            return true;
        }

        foreach ($models as $class => $modelFields) {
            if (is_string($class) && $model instanceof $class) {
                $modelFields = self::list($modelFields);

                if (in_array('*', $modelFields, true) || in_array($field, $modelFields, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** 'keep' (default) or 'clear', for one model/field. */
    public static function whilePending(Model $model, string $field): string
    {
        foreach ((array) config('translaGenius.review.while_pending_overrides', []) as $class => $override) {
            if (!is_string($class) || !$model instanceof $class) {
                continue;
            }

            if (is_string($override)) {
                return self::visibility($override);
            }

            if (is_array($override) && isset($override[$field])) {
                return self::visibility($override[$field]);
            }
        }

        return self::visibility(config('translaGenius.review.while_pending', self::KEEP));
    }

    /** 'leave' (default) or 'retranslate'. */
    public static function onReject(): string
    {
        return strtolower(trim((string) config('translaGenius.review.on_reject', self::LEAVE))) === self::RETRANSLATE
            ? self::RETRANSLATE
            : self::LEAVE;
    }

    protected static function visibility(mixed $value): string
    {
        return strtolower(trim((string) $value)) === self::CLEAR ? self::CLEAR : self::KEEP;
    }

    /** A config list that may come from .env as "a,b" or from config as an array. */
    protected static function list(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(fn ($item) => trim((string) $item), $items), fn ($item) => $item !== ''));
    }
}
