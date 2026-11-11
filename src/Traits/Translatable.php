<?php

namespace CodingPartners\TranslaGenius\Traits;

use CodingPartners\TranslaGenius\Support\TranslationJobDispatcher;
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
            TranslationJobDispatcher::dispatch(static::translationJobClass(), $model, $model->translatable);
        });

        static::updated(function ($model) {
            $translatableFields = $model->getTranslatableAttributes();
            $retranslate = empty($translatableFields) ? [] : static::staleTranslationPlan($model, $translatableFields);

            if ($retranslate !== []) {
                TranslationJobDispatcher::dispatch(static::translationJobClass(), $model, $translatableFields, $retranslate);
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
    /**
     * For each translatable field whose current-locale (source) text changed
     * in this save, what the job must re-translate: the source locale, the
     * new source text as a snapshot, and the target locales this same save
     * changed itself (`keep`). Those were provided by the app or user along
     * with the new source, so they are kept; every other target locale is now
     * stale and gets re-translated and overwritten. Fields whose source didn't
     * change aren't in the plan.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param array<int, string> $translatableFields
     * @return array<string, array{locale: string, text: string, keep: array<int, string>}>
     */
    protected static function staleTranslationPlan($model, array $translatableFields): array
    {
        $sourceLocale = TranslationJobDispatcher::sourceLocale();
        $plan = [];

        foreach ($translatableFields as $field) {
            if (!$model->isDirty($field)) {
                continue;
            }

            $originalJson = $model->getRawOriginal($field);
            $original = is_string($originalJson) ? json_decode($originalJson, true) : [];
            $original = is_array($original) ? $original : [];
            $current = $model->getTranslations($field);
            $newSource = $current[$sourceLocale] ?? null;

            if (!is_string($newSource) || $newSource === '' || ($original[$sourceLocale] ?? null) === $newSource) {
                continue;
            }

            $keep = [];
            foreach (array_unique(array_merge(array_keys($original), array_keys($current))) as $locale) {
                if ($locale !== $sourceLocale && ($original[$locale] ?? null) !== ($current[$locale] ?? null)) {
                    $keep[] = $locale;
                }
            }

            $plan[$field] = ['locale' => $sourceLocale, 'text' => $newSource, 'keep' => $keep];
        }

        return $plan;
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
     * @param string|null $sourceLocale Locale content is authored in; its targets
     *        are every other supported locale. Null = the current locale.
     * @return \Illuminate\Database\Eloquent\Builder
     *
     * @see self::scopeNotFullyTranslated() The inverse.
     */
    public function scopeFullyTranslated(Builder $query, ?string $sourceLocale = null)
    {
        if (empty($this->translatable) || !is_array($this->translatable)) {
            return $query;
        }

        $targetLanguages = get_supported_languages($sourceLocale);

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
     * @param string|null $sourceLocale Locale content is authored in; its targets
     *        are every other supported locale. Null = the current locale.
     * @return \Illuminate\Database\Eloquent\Builder
     *
     * @see self::scopeFullyTranslated() The inverse.
     */
    public function scopeNotFullyTranslated(Builder $query, ?string $sourceLocale = null)
    {
        if (empty($this->translatable) || !is_array($this->translatable)) {
            return $query;
        }

        $targetLanguages = get_supported_languages($sourceLocale);
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
