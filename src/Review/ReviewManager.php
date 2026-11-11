<?php

namespace CodingPartners\TranslaGenius\Review;

use CodingPartners\TranslaGenius\Contracts\HasTranslatableAttributes;
use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;
use CodingPartners\TranslaGenius\Events\TranslationApproved;
use CodingPartners\TranslaGenius\Events\TranslationPendingReview;
use CodingPartners\TranslaGenius\Events\TranslationRejected;
use CodingPartners\TranslaGenius\Events\TranslationSuperseded;
use CodingPartners\TranslaGenius\Exceptions\InvalidReviewStateException;
use CodingPartners\TranslaGenius\Exceptions\ReviewConflictException;
use CodingPartners\TranslaGenius\Exceptions\StaleReviewException;
use CodingPartners\TranslaGenius\Models\TranslationReview;
use CodingPartners\TranslaGenius\Providers\CachingProvider;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Support\Text;
use CodingPartners\TranslaGenius\Support\TranslationJobDispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * The review/approval workflow's primitives (TRANSLATION_REVIEW). Reached
 * through the TranslaGenius facade (TranslaGenius::reviews()) or the
 * container. The package never checks permissions: authorize in your app
 * before calling approve()/reject() (see the README).
 *
 * Every decision locks the record's row and re-checks the same snapshots the
 * job uses (TranslationWriter): a translation whose source text changed since
 * it was produced is never published (StaleReviewException, the review
 * becomes superseded), and a published value someone edited by hand since is
 * never silently overwritten (ReviewConflictException, unless forced).
 */
class ReviewManager
{
    // --- producing (called by TranslateFields) ------------------------------

    /**
     * Store a produced translation as pending instead of publishing it.
     *
     * Under the record's lock: refuses if the stored source no longer equals
     * $sourceText (SOURCE_CHANGED) or, for a re-translation, if the target no
     * longer holds $staleValue (TARGET_CHANGED) - the same rules as a direct
     * write. Supersedes any earlier pending row for the same field/locale,
     * applies the visibility setting (`clear` removes the published value so
     * the fallback shows), and records the published value as it now stands.
     *
     * @param  Model&HasTranslatableAttributes  $model
     * @return string A TranslationWriter outcome constant.
     */
    public function propose(
        Model $model,
        string $field,
        string $locale,
        TranslationResult $result,
        string $sourceLocale,
        string $sourceText,
        ?string $staleValue = null,
    ): string {
        $superseded = [];
        $review = null;

        $outcome = DB::transaction(function () use ($model, $field, $locale, $result, $sourceLocale, $sourceText, $staleValue, &$superseded, &$review) {
            $locked = $model->newQuery()->lockForUpdate()->whereKey($model->getKey())->first();

            if (! $locked) {
                return TranslationWriter::MISSING;
            }

            if ($locked->getTranslation($field, $sourceLocale, false) !== $sourceText) {
                return TranslationWriter::SOURCE_CHANGED;
            }

            if ($staleValue !== null && (string) $locked->getTranslation($field, $locale, false) !== $staleValue) {
                return TranslationWriter::TARGET_CHANGED;
            }

            $superseded = $this->supersede(
                TranslationReview::query()->for($locked)->field($field)->locale($locale)->pending()
            );

            $published = (string) $locked->getTranslation($field, $locale, false);

            if ($published !== '' && ReviewSettings::whilePending($locked, $field) === ReviewSettings::CLEAR) {
                $locked->withoutEvents(function () use ($locked, $field, $locale) {
                    $locked->forgetTranslation($field, $locale);
                    $locked->save();
                });
                $published = '';
            }

            $review = TranslationReview::create([
                'translatable_type' => $locked->getMorphClass(),
                'translatable_id' => (string) $locked->getKey(),
                'field' => $field,
                'locale' => $locale,
                'status' => TranslationReview::PENDING,
                'text' => $result->text,
                'source_locale' => $sourceLocale,
                'source_text' => $sourceText,
                'source_hash' => self::hash($sourceText),
                'published_snapshot' => $published,
                'provider' => $result->provider,
                'request_id' => $result->requestId,
                'cached' => $result->cached,
            ]);

            return TranslationWriter::WRITTEN;
        });

        foreach ($superseded as $old) {
            TranslationSuperseded::dispatch($old, TranslationSuperseded::REPLACED);
        }

        if ($review !== null) {
            TranslationPendingReview::dispatch($review);
        }

        return $outcome;
    }

    /**
     * Supersede the pending rows of $field whose source differs from
     * $currentSourceText - called when a field's source changes, before the
     * re-translation produces new rows.
     */
    public function supersedeForSourceChange(Model $model, string $field, string $currentSourceText): void
    {
        $rows = $this->supersede(
            TranslationReview::query()->for($model)->field($field)->pending()
                ->where('source_hash', '!=', self::hash($currentSourceText))
        );

        foreach ($rows as $row) {
            TranslationSuperseded::dispatch($row, TranslationSuperseded::SOURCE_CHANGED);
        }
    }

    /**
     * Field => locales a fill-missing job must not request for $model: pairs
     * with a pending row, and - unless $includeRejected (backfill, explicit
     * re-translation) - pairs rejected for their current source text, so a
     * rejection leaves the locale missing until it is asked for again.
     *
     * @param  Model&HasTranslatableAttributes  $model
     * @return array<string, array<int, string>>
     */
    public function pairsToSkip(Model $model, string $sourceLocale, bool $includeRejected): array
    {
        $statuses = $includeRejected
            ? [TranslationReview::PENDING]
            : [TranslationReview::PENDING, TranslationReview::REJECTED];

        $skip = [];

        foreach (TranslationReview::query()->for($model)->whereIn('status', $statuses)->get() as $row) {
            if ($row->status === TranslationReview::REJECTED) {
                $current = (string) $model->getTranslation($row->field, $sourceLocale, false);

                if (self::hash($current) !== $row->source_hash) {
                    continue; // rejected for an older source: the new one may be requested
                }
            }

            $skip[$row->field][] = $row->locale;
        }

        return $skip;
    }

    // --- reviewing ------------------------------------------------------------

    /**
     * Pending reviews, as a query the app can filter and paginate.
     *
     * @return Builder<TranslationReview>
     */
    public function pending(): Builder
    {
        return TranslationReview::query()->pending();
    }

    /**
     * Publish a pending translation - the produced text, or $editedText.
     *
     * @param  bool  $cacheEdit  With an edit: store it in the translation cache so
     *                           the next identical source text gets the corrected version.
     * @param  bool  $force  Publish even if the published value was edited by hand
     *                       since the translation was produced.
     *
     * @throws StaleReviewException The source changed; the review is now superseded.
     * @throws ReviewConflictException The published value changed since; nothing written.
     * @throws InvalidReviewStateException The review isn't pending.
     */
    public function approve(
        TranslationReview $review,
        ?string $editedText = null,
        ?Model $reviewer = null,
        ?string $note = null,
        bool $cacheEdit = true,
        bool $force = false,
    ): TranslationReview {
        $edited = $editedText !== null && ! Text::isBlank($editedText) && $editedText !== $review->text;

        // Outcomes are decided inside the transaction but thrown after it, so
        // marking a stale review superseded is committed, not rolled back.
        [$outcome, $current] = DB::transaction(function () use ($review, $editedText, $edited, $reviewer, $note, $force) {
            $review = $this->lockReview($review);
            $owner = $this->lockOwner($review);

            if ($owner === null || self::hash((string) $owner->getTranslation($review->field, $review->source_locale, false)) !== $review->source_hash) {
                $this->markSuperseded($review);

                return ['stale', null];
            }

            $current = (string) $owner->getTranslation($review->field, $review->locale, false);
            if (! $force && $current !== (string) $review->published_snapshot) {
                return ['conflict', $current];
            }

            $text = $edited ? $editedText : $review->text;
            $owner->withoutEvents(function () use ($owner, $review, $text) {
                $owner->setTranslation($review->field, $review->locale, $text);
                $owner->save();
            });

            $review->forceFill([
                'status' => TranslationReview::APPROVED,
                'approved_text' => $edited ? $editedText : null,
                'reviewed_at' => now(),
                'note' => $note,
            ]);
            $this->setReviewer($review, $reviewer);
            $review->save();

            return ['approved', null];
        }, attempts: 1);

        $review->refresh();

        if ($outcome === 'stale') {
            TranslationSuperseded::dispatch($review, TranslationSuperseded::SOURCE_CHANGED);

            throw new StaleReviewException($review);
        }

        if ($outcome === 'conflict') {
            throw new ReviewConflictException($review, (string) $current);
        }

        if ($edited && $cacheEdit) {
            $this->cacheProvider()?->storeReviewed(
                $review->source_text, $review->source_locale, $review->locale, $review->field, $this->modelName($review), $editedText, $review->provider
            );
        }

        TranslationApproved::dispatch($review, $reviewer, $edited);

        return $review;
    }

    /**
     * Reject a pending translation: nothing is published, and its cache entry
     * is removed so identical text isn't served the rejected version again.
     * With review.on_reject = retranslate, a fresh translation is requested.
     *
     * @throws InvalidReviewStateException The review isn't pending.
     */
    public function reject(TranslationReview $review, ?Model $reviewer = null, ?string $note = null): TranslationReview
    {
        DB::transaction(function () use ($review, $reviewer, $note) {
            $review = $this->lockReview($review);

            $review->forceFill(['status' => TranslationReview::REJECTED, 'reviewed_at' => now(), 'note' => $note]);
            $this->setReviewer($review, $reviewer);
            $review->save();
        }, attempts: 1);

        $review->refresh();
        $this->forgetCached($review);

        TranslationRejected::dispatch($review, $reviewer, $note);

        if (ReviewSettings::onReject() === ReviewSettings::RETRANSLATE) {
            $this->retranslate($review);
        }

        return $review;
    }

    /**
     * Request a fresh translation of one field/locale (after a rejection, or
     * to replace a published one): its cache entry is removed first, and a job
     * re-translates just that pair from the current source text. With review
     * on, the result becomes a new pending review.
     */
    public function retranslate(TranslationReview $review): void
    {
        $this->forgetCached($review);

        $owner = $review->translatable;
        if ($owner === null) {
            return;
        }

        $sourceText = (string) $owner->getTranslation($review->field, $review->source_locale, false);
        if (Text::isBlank($sourceText)) {
            return;
        }

        $others = array_values(array_diff(get_supported_languages($review->source_locale), [$review->locale]));

        TranslationJobDispatcher::dispatch(
            $owner::getTranslationJobClass(),
            $owner,
            [$review->field],
            [$review->field => [
                'locale' => $review->source_locale,
                'text' => $sourceText,
                'keep' => $others,
                'targets' => [$review->locale => (string) $owner->getTranslation($review->field, $review->locale, false)],
            ]],
            $review->source_locale,
        );
    }

    /**
     * Approve every pending review of a record (a Model) or of a model class,
     * optionally narrowed to a field and/or locale. Reviews that turn out
     * stale or conflicting are skipped and counted, not fatal.
     *
     * @return array{approved: int, skipped_stale: int, skipped_conflict: int}
     */
    public function approveAll(Model|string $target, ?string $field = null, ?string $locale = null, ?Model $reviewer = null): array
    {
        $query = $this->pending();
        $target instanceof Model ? $query->for($target) : $query->forModel($target);

        if ($field !== null) {
            $query->field($field);
        }
        if ($locale !== null) {
            $query->locale($locale);
        }

        $summary = ['approved' => 0, 'skipped_stale' => 0, 'skipped_conflict' => 0];

        // In chunks, so a large backlog isn't loaded into memory at once.
        // chunkById() pages by id, so rows leaving the pending set as they're
        // approved don't make it skip any.
        $query->chunkById(200, function ($reviews) use (&$summary, $reviewer) {
            foreach ($reviews as $review) {
                try {
                    $this->approve($review, reviewer: $reviewer);
                    $summary['approved']++;
                } catch (StaleReviewException) {
                    $summary['skipped_stale']++;
                } catch (ReviewConflictException) {
                    $summary['skipped_conflict']++;
                } catch (InvalidReviewStateException) {
                    // Decided concurrently; nothing to do.
                }
            }
        });

        return $summary;
    }

    /** Delete decided (non-pending) reviews older than $days days. */
    public function prune(int $days): int
    {
        return TranslationReview::query()
            ->where('status', '!=', TranslationReview::PENDING)
            ->where('updated_at', '<', now()->subDays($days))
            ->delete();
    }

    // --- internals ----------------------------------------------------------

    public static function hash(string $text): string
    {
        return hash('sha256', $text);
    }

    /**
     * @param  Builder<TranslationReview>  $query
     * @return array<int, TranslationReview> The rows marked superseded.
     */
    protected function supersede(Builder $query): array
    {
        $rows = $query->get()->all();

        foreach ($rows as $row) {
            $this->markSuperseded($row);
        }

        return $rows;
    }

    protected function markSuperseded(TranslationReview $review): void
    {
        $review->forceFill(['status' => TranslationReview::SUPERSEDED])->save();
    }

    protected function lockReview(TranslationReview $review): TranslationReview
    {
        $locked = TranslationReview::query()->lockForUpdate()->find($review->id);

        if ($locked === null || ! $locked->isPending()) {
            throw new InvalidReviewStateException($locked ?? $review);
        }

        return $locked;
    }

    /**
     * @return (Model&HasTranslatableAttributes)|null
     */
    protected function lockOwner(TranslationReview $review): ?Model
    {
        $class = Relation::getMorphedModel($review->translatable_type) ?? $review->translatable_type;

        if (! is_subclass_of($class, Model::class)) {
            return null;
        }

        /** @var (Model&HasTranslatableAttributes)|null $owner A review is only ever created for a model using the Translatable trait. */
        $owner = $class::query()->lockForUpdate()->whereKey($review->translatable_id)->first();

        return $owner;
    }

    protected function setReviewer(TranslationReview $review, ?Model $reviewer): void
    {
        $review->forceFill([
            'reviewer_type' => $reviewer?->getMorphClass(),
            'reviewer_id' => $reviewer === null ? null : (string) $reviewer->getKey(),
        ]);
    }

    protected function cacheProvider(): ?CachingProvider
    {
        $provider = app(TranslationProviderInterface::class);

        return $provider instanceof CachingProvider ? $provider : null;
    }

    protected function forgetCached(TranslationReview $review): void
    {
        $this->cacheProvider()?->forgetTranslation(
            $review->source_text, $review->source_locale, $review->locale, $review->field, $this->modelName($review)
        );
    }

    /** The class basename used as {model} context in cache keys. */
    protected function modelName(TranslationReview $review): string
    {
        $class = Relation::getMorphedModel($review->translatable_type) ?? $review->translatable_type;

        return class_basename($class);
    }
}
