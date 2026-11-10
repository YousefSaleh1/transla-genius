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
     * @return class-string<\Illuminate\Contracts\Queue\ShouldQueue>
     */
    protected static function translationJobClass(): string
    {
        return TranslateFields::class;
    }

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
}
