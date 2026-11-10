<?php

namespace CodingPartners\TranslaGenius\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per successfully completed translation
 * (Events\TranslationCompleted), written by Listeners\LogTranslationUsage
 * when translaGenius.usage_tracking.enabled is true.
 *
 * This is genuinely public API surface, not an internal implementation
 * detail: query it directly from your own application code to build
 * usage/cost reports, dashboards, or per-tenant billing. Nothing else in
 * this package reads from `translation_usage_logs` - it exists purely for
 * you to query.
 *
 * `estimated_cost` is null whenever a rate wasn't configured for the
 * reporting provider (see translaGenius.providers.{name}.cost_per_1k_*) or the
 * provider didn't report the token counts a configured rate needs - never
 * a misleading `0`. Filter it out explicitly (`whereNotNull('estimated_cost')`)
 * before summing/averaging cost, so unrated rows don't silently read as free.
 *
 * @property int $id
 * @property string|null $provider The TranslationResult::$provider that produced this row.
 * @property string|null $model_class Fully-qualified class of the translated model.
 * @property string|null $model_id Primary key of the translated model.
 * @property string|null $field The translatable attribute name.
 * @property string $source_locale
 * @property string $target_locale
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property string|null $estimated_cost Decimal string (6 fractional digits), or null if unrated.
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @see \CodingPartners\TranslaGenius\Listeners\LogTranslationUsage
 * @see \CodingPartners\TranslaGenius\Events\TranslationCompleted
 */
class TranslationUsageLog extends Model
{
    protected $table = 'translation_usage_logs';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'estimated_cost' => 'decimal:6',
    ];
}
