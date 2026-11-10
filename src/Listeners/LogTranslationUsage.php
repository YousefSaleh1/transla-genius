<?php

namespace CodingPartners\TranslaGenius\Listeners;

use CodingPartners\TranslaGenius\Events\TranslationCompleted;
use CodingPartners\TranslaGenius\Models\TranslationUsageLog;

/**
 * Writes one Models\TranslationUsageLog row per Events\TranslationCompleted,
 * with an estimated cost computed from translaGenius.usage_tracking.providers
 * if a rate is configured for the reporting provider.
 *
 * Only ever registered by TranslaGeniusServiceProvider when
 * translaGenius.usage_tracking.enabled is true - that check happens once,
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
 * @see \CodingPartners\TranslaGenius\Models\TranslationUsageLog
 * @see \CodingPartners\TranslaGenius\Events\TranslationCompleted
 */
class LogTranslationUsage
{
    /**
     * @param TranslationCompleted $event
     * @return void
     */
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
            'estimated_cost' => $this->estimateCost($event),
        ]);
    }

    /**
     * Compute an estimated cost from translaGenius.usage_tracking.providers,
     * keyed by $event->result->provider (the identifier the provider itself
     * reports, not necessarily a key under the top-level `providers`
     * config). Returns null - never 0 - whenever a definitive number can't
     * be computed: no provider reported, no rate configured for it, or a
     * rate is configured for a token count the provider didn't report.
     *
     * @param TranslationCompleted $event
     * @return string|null Decimal string with 6 fractional digits, or null.
     */
    protected function estimateCost(TranslationCompleted $event): ?string
    {
        $provider = $event->result->provider;

        if ($provider === null) {
            return null;
        }

        $rates = config("translaGenius.usage_tracking.providers.{$provider}", []);
        $promptRate = $rates['cost_per_1k_prompt_tokens'] ?? null;
        $completionRate = $rates['cost_per_1k_completion_tokens'] ?? null;

        if ($promptRate === null && $completionRate === null) {
            return null;
        }

        if ($promptRate !== null && $event->result->promptTokens === null) {
            return null;
        }

        if ($completionRate !== null && $event->result->completionTokens === null) {
            return null;
        }

        $cost = 0.0;

        if ($promptRate !== null) {
            $cost += ($event->result->promptTokens / 1000) * (float) $promptRate;
        }

        if ($completionRate !== null) {
            $cost += ($event->result->completionTokens / 1000) * (float) $completionRate;
        }

        return number_format($cost, 6, '.', '');
    }
}
