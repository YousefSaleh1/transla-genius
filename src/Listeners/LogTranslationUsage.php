<?php

namespace CodingPartners\TranslaGenius\Listeners;

use CodingPartners\TranslaGenius\Events\TranslationCompleted;
use CodingPartners\TranslaGenius\Events\TranslationRequestBilled;
use CodingPartners\TranslaGenius\Models\TranslationUsageLog;

/**
 * Writes one Models\TranslationUsageLog row per Events\TranslationCompleted,
 * with an estimated cost computed from the reporting provider's own
 * translagenius.providers.{name} entry, if it configures a rate.
 *
 * Only ever registered by TranslaGeniusServiceProvider when
 * translagenius.usage_tracking.enabled is true - that check happens once,
 * at listener-registration time in the service provider, not on every
 * event here, so this class itself has no "am I enabled?" branch at all;
 * if it's running, it's supposed to be.
 *
 * The write is a single synchronous insert, not queued again:
 * TranslationCompleted only ever fires from inside TranslateFields, which
 * is already a queued job - adding a second queue hop here would just
 * delay a write that's already off the request cycle, for no benefit.
 *
 * Deliberately doesn't handle Events\TranslationFailed: this feature is
 * scoped to usage/cost (tokens and money), neither of which a failure has
 * - a failed attempt has no TranslationResult to read cost from, and
 * giving failures rows in the same table would either fill it with
 * mostly-null noise or need every cost/usage query to filter them out.
 * Failure-rate visibility is what TranslationFailed itself, plus your own
 * logging/monitoring, is for.
 *
 * @see TranslationUsageLog
 * @see TranslationCompleted
 */
class LogTranslationUsage
{
    public function handle(TranslationCompleted $event): void
    {
        TranslationUsageLog::create([
            'provider' => $event->result->provider,
            'model_class' => $event->modelClass,
            'model_id' => (string) $event->modelId,
            'field' => $event->field,
            'source_locale' => $event->sourceLocale,
            'target_locale' => $event->targetLocale,
            'prompt_tokens' => $event->result->promptTokens,
            'completion_tokens' => $event->result->completionTokens,
            'estimated_cost' => $this->estimateCost($event->result->provider, $event->result->promptTokens, $event->result->completionTokens),
            'request_id' => $event->result->requestId,
            'cached' => $event->result->cached,
            'outcome' => TranslationUsageLog::OUTCOME_TRANSLATED,
        ]);
    }

    /**
     * One row for a billed request that didn't produce a saved translation
     * (truncated, unusable, failed, discarded), so summed usage covers every
     * billed request. A batch request's row lists its locales comma-separated
     * in target_locale.
     */
    public function handleBilled(TranslationRequestBilled $event): void
    {
        TranslationUsageLog::create([
            'provider' => $event->provider,
            'model_class' => $event->modelClass,
            'model_id' => $event->modelId === null ? null : (string) $event->modelId,
            'field' => $event->field,
            'source_locale' => $event->sourceLocale,
            'target_locale' => implode(',', $event->targetLocales),
            'prompt_tokens' => $event->promptTokens,
            'completion_tokens' => $event->completionTokens,
            'estimated_cost' => $this->estimateCost($event->provider, $event->promptTokens, $event->completionTokens),
            'request_id' => $event->requestId,
            'cached' => false,
            'outcome' => $event->outcome,
        ]);
    }

    /**
     * Estimated cost from the cost_per_1k_prompt_tokens /
     * cost_per_1k_completion_tokens rates on translagenius.providers.{provider}
     * - the config key the provider was resolved under. Returns null - never
     * 0 - whenever a definitive number can't be computed: no provider
     * reported, no rate configured for it, or a rate is configured for a
     * token count the provider didn't report.
     *
     * @return string|null Decimal string with 6 fractional digits, or null.
     */
    protected function estimateCost(?string $provider, ?int $promptTokens, ?int $completionTokens): ?string
    {
        if ($provider === null) {
            return null;
        }

        $rates = config("translagenius.providers.{$provider}", []);
        // An empty string (e.g. `OPENAI_COST_PER_1K_PROMPT_TOKENS=` in .env)
        // means "not configured", not a rate of 0.
        $promptRate = ($rates['cost_per_1k_prompt_tokens'] ?? '') === '' ? null : $rates['cost_per_1k_prompt_tokens'];
        $completionRate = ($rates['cost_per_1k_completion_tokens'] ?? '') === '' ? null : $rates['cost_per_1k_completion_tokens'];

        if ($promptRate === null && $completionRate === null) {
            return null;
        }

        if ($promptRate !== null && $promptTokens === null) {
            return null;
        }

        if ($completionRate !== null && $completionTokens === null) {
            return null;
        }

        $cost = 0.0;

        if ($promptRate !== null) {
            $cost += ($promptTokens / 1000) * (float) $promptRate;
        }

        if ($completionRate !== null) {
            $cost += ($completionTokens / 1000) * (float) $completionRate;
        }

        return number_format($cost, 6, '.', '');
    }
}
