<?php

namespace CodingPartners\TranslaGenius\Traits;

use CodingPartners\TranslaGenius\Contracts\HasTranslatableAttributes;
use CodingPartners\TranslaGenius\Contracts\TranslationJob;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Models\TranslationReview;
use CodingPartners\TranslaGenius\Support\KeyCast;
use CodingPartners\TranslaGenius\Support\Locales;
use CodingPartners\TranslaGenius\Support\Text;
use CodingPartners\TranslaGenius\Support\TranslationJobDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * Trait Translatable
 *
 * This trait provides functionality for translating fields in an Eloquent model.
 * It automatically dispatches translation jobs when a model is created or updated,
 * and provides a scope to filter fully translated models.
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
            $fields = $model->getTranslatableAttributes();

            if ($fields !== []) {
                TranslationJobDispatcher::dispatch(static::translationJobClass(), $model, $fields);
            }
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
     * @return class-string<TranslationJob>
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
     * @return class-string<TranslationJob>
     *
     * @see self::translationJobClass() The overridable extension point this exposes.
     */
    public static function getTranslationJobClass(): string
    {
        return static::translationJobClass();
    }

    /**
     * For each translatable field whose current-locale (source) text changed
     * in this save, what the job must re-translate: the source locale, the
     * new source text as a snapshot, and the target locales this same save
     * changed itself (`keep`). Those were provided by the app or user along
     * with the new source, so they are kept; every other target locale is now
     * stale and gets re-translated and overwritten. Fields whose source didn't
     * change aren't in the plan.
     *
     * Each remaining target's stored value at this moment is snapshotted too
     * (`targets`): it is overwritten only while it still holds that value.
     *
     * @param  Model&HasTranslatableAttributes  $model
     * @param  array<int, string>  $translatableFields
     * @return array<string, array{locale: string, text: string, keep: array<int, string>, targets: array<string, string>}>
     */
    protected static function staleTranslationPlan($model, array $translatableFields): array
    {
        $sourceLocale = TranslationJobDispatcher::sourceLocale();
        $plan = [];

        foreach ($translatableFields as $field) {
            if (! $model->isDirty($field)) {
                continue;
            }

            $originalJson = $model->getRawOriginal($field);
            $original = is_string($originalJson) ? json_decode($originalJson, true) : [];
            $original = is_array($original) ? $original : [];
            $current = $model->getTranslations($field);
            $newSource = $current[$sourceLocale] ?? null;

            if (! is_string($newSource) || Text::isBlank($newSource) || ($original[$sourceLocale] ?? null) === $newSource) {
                continue;
            }

            $keep = [];
            foreach (array_unique(array_merge(array_keys($original), array_keys($current))) as $locale) {
                if ($locale !== $sourceLocale && ($original[$locale] ?? null) !== ($current[$locale] ?? null)) {
                    $keep[] = $locale;
                }
            }

            // Each stale target's stored value, as of this save. The job only
            // overwrites a target that still holds exactly this value, so a
            // retry skips what it already replaced and a later hand edit wins.
            $targets = [];
            foreach (Locales::targets($sourceLocale) as $locale) {
                if (! in_array($locale, $keep, true)) {
                    $targets[$locale] = (string) ($current[$locale] ?? '');
                }
            }

            $plan[$field] = ['locale' => $sourceLocale, 'text' => $newSource, 'keep' => $keep, 'targets' => $targets];
        }

        return $plan;
    }

    /**
     * Whether any translatable field's value for the current locale actually
     * changed on this save. Guards the `updated` listener so a save that
     * only touches non-source-locale data (or non-translatable columns)
     * doesn't trigger a needless re-translation dispatch.
     *
     * @param  Model&HasTranslatableAttributes  $model
     * @param  array<int, string>  $translatableFields
     */
    protected static function hasSourceLocaleChanged($model, array $translatableFields): bool
    {
        $currentLocale = Locales::current();

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
     * Scope to filter models that are fully translated in all target languages.
     *
     * Uses the query builder's `column->path` JSON syntax rather than raw SQL,
     * so it compiles to the correct JSON-extraction expression per driver
     * (MySQL, PostgreSQL, SQLite) instead of only ever working on MySQL.
     *
     * @param  string|null  $sourceLocale  Locale content is authored in; its targets
     *                                     are every other supported locale. Null = the current locale.
     * @param  Builder<static>  $query
     * @return Builder<static>
     *
     * @see self::scopeNotFullyTranslated() The inverse.
     */
    public function scopeFullyTranslated(Builder $query, ?string $sourceLocale = null)
    {
        $translatableFields = $this->getTranslatableAttributes();

        if ($translatableFields === []) {
            return $query;
        }

        $targetLanguages = Locales::targets($sourceLocale);

        foreach ($translatableFields as $field) {
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
     * @param  string|null  $sourceLocale  Locale content is authored in; its targets
     *                                     are every other supported locale. Null = the current locale.
     * @param  Builder<static>  $query
     * @return Builder<static>
     *
     * @see self::scopeFullyTranslated() The inverse.
     */
    public function scopeNotFullyTranslated(Builder $query, ?string $sourceLocale = null)
    {
        $translatableFields = $this->getTranslatableAttributes();

        if ($translatableFields === []) {
            return $query;
        }

        $targetLanguages = Locales::targets($sourceLocale);

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

    /**
     * Records with at least one translation awaiting review (review workflow,
     * TRANSLATION_REVIEW), optionally for one field and/or locale. Published
     * translations are what fullyTranslated()/notFullyTranslated() look at;
     * this looks at the pending ones.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePendingReview(Builder $query, ?string $field = null, ?string $locale = null)
    {
        $model = $query->getModel();
        /** @var Connection $connection Every Laravel database connection extends it. */
        $connection = $query->getConnection();
        $ownerKey = KeyCast::asString($connection, $model->getQualifiedKeyName());

        // A correlated subquery: no list of ids is ever bound, so it works at
        // any queue size (a bound id list would hit SQL Server's ~2,100
        // parameter limit). See KeyCast for the string/integer key comparison.
        return $query->whereExists(function ($sub) use ($model, $ownerKey, $field, $locale) {
            $sub->selectRaw('1')
                ->from('translation_reviews')
                ->where('translation_reviews.translatable_type', $model->getMorphClass())
                ->where('translation_reviews.status', TranslationReview::PENDING)
                ->whereColumn('translation_reviews.translatable_id', '=', $ownerKey);

            if ($field !== null) {
                $sub->where('translation_reviews.field', $field);
            }

            if ($locale !== null) {
                $sub->where('translation_reviews.locale', $locale);
            }
        });
    }
}
