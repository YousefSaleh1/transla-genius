<?php

namespace CodingPartners\TranslaGenius\Traits;

use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Translatable\HasTranslations;

/**
 * Trait Translatable
 *
 * This trait provides functionality for translating fields in an Eloquent model.
 * It automatically dispatches translation jobs when a model is created or updated,
 * and provides a scope to filter fully translated models.
 *
 * @package CodingPartners\TranslaGenius\Traits
 */
trait Translatable
{
    use HasTranslations;

    /**
     * Boot the Translatable trait.
     *
     * This method sets up event listeners for the model's `created` and `updated` events.
     * When a model is created or updated, it dispatches a job to translate the specified fields.
     *
     * @return void
     */
    protected static function bootTranslatable()
    {
        static::created(function ($model) {
            $jobClass = static::translationJobClass();
            $jobClass::dispatch($model, $model->translatable);
        });

        static::updated(function ($model) {
            $translatableFields = $model->getTranslatableAttributes();
            if (!empty($translatableFields) && static::hasSourceLocaleChanged($model, $translatableFields)) {
                $jobClass = static::translationJobClass();
                $jobClass::dispatch($model, $translatableFields);
            }
        });
    }

    /**
     * Which job class to dispatch on create/update. Override this on your
     * model to use a custom job (e.g. one with additional side effects)
     * without forking this trait - the only requirement is that it accepts
     * the same constructor signature as TranslateFields and is dispatchable
     * (implements ShouldQueue, uses Dispatchable).
     *
     * Stays `protected` - overriding it is a model's own internal
     * customization, not something meant to be called from outside the
     * model. External code (e.g. Console\Commands\BackfillCommand) that
     * needs to know which job a model would dispatch should call
     * getTranslationJobClass() instead of widening this method's own
     * visibility, which would force every existing `protected` override
     * (including the one documented in the README) to change too.
     *
     * @return class-string<\Illuminate\Contracts\Queue\ShouldQueue>
     *
     * @see self::getTranslationJobClass() The public accessor for this.
     */
    protected static function translationJobClass(): string
    {
        return TranslateFields::class;
    }

    /**
     * Public accessor for translationJobClass() - lets external code
     * (e.g. BackfillCommand) resolve which job this model dispatches
     * on create/update, including a model's own override, without that
     * override point itself needing to be public.
     *
     * @return class-string<\Illuminate\Contracts\Queue\ShouldQueue>
     *
     * @see self::translationJobClass() The overridable extension point this exposes.
     */
    public static function getTranslationJobClass(): string
    {
        return static::translationJobClass();
    }

    /**
     * Whether any translatable field's value for the current locale actually
     * changed on this save. Guards the `updated` listener so a save that
     * only touches non-source-locale data (or non-translatable columns)
     * doesn't trigger a needless re-translation dispatch.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param array<int, string> $translatableFields
     * @return bool
     */
    protected static function hasSourceLocaleChanged($model, array $translatableFields): bool
    {
        $currentLocale = get_current_locale();

        foreach ($translatableFields as $field) {
            if ($model->isDirty($field)) {
                $originalJson = $model->getRawOriginal($field);
                $originalTranslations = is_string($originalJson) ? json_decode($originalJson, true) : [];
                $originalTranslations = is_array($originalTranslations) ? $originalTranslations : [];
                $originalValue = $originalTranslations[$currentLocale] ?? null;
                $newValue = $model->getTranslation($field, $currentLocale, false);

                if ($originalValue !== $newValue) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the names of the attributes that are translatable.
     * Helper method to ensure consistency.
     *
     * @return array
     */
    public function getTranslatableAttributes(): array
    {
        return property_exists($this, 'translatable') && is_array($this->translatable)
            ? $this->translatable
            : [];
    }

    /**
     * Scope to filter models that are fully translated in all target languages.
     *
     * Uses the query builder's `column->path` JSON syntax rather than raw SQL,
     * so it compiles to the correct JSON-extraction expression per driver
     * (MySQL, PostgreSQL, SQLite) instead of only ever working on MySQL.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     *
     * @see self::scopeNotFullyTranslated() The inverse.
     */
    public function scopeFullyTranslated(Builder $query)
    {
        if (empty($this->translatable) || !is_array($this->translatable)) {
            return $query;
        }

        $targetLanguages = get_supported_languages();

        foreach ($this->translatable as $field) {
            $query->where(function ($q) use ($field, $targetLanguages) {
                foreach ($targetLanguages as $language) {
                    $q->whereNotNull("{$field}->{$language}");
                }
            });
        }

        return $query;
    }

    /**
     * Scope to filter models that are missing at least one target
     * language, in at least one translatable field - the exact inverse of
     * scopeFullyTranslated(). Used by `php artisan translagenius:backfill`
     * to find records to (re-)dispatch TranslateFields for.
     *
     * The De Morgan's-dual of scopeFullyTranslated()'s query: that scope
     * ANDs together "every field has every language" (where/whereNotNull,
     * implicitly AND-joined); this one ORs together "some field is missing
     * some language" (orWhere/orWhereNull), wrapped in a single top-level
     * where() so the OR logic stays self-contained and composes safely
     * with any other constraints already on $query (rather than OR-ing
     * against them too). Same `column->path` JSON syntax as
     * scopeFullyTranslated(), so it's equally driver-agnostic.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     *
     * @see self::scopeFullyTranslated() The inverse.
     */
    public function scopeNotFullyTranslated(Builder $query)
    {
        if (empty($this->translatable) || !is_array($this->translatable)) {
            return $query;
        }

        $targetLanguages = get_supported_languages();
        $translatableFields = $this->translatable;

        return $query->where(function ($outer) use ($translatableFields, $targetLanguages) {
            foreach ($translatableFields as $field) {
                $outer->orWhere(function ($inner) use ($field, $targetLanguages) {
                    foreach ($targetLanguages as $language) {
                        $inner->orWhereNull("{$field}->{$language}");
                    }
                });
            }
        });
    }
}
