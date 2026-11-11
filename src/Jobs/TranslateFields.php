<?php

namespace CodingPartners\TranslaGenius\Jobs;

use CodingPartners\TranslaGenius\Contracts\BatchTranslatorInterface;
use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;
use CodingPartners\TranslaGenius\Events\TranslationCompleted;
use CodingPartners\TranslaGenius\Events\TranslationFailed as TranslationFailedEvent;
use CodingPartners\TranslaGenius\Events\TranslationStarted;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
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
 * @see \CodingPartners\TranslaGenius\Traits\Translatable
 * @see \CodingPartners\TranslaGenius\Services\TranslationWriter
 * @see \CodingPartners\TranslaGenius\Events\TranslationStarted
 * @see \CodingPartners\TranslaGenius\Events\TranslationCompleted
 * @see \CodingPartners\TranslaGenius\Events\TranslationFailed
 */
class TranslateFields implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var Model&\CodingPartners\TranslaGenius\Contracts\HasTranslatableAttributes
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
     * @var array<string, array{locale: string, text: string, keep: array<int, string>}>
     */
    protected array $retranslate;

    /**
     * Number of attempts before the job is considered permanently failed
     * (translaGenius.retries.job_tries / TRANSLATION_JOB_TRIES, default 3),
     * set at construction so it's captured in the queued payload. Transient
     * failures (timeouts, rate limits, 5xx) use these up via backoff();
     * non-retryable failures (bad config, 4xx other than 429, truncation)
     * skip straight to failed() via TranslationFailedException::isRetryable().
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Delay (seconds) before each retry, from translaGenius.retries.job_backoff
     * (TRANSLATION_JOB_BACKOFF, comma-separated; default "10,30"): a short
     * initial wait, then a longer one. Translation-API failures are typically
     * either resolved within seconds or not at all. A 429 with a long
     * Retry-After overrides this for that attempt - see handle().
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $configured = config('translaGenius.retries.job_backoff', '10,30');
        $values = is_array($configured) ? $configured : explode(',', (string) $configured);

        $delays = array_values(array_map(
            'intval',
            array_filter(array_map('trim', $values), fn ($value) => $value !== '')
        ));

        return $delays === [] ? [10, 30] : $delays;
    }

    /**
     * Create a new job instance.
     *
     * @param Model&\CodingPartners\TranslaGenius\Contracts\HasTranslatableAttributes $model
     * @param array<int, string> $fields
     * @param array<string, array{locale: string, text: string, keep: array<int, string>}> $retranslate
     *        Re-translation plan for fields whose source changed (see $retranslate).
     */
    public function __construct(Model $model, array $fields, array $retranslate = [])
    {
        $this->model = $model;
        $this->fields = $fields;
        $this->retranslate = $retranslate;
        $this->tries = max(1, \CodingPartners\TranslaGenius\TranslaGeniusServiceProvider::intSetting(
            config('translaGenius.retries.job_tries'),
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
     * @param TranslatorInterface $translator Resolves the actual per-field,
     *        per-locale translate() calls.
     * @param TranslationWriter $writer Persists the computed translations
     *        onto $this->model.
     * @return void
     *
     * @throws TranslationFailedException If translation fails and is
     *         retryable (propagated for the queue to retry).
     */
    public function handle(TranslatorInterface $translator, TranslationWriter $writer)
    {
        try {
            $currentLocale = get_current_locale();
            $targetLanguages = get_supported_languages();

            $saved = $this->translateMissingPairs($translator, $writer, $currentLocale, $targetLanguages);

            if ($saved > 0) {
                Log::info('Translation completed successfully', [
                    'model_id' => $this->model->getKey(),
                    'model_class' => get_class($this->model),
                    'translations_saved' => $saved,
                ]);
            }
        } catch (TranslationFailedException $e) {
            if (!$e->isRetryable()) {
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
                && !$this->job instanceof \Illuminate\Queue\Jobs\SyncJob
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
     *
     * @param \Throwable $exception
     * @return void
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
     * @param TranslatorInterface $translator
     * @param TranslationWriter $writer
     * @param string $currentLocale The locale field values are read/translated from.
     * @param array<int, string> $targetLanguages Locales to translate into.
     * @return int Number of pairs translated and saved by this call.
     */
    protected function translateMissingPairs(
        TranslatorInterface $translator,
        TranslationWriter $writer,
        string $currentLocale,
        array $targetLanguages
    ): int {
        $saved = 0;

        foreach ($this->fields as $field) {
            if (isset($this->retranslate[$field])) {
                $saved += $this->retranslateField($translator, $writer, $field, $this->retranslate[$field]);

                continue;
            }

            $fieldTranslations = $this->normalizeFieldTranslations($this->model->getTranslations($field), $currentLocale);
            $sourceText = $fieldTranslations[$currentLocale] ?? null;

            if ($sourceText === null || !$this->shouldTranslateField($field, $sourceText)) {
                continue;
            }

            $missing = array_values(array_filter(
                $targetLanguages,
                fn (string $locale) => empty($fieldTranslations[$locale])
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
     * @param TranslatorInterface $translator
     * @param TranslationWriter $writer
     * @param string $field
     * @param array{locale: string, text: string, keep: array<int, string>} $plan
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

        if (!$this->shouldTranslateField($field, $plan['text'])) {
            return 0;
        }

        $targets = array_values(array_diff(
            get_supported_languages($plan['locale']),
            (array) ($plan['keep'] ?? [])
        ));

        return $this->translateField(
            $translator,
            $writer,
            $field,
            $plan['text'],
            $plan['locale'],
            $targets,
            ['locale' => $plan['locale'], 'text' => $plan['text']],
        );
    }

    /**
     * Translate one field into its missing locales: a batch request first when
     * possible, then one request per locale still missing.
     *
     * Token accounting stays exact (see TranslationBatchResult::resultFor()):
     * a batch's token counts are carried by the first pair it completes and
     * the batch's other pairs get null. If the batch reply yielded nothing
     * usable, its billed tokens are added to this field's first pair that a
     * one-at-a-time request completes instead, so they're never lost.
     *
     * @param TranslatorInterface $translator
     * @param TranslationWriter $writer
     * @param string $field
     * @param string $sourceText
     * @param string $currentLocale
     * @param array<int, string> $missing Locales to translate, in order.
     * @param array{locale: string, text: string}|null $snapshot For a
     *        re-translation: the source it was made from. Pairs then overwrite
     *        what's stored, unless the source changed again, in which case the
     *        rest of the field is abandoned (a newer job owns it).
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
        $unusedBatchTokens = null;

        if (count($missing) > 1 && $translator instanceof BatchTranslatorInterface && $translator->canBatch()) {
            foreach ($missing as $locale) {
                $this->dispatchStarted($field, $currentLocale, $locale, $sourceText);
                $started[$locale] = true;
            }

            $batch = $translator->translateBatch($sourceText, $currentLocale, $missing, $field, $this->modelContextName());
            $tokensAttributed = false;

            foreach ($missing as $locale) {
                if (!isset($batch->translations[$locale])) {
                    continue;
                }

                // The request's tokens go to the first pair it actually
                // produced; a pair served from the cache never carries any.
                $carriesTokens = !$tokensAttributed && !$batch->isCached($locale);
                $result = $batch->resultFor($locale, $carriesTokens);
                $tokensAttributed = $tokensAttributed || $carriesTokens;

                if (!$this->savePair($writer, $field, $currentLocale, $locale, $result, $snapshot)) {
                    return $saved;
                }
                $saved++;
            }

            if (!$tokensAttributed && $batch->hasTokens()) {
                $unusedBatchTokens = [$batch->promptTokens, $batch->completionTokens];
            }

            $missing = array_values(array_filter($missing, fn ($locale) => !isset($batch->translations[$locale])));
        }

        foreach ($missing as $locale) {
            if (!isset($started[$locale])) {
                $this->dispatchStarted($field, $currentLocale, $locale, $sourceText);
            }

            $result = $translator->translate($sourceText, $currentLocale, $locale, $field, $this->modelContextName());

            if ($unusedBatchTokens !== null) {
                $result = $this->withAddedTokens($result, ...$unusedBatchTokens);
                $unusedBatchTokens = null;
            }

            if (!$this->savePair($writer, $field, $currentLocale, $locale, $result, $snapshot)) {
                return $saved;
            }
            $saved++;
        }

        return $saved;
    }

    /**
     * Save one pair, then announce it. Returns false when a re-translation
     * was discarded because the source changed again (nothing is announced).
     *
     * @param array{locale: string, text: string}|null $snapshot
     */
    protected function savePair(
        TranslationWriter $writer,
        string $field,
        string $sourceLocale,
        string $targetLocale,
        TranslationResult $result,
        ?array $snapshot = null
    ): bool {
        $written = $writer->apply(
            $this->model,
            [$field => [$targetLocale => $result->text]],
            $snapshot === null ? [] : [$field => $snapshot],
        );

        if ($snapshot !== null && $written === 0) {
            return false;
        }

        TranslationCompleted::dispatch(
            get_class($this->model),
            $this->model->getKey(),
            $field,
            $sourceLocale,
            $targetLocale,
            $result,
        );

        return true;
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
     * $result with extra token counts added (null + n = n; null + null = null).
     */
    protected function withAddedTokens(TranslationResult $result, ?int $promptTokens, ?int $completionTokens): TranslationResult
    {
        $add = fn (?int $a, ?int $b) => $a === null && $b === null ? null : (int) $a + (int) $b;

        return new TranslationResult(
            text: $result->text,
            provider: $result->provider,
            promptTokens: $add($result->promptTokens, $promptTokens),
            completionTokens: $add($result->completionTokens, $completionTokens),
            requestId: $result->requestId,
            cached: $result->cached,
        );
    }

    /**
     * Optional per-field content boundary: if the model defines
     * `shouldTranslateField(string $field, string $value): bool`, it's
     * consulted before that field's content is sent to the external API.
     * Absent a definition, every field is sent (current/default behavior) -
     * this is an opt-in hook, not a behavior change for existing consumers.
     *
     * @param string $field
     * @param string $value
     * @return bool
     */
    protected function shouldTranslateField(string $field, string $value): bool
    {
        if (method_exists($this->model, 'shouldTranslateField')) {
            return (bool) $this->model->shouldTranslateField($field, $value);
        }

        return true;
    }

    /**
     * The model's class basename, passed as prompt context (e.g. "Product").
     * An anonymous class has no meaningful name of its own, so its nearest
     * named parent is used instead.
     *
     * @return string
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
     * @param mixed $value The field's current value (array or scalar).
     * @param string $currentLocale Locale to key a scalar $value under.
     * @return array<string, string>
     */
    protected function normalizeFieldTranslations($value, string $currentLocale): array
    {
        if (is_array($value)) {
            return $value;
        }

        return [
            $currentLocale => $value
        ];
    }
}
