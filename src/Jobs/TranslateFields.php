<?php

namespace CodingPartners\TranslaGenius\Jobs;

use CodingPartners\TranslaGenius\Contracts\BatchTranslatorInterface;
use CodingPartners\TranslaGenius\Contracts\HasTranslatableAttributes;
use CodingPartners\TranslaGenius\Contracts\TranslationJob;
use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;
use CodingPartners\TranslaGenius\Events\TranslationCompleted;
use CodingPartners\TranslaGenius\Events\TranslationFailed;
use CodingPartners\TranslaGenius\Events\TranslationFailed as TranslationFailedEvent;
use CodingPartners\TranslaGenius\Events\TranslationRequestBilled;
use CodingPartners\TranslaGenius\Events\TranslationStarted;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use CodingPartners\TranslaGenius\Review\ReviewManager;
use CodingPartners\TranslaGenius\Review\ReviewSettings;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Support\TranslationJobDispatcher;
use CodingPartners\TranslaGenius\Traits\Translatable;
use CodingPartners\TranslaGenius\TranslaGeniusServiceProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued job that translates a model's translatable fields into every
 * configured target language and persists the results.
 *
 * Dispatched automatically by Traits\Translatable on model create/update
 * (see Translatable::bootTranslatable() and translationJobClass() for how
 * to swap in a custom job with the same constructor signature). Computing
 * the translations (this class, via TranslatorInterface) is deliberately
 * separate from persisting them (TranslationWriter) - see TranslationWriter
 * for why only the write step runs under a row lock.
 *
 * Fires three lifecycle events a consuming app can listen for without
 * touching this class: Events\TranslationStarted and
 * Events\TranslationCompleted around each individual field/locale
 * translate() call (see translateMissingPairs()), and Events\TranslationFailed
 * once, from failed(), if the job gives up permanently. See each event's
 * own docblock for exactly when/why it fires.
 *
 * @see Translatable
 * @see TranslationWriter
 * @see TranslationStarted
 * @see TranslationCompleted
 * @see TranslationFailed
 */
class TranslateFields implements ShouldQueue, TranslationJob
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var Model&HasTranslatableAttributes
     */
    protected Model $model;

    /**
     * @var array<int, string>
     */
    protected array $fields;

    /**
     * Fields whose source text changed in the save that dispatched this job:
     * field => [locale (source locale), text (the new source, as a
     * snapshot), keep (target locales that save set itself)]. Their other
     * target locales are re-translated and overwritten - see
     * Translatable::staleTranslationPlan() and TranslationWriter::apply().
     *
     * @var array<string, array{locale: string, text: string, keep: array<int, string>, targets?: array<string, string>}>
     */
    protected array $retranslate;

    /**
     * The locale the content was authored in, captured at dispatch time (see
     * TranslationJobDispatcher::sourceLocale()). Null only for a job queued
     * by a version that didn't carry it - see resolvedSourceLocale().
     */
    protected ?string $sourceLocale = null;

    /**
     * Extra dispatch options. `include_rejected`: with review on, also request
     * pairs whose translation was rejected for the current source text
     * (backfill sets it; a model save doesn't, so a rejection stays put).
     *
     * @var array<string, mixed>
     */
    protected array $options = [];

    /** Set per run when the review workflow is on; null when it's off. */
    protected ?ReviewManager $reviews = null;

    /**
     * Number of attempts before the job is considered permanently failed
     * (translagenius.retries.job_tries / TRANSLATION_JOB_TRIES, default 3),
     * set at construction so it's captured in the queued payload. Transient
     * failures (timeouts, rate limits, 5xx) use these up via backoff();
     * non-retryable failures (bad config, 4xx other than 429, truncation)
     * skip straight to failed() via TranslationFailedException::isRetryable().
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Delay (seconds) before each retry, from translagenius.retries.job_backoff
     * (TRANSLATION_JOB_BACKOFF, comma-separated; default "10,30"): a short
     * initial wait, then a longer one. Translation-API failures are typically
     * either resolved within seconds or not at all. A 429 with a long
     * Retry-After overrides this for that attempt - see handle().
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $configured = config('translagenius.retries.job_backoff', '10,30');
        $values = is_array($configured) ? $configured : explode(',', (string) $configured);

        $delays = array_values(array_map(
            'intval',
            array_filter(array_map('trim', $values), fn ($value) => $value !== '')
        ));

        return $delays === [] ? [10, 30] : $delays;
    }

    /**
     * Create a new job instance from its dispatch payload (the TranslationJob
     * contract). The payload is unpacked into this job's own properties, so
     * SerializesModels handles the model exactly as before - and a job queued
     * by an earlier version, which has these same properties, still runs.
     */
    public function __construct(TranslationJobPayload $payload)
    {
        $this->model = $payload->model;
        $this->fields = $payload->fields;
        $this->retranslate = $payload->retranslate;
        $this->sourceLocale = $payload->sourceLocale;
        $this->options = $payload->options;
        $this->tries = max(1, TranslaGeniusServiceProvider::intSetting(
            config('translagenius.retries.job_tries'),
            3
        ));
    }

    /**
     * Execute the job: compute missing translations for every configured
     * field via $translator, then persist them via $writer.
     *
     * A TranslationFailedException that isn't retryable calls
     * $this->fail() immediately instead of retrying; a retryable one is
     * rethrown so the queue applies $tries/backoff() as normal. Any other
     * exception propagates uncaught.
     *
     * @param  TranslatorInterface  $translator  Resolves the actual per-field,
     *                                           per-locale translate() calls.
     * @param  TranslationWriter  $writer  Persists the computed translations
     *                                     onto $this->model.
     * @return void
     *
     * @throws TranslationFailedException If translation fails and is
     *                                    retryable (propagated for the queue to retry).
     */
    public function handle(TranslatorInterface $translator, TranslationWriter $writer)
    {
        try {
            // Never the worker's own locale: it has nothing to do with the
            // request that saved the record.
            $currentLocale = $this->resolvedSourceLocale();
            $targetLanguages = get_supported_languages($currentLocale);

            // Review off: no review queries at all.
            $this->reviews = ReviewSettings::enabled() ? app(ReviewManager::class) : null;

            $saved = $this->translateMissingPairs($translator, $writer, $currentLocale, $targetLanguages);

            if ($saved > 0) {
                Log::info('Translation completed successfully', [
                    'model_id' => $this->model->getKey(),
                    'model_class' => get_class($this->model),
                    'translations_saved' => $saved,
                ]);
            }
        } catch (TranslationFailedException $e) {
            if (! $e->isRetryable()) {
                Log::error('Translation job failed permanently (not retryable)', [
                    'model_class' => get_class($this->model),
                    'model_id' => $this->model->getKey(),
                    'status_code' => $e->getStatusCode(),
                    'message' => $e->getMessage(),
                ]);

                $this->fail($e);

                return;
            }

            // The provider asked for a specific wait (429 Retry-After longer
            // than it would sleep in-request): release with that delay instead
            // of the generic backoff. Only on a real queue (the sync driver
            // can't delay) and only while attempts remain, so the last
            // attempt still fails through failed() and fires TranslationFailed.
            if ($e->getRetryAfter() !== null
                && $this->job !== null
                && ! $this->job instanceof SyncJob
                && $this->attempts() < $this->tries
            ) {
                $this->release($e->getRetryAfter());

                return;
            }

            // Retryable: let it propagate so the queue applies $tries/backoff().
            throw $e;
        }
    }

    /**
     * Called by the queue once the job has exhausted its retries (or after
     * an immediate $this->fail() call for a non-retryable failure). This is
     * the deliberate replacement for the old catch-and-swallow behavior:
     * a permanent failure is now visible here (and via Laravel's normal
     * failed_jobs table / JobFailed event) instead of disappearing after a
     * single log line. Consuming apps that want their own handling can
     * listen for Illuminate\Queue\Events\JobFailed rather than needing
     * this method extended.
     *
     * Also dispatches Events\TranslationFailed, but only when $exception is
     * a TranslationFailedException - anything else (e.g. a TranslationWriter
     * persistence failure) isn't a "translation failed" in the domain sense
     * that event names, so it's logged here but not turned into that event.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Translation job permanently failed', [
            'model_class' => get_class($this->model),
            'model_id' => $this->model->getKey() ?? null,
            'exception_class' => get_class($exception),
            'message' => $exception->getMessage(),
            'status_code' => $exception instanceof TranslationFailedException
                ? $exception->getStatusCode()
                : null,
        ]);

        if ($exception instanceof TranslationFailedException) {
            TranslationFailedEvent::dispatch(
                get_class($this->model),
                $this->model->getKey() ?? null,
                $exception,
            );
        }
    }

    /**
     * Translate every missing field/locale pair and save each one as soon as
     * it succeeds, so a failure part-way through loses nothing already paid
     * for: when the queue retries the job, the model is re-read and only the
     * pairs still missing are requested again.
     *
     * Saved per pair rather than per field: a DB write costs milliseconds,
     * an API call costs money and seconds, so even the earlier locales of a
     * field whose later locale failed shouldn't be translated twice.
     * TranslationWriter re-checks each pair under a row lock and only fills
     * gaps, so saving as we go stays safe against concurrent jobs, and it
     * saves without model events, so it doesn't re-trigger the `updated`
     * listener that dispatched this job.
     *
     * Batching (TRANSLATION_BATCH): when the translator can batch and a field
     * is missing two or more locales, they are requested together first.
     * Every valid locale of the reply is saved at once; the locales it missed
     * are then translated one call at a time. See translateField().
     *
     * Events: TranslationStarted fires once per pair, immediately before the
     * first request that attempts it (for a batch, before the batch request;
     * a one-at-a-time retry of the same pair doesn't fire it again).
     * TranslationCompleted fires once per pair, after that pair is saved. If a
     * request throws, only TranslationStarted will have fired for its pairs -
     * see failed() for the corresponding failure event.
     *
     * @param  string  $currentLocale  The locale field values are read/translated from.
     * @param  array<int, string>  $targetLanguages  Locales to translate into.
     * @return int Number of pairs translated and saved by this call.
     */
    protected function translateMissingPairs(
        TranslatorInterface $translator,
        TranslationWriter $writer,
        string $currentLocale,
        array $targetLanguages
    ): int {
        $saved = 0;
        $skip = $this->reviews?->pairsToSkip($this->model, $currentLocale, (bool) ($this->options['include_rejected'] ?? false)) ?? [];

        foreach ($this->fields as $field) {
            if (isset($this->retranslate[$field])) {
                $saved += $this->retranslateField($translator, $writer, $field, $this->retranslate[$field]);

                continue;
            }

            $fieldTranslations = $this->normalizeFieldTranslations($this->model->getTranslations($field), $currentLocale);
            $sourceText = $fieldTranslations[$currentLocale] ?? null;

            if (! is_string($sourceText) || trim($sourceText) === '') {
                // Empty everywhere: a legitimately empty field (an optional
                // description, say) - nothing to do, nothing to report. Text in
                // another locale but not the source one is what signals a wrong
                // source locale, so only that is worth a warning.
                $textElsewhere = array_keys(array_filter(
                    $fieldTranslations,
                    fn ($value, $locale) => $locale !== $currentLocale && is_string($value) && trim($value) !== '',
                    ARRAY_FILTER_USE_BOTH
                ));

                if ($textElsewhere !== []) {
                    Log::warning('TranslateFields: no source text to translate from, but the field has text in another locale; skipping the field (is the source locale right?)', [
                        'model_class' => get_class($this->model),
                        'model_id' => $this->model->getKey(),
                        'field' => $field,
                        'source_locale' => $currentLocale,
                        'locales_with_text' => $textElsewhere,
                    ]);
                }

                continue;
            }

            if (! $this->shouldTranslateField($field, $sourceText)) {
                continue;
            }

            // With review on: not a pair already awaiting review, or one rejected
            // for this source text (see ReviewManager::pairsToSkip()).
            $missing = array_values(array_filter(
                $targetLanguages,
                fn (string $locale) => empty($fieldTranslations[$locale]) && ! in_array($locale, $skip[$field] ?? [], true)
            ));

            $saved += $this->translateField($translator, $writer, $field, $sourceText, $currentLocale, $missing);
        }

        return $saved;
    }

    /**
     * Re-translate a field whose source text changed: every target locale
     * except those the same save provided (`keep`), overwriting what's stored.
     *
     * The job works from the source text snapshot taken at save time. If the
     * record's source no longer matches it - the source was changed again, so
     * a newer job owns this field - nothing is requested at all; and if it
     * changes while this job is translating, TranslationWriter discards the
     * result under its lock.
     *
     * @param  array{locale: string, text: string, keep: array<int, string>, targets?: array<string, string>}  $plan
     * @return int Pairs saved.
     */
    protected function retranslateField(TranslatorInterface $translator, TranslationWriter $writer, string $field, array $plan): int
    {
        if ($this->model->getTranslation($field, $plan['locale'], false) !== $plan['text']) {
            Log::info('Skipping a stale re-translation: the source text changed again since this job was dispatched', [
                'model_class' => get_class($this->model),
                'model_id' => $this->model->getKey(),
                'field' => $field,
            ]);

            return 0;
        }

        if (! $this->shouldTranslateField($field, $plan['text'])) {
            return 0;
        }

        // Pending reviews of the old source text are obsolete now.
        $this->reviews?->supersedeForSourceChange($this->model, $field, $plan['text']);

        $targets = array_values(array_diff(
            get_supported_languages($plan['locale']),
            $plan['keep']
        ));

        // Only targets still holding their stale value: one an earlier attempt
        // of this job already replaced, or a person edited since, is left
        // alone - and costs no request. (A plan without target snapshots, from
        // an older payload, treats every target as stale.)
        $staleValues = $plan['targets'] ?? null;
        if (is_array($staleValues)) {
            $targets = array_values(array_filter(
                $targets,
                fn (string $locale) => ! array_key_exists($locale, $staleValues)
                    || (string) $this->model->getTranslation($field, $locale, false) === $staleValues[$locale]
            ));
        }

        if ($targets === []) {
            return 0;
        }

        return $this->translateField(
            $translator,
            $writer,
            $field,
            $plan['text'],
            $plan['locale'],
            $targets,
            ['locale' => $plan['locale'], 'text' => $plan['text'], 'targets' => is_array($staleValues) ? $staleValues : null],
        );
    }

    /**
     * Translate one field into its missing locales: a batch request first when
     * possible, then one request per locale still missing.
     *
     * Token accounting stays exact (see TranslationBatchResult::resultFor()):
     * a batch's token counts are carried by the first pair it completes and
     * the batch's other pairs get null. A billed reply that produced nothing
     * usable (truncated, unparseable) is logged by the provider itself via
     * TranslationRequestBilled, and its result carries no tokens.
     *
     * @param  array<int, string>  $missing  Locales to translate, in order.
     * @param  array{locale: string, text: string}|null  $snapshot  For a
     *                                                              re-translation: the source it was made from. Pairs then overwrite
     *                                                              what's stored, unless the source changed again, in which case the
     *                                                              rest of the field is abandoned (a newer job owns it).
     * @return int Pairs saved.
     */
    protected function translateField(
        TranslatorInterface $translator,
        TranslationWriter $writer,
        string $field,
        string $sourceText,
        string $currentLocale,
        array $missing,
        ?array $snapshot = null
    ): int {
        $saved = 0;
        $started = [];

        if (count($missing) > 1 && $translator instanceof BatchTranslatorInterface && $translator->canBatch()) {
            foreach ($missing as $locale) {
                $this->dispatchStarted($field, $currentLocale, $locale, $sourceText);
                $started[$locale] = true;
            }

            $batch = $translator->translateBatch($sourceText, $currentLocale, $missing, $field, $this->modelContextName());
            $tokensAttributed = false;

            foreach ($missing as $locale) {
                if (! isset($batch->translations[$locale])) {
                    continue;
                }

                // The request's tokens go to the first pair it actually
                // produced; a pair served from the cache never carries any.
                $carriesTokens = ! $tokensAttributed && ! $batch->isCached($locale);
                $result = $batch->resultFor($locale, $carriesTokens);
                $tokensAttributed = $tokensAttributed || $carriesTokens;

                $outcome = $this->savePair($writer, $field, $currentLocale, $locale, $result, $snapshot, $sourceText);
                if ($outcome === false) {
                    return $saved;
                }
                $saved += $outcome ? 1 : 0;
            }

            $missing = array_values(array_filter($missing, fn ($locale) => ! isset($batch->translations[$locale])));
        }

        foreach ($missing as $locale) {
            if (! isset($started[$locale])) {
                $this->dispatchStarted($field, $currentLocale, $locale, $sourceText);
            }

            $result = $translator->translate($sourceText, $currentLocale, $locale, $field, $this->modelContextName());

            $outcome = $this->savePair($writer, $field, $currentLocale, $locale, $result, $snapshot, $sourceText);
            if ($outcome === false) {
                return $saved;
            }
            $saved += $outcome ? 1 : 0;
        }

        return $saved;
    }

    /**
     * Save one pair, then announce it.
     *
     * Filling a gap, the write only lands if the stored source still equals
     * $sourceText (TranslationWriter::fillMissing()): a translation of text
     * that was edited meanwhile is discarded, and the edit's own job fills
     * the target from the new text.
     *
     * For a re-translation ($snapshot), the write only lands if the stored
     * source still matches and the stored target still holds its stale value
     * (TranslationWriter::applyRetranslation()). Returns true when saved; null
     * when this target changed since the plan (already re-translated by an
     * earlier attempt, or edited by hand) and was left alone; false when the
     * source changed again, so the rest of the field is abandoned. A result
     * that was billed but not saved is reported (see reportUnused()), and so
     * is one whose save throws - the exception then propagates as before.
     *
     * @param  array{locale: string, text: string, targets?: array<string, string>|null}|null  $snapshot
     */
    protected function savePair(
        TranslationWriter $writer,
        string $field,
        string $sourceLocale,
        string $targetLocale,
        TranslationResult $result,
        ?array $snapshot = null,
        ?string $sourceText = null
    ): ?bool {
        $staleValues = $snapshot['targets'] ?? null;
        $staleValue = is_array($staleValues) && array_key_exists($targetLocale, $staleValues) ? $staleValues[$targetLocale] : null;
        $pendingReview = $this->reviews !== null && ReviewSettings::appliesTo($this->model, $field, $targetLocale);

        try {
            if ($pendingReview) {
                // Reviewed: stored as pending instead of published, under the
                // same source/target snapshot rules as a direct write.
                $outcome = $this->reviews->propose(
                    $this->model,
                    $field,
                    $targetLocale,
                    $result,
                    $snapshot['locale'] ?? $sourceLocale,
                    $snapshot['text'] ?? (string) $sourceText,
                    $staleValue,
                );
            } elseif ($snapshot === null) {
                // Filling a gap: written only while the stored source is still
                // the text this was translated from.
                $outcome = $writer->fillMissing(
                    $this->model,
                    $field,
                    $targetLocale,
                    $result->text,
                    ['locale' => $sourceLocale, 'text' => (string) $sourceText],
                );
                if ($outcome === TranslationWriter::TARGET_CHANGED) {
                    $outcome = TranslationWriter::WRITTEN;
                }
            } else {
                $outcome = $writer->applyRetranslation(
                    $this->model,
                    $field,
                    $targetLocale,
                    $result->text,
                    ['locale' => $snapshot['locale'], 'text' => $snapshot['text']],
                    $staleValue,
                );
            }
        } catch (Throwable $e) {
            // The translation was billed but couldn't be saved: log its usage,
            // then fail exactly as before.
            $this->reportUnused($result, TranslationRequestBilled::UNSAVED, $field, $sourceLocale, $targetLocale);

            throw $e;
        }

        if ($outcome !== TranslationWriter::WRITTEN) {
            $this->reportUnused($result, TranslationRequestBilled::DISCARDED, $field, $sourceLocale, $targetLocale);

            if ($outcome === TranslationWriter::TARGET_CHANGED) {
                Log::info('Keeping a target that changed since the source edit (already re-translated, or edited by hand)', [
                    'model_class' => get_class($this->model),
                    'model_id' => $this->model->getKey(),
                    'field' => $field,
                    'locale' => $targetLocale,
                ]);

                // Only this pair is skipped; the rest of the field goes on.
                return null;
            }

            Log::info('TranslateFields: discarding a translation; the source text changed while it was being made', [
                'model_class' => get_class($this->model),
                'model_id' => $this->model->getKey(),
                'field' => $field,
            ]);

            return false;
        }

        TranslationCompleted::dispatch(
            get_class($this->model),
            $this->model->getKey(),
            $field,
            $sourceLocale,
            $targetLocale,
            $result,
            $pendingReview,
        );

        return true;
    }

    /**
     * Report a billed result that wasn't saved (discarded, or its save threw),
     * so usage tracking still logs its tokens. Only the result that carries a
     * request's tokens reports.
     */
    protected function reportUnused(TranslationResult $result, string $outcome, string $field, string $sourceLocale, string $targetLocale): void
    {
        if ($result->promptTokens === null && $result->completionTokens === null) {
            return;
        }

        TranslationRequestBilled::dispatch(
            $outcome,
            $result->provider,
            $result->requestId,
            $result->promptTokens,
            $result->completionTokens,
            $sourceLocale,
            [$targetLocale],
            $field,
            get_class($this->model),
            $this->model->getKey(),
        );
    }

    protected function dispatchStarted(string $field, string $sourceLocale, string $targetLocale, string $sourceText): void
    {
        TranslationStarted::dispatch(
            get_class($this->model),
            $this->model->getKey(),
            $field,
            $sourceLocale,
            $targetLocale,
            $sourceText,
        );
    }

    /**
     * Optional per-field content boundary: if the model defines
     * `shouldTranslateField(string $field, string $value): bool`, it's
     * consulted before that field's content is sent to the external API.
     * Absent a definition, every field is sent (current/default behavior) -
     * this is an opt-in hook, not a behavior change for existing consumers.
     */
    protected function shouldTranslateField(string $field, string $value): bool
    {
        if (method_exists($this->model, 'shouldTranslateField')) {
            return (bool) $this->model->shouldTranslateField($field, $value);
        }

        return true;
    }

    /**
     * The source locale this job translates from: the one captured at dispatch.
     * A job serialized before that was carried (queued before an upgrade) has
     * none, and uses TRANSLATION_SOURCE_LOCALE, then config('app.locale') -
     * deterministically, never the worker's current locale.
     */
    protected function resolvedSourceLocale(): string
    {
        if ($this->sourceLocale !== null && $this->sourceLocale !== '') {
            return $this->sourceLocale;
        }

        $fallback = TranslationJobDispatcher::defaultSourceLocale();

        Log::info('TranslateFields: job carries no source locale (queued before it was recorded); using the default', [
            'model_class' => get_class($this->model),
            'model_id' => $this->model->getKey(),
            'source_locale' => $fallback,
        ]);

        return $fallback;
    }

    /**
     * The model's class basename, passed as prompt context (e.g. "Product").
     * An anonymous class has no meaningful name of its own, so its nearest
     * named parent is used instead.
     */
    protected function modelContextName(): string
    {
        $class = new \ReflectionClass($this->model);

        while ($class->isAnonymous() && $class->getParentClass() !== false) {
            $class = $class->getParentClass();
        }

        return $class->getShortName();
    }

    /**
     * Normalize a field's current value to a [locale => text] array: passed
     * through as-is if already an array, otherwise wrapped as the single
     * value for $currentLocale.
     *
     * @param  mixed  $value  The field's current value (array or scalar).
     * @param  string  $currentLocale  Locale to key a scalar $value under.
     * @return array<string, mixed>
     */
    protected function normalizeFieldTranslations($value, string $currentLocale): array
    {
        if (is_array($value)) {
            return $value;
        }

        return [
            $currentLocale => $value,
        ];
    }
}
