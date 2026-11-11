<?php

namespace CodingPartners\TranslaGenius\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One AI translation in the review workflow (TRANSLATION_REVIEW): pending a
 * human decision, or approved / rejected / superseded. Public API - query it
 * for review queues and audit trails, and register a policy for it to
 * authorize approvals (see the README's "Review workflow" section).
 *
 * @property int $id
 * @property string $translatable_type
 * @property string $translatable_id
 * @property string $field
 * @property string $locale
 * @property string $status pending | approved | rejected | superseded
 * @property string $text The AI translation as produced.
 * @property string|null $approved_text What was published, if a reviewer edited it.
 * @property string $source_locale
 * @property string $source_text
 * @property string $source_hash
 * @property string|null $published_snapshot The published value when this row was produced.
 * @property string|null $provider
 * @property string|null $request_id
 * @property bool $cached
 * @property string|null $reviewer_type
 * @property string|null $reviewer_id
 * @property Carbon|null $reviewed_at
 * @property string|null $note
 */
class TranslationReview extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const SUPERSEDED = 'superseded';

    protected $table = 'translation_reviews';

    protected $guarded = [];

    protected $casts = [
        'cached' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    /** The record this translation belongs to. */
    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Whoever approved or rejected it, if the app passed a reviewer. */
    public function reviewer(): MorphTo
    {
        return $this->morphTo();
    }

    /** The text that is (or would be) published: the reviewer's edit if any. */
    public function publishedText(): string
    {
        return $this->approved_text ?? $this->text;
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    /** Rows for one record. */
    public function scopeFor(Builder $query, Model $model): Builder
    {
        return $query->where('translatable_type', $model->getMorphClass())
            ->where('translatable_id', (string) $model->getKey());
    }

    /** Rows for every record of a model class (or morph alias). */
    public function scopeForModel(Builder $query, string $modelClass): Builder
    {
        $type = class_exists($modelClass) ? (new $modelClass)->getMorphClass() : $modelClass;

        return $query->where('translatable_type', $type);
    }

    public function scopeField(Builder $query, string $field): Builder
    {
        return $query->where('field', $field);
    }

    public function scopeLocale(Builder $query, string $locale): Builder
    {
        return $query->where('locale', $locale);
    }

    public function scopeProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    public function scopeOlderThan(Builder $query, \DateTimeInterface $moment): Builder
    {
        return $query->where('created_at', '<', $moment);
    }
}
