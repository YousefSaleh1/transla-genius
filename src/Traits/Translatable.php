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
        static::created(fn($model) => TranslateFields::dispatch($model, $model->translatable));

        static::updated(function ($model) {
            $translatableFields = $model->getTranslatableAttributes();
            if (!empty($translatableFields) && static::hasSourceLocaleChanged($model, $translatableFields)) {
                TranslateFields::dispatch($model, $translatableFields);
            }
        });
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
                    $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`$field`, '$.$language')) IS NOT NULL");
                }
            });
        }

        return $query;
    }
}
