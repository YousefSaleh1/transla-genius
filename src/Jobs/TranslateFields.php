<?php

namespace CodingPartners\TranslaGenius\Jobs;

use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
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
 * @see \CodingPartners\TranslaGenius\Traits\Translatable
 * @see \CodingPartners\TranslaGenius\Services\TranslationWriter
 */
class TranslateFields implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    /**
     * @var Model&\CodingPartners\TranslaGenius\Contracts\HasTranslatableAttributes
     */
    protected Model $model;

    /**
     * @var array<int, string>
     */
    protected array $fields;

    /**
     * Number of attempts before the job is considered permanently failed.
     * Transient failures (timeouts, rate limits, 5xx) get two retries beyond
     * the initial attempt via backoff() below; non-retryable failures (bad
     * config, 4xx errors other than 429) skip straight to failed() instead
     * of burning through these via TranslationFailedException::isRetryable().
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Delay (seconds) before each retry: a short initial wait, then a longer
     * one. Translation-API failures are typically either resolved within
     * seconds (a blip, a momentary rate limit) or not at all - there's little
     * value in a long backoff window for a job most consuming apps expect to
     * resolve quickly after a create/update.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30];
    }

    /**
     * Create a new job instance.
     *
     * @param Model&\CodingPartners\TranslaGenius\Contracts\HasTranslatableAttributes $model
     * @param array<int, string> $fields
     */
    public function __construct(Model $model, array $fields)
    {
        $this->model = $model;
        $this->fields = $fields;
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

            $translations = $this->prepareTranslations($translator, $currentLocale, $targetLanguages);

            if (!empty($translations)) {
                $writer->apply($this->model, $translations);

                Log::info('Translation completed successfully', [
                    'model_id' => $this->model->getKey(),
                    'model_class' => get_class($this->model),
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
    }

    /**
     * Prepare translations for the given model and fields.
     *
     * Persistence is deliberately not this method's concern - it only
     * computes the [field => [locale => text]] map; TranslationWriter
     * (called from handle()) owns the locked read-check-write that applies
     * it. See TranslationWriter for why that split matters under
     * concurrent updates.
     *
     * @param TranslatorInterface $translator
     * @param string $currentLocale The locale field values are read/translated from.
     * @param array<int, string> $targetLanguages Locales to translate into.
     * @return array<string, array<string, string>> Map of field => [locale => text].
     */
    protected function prepareTranslations(TranslatorInterface $translator, string $currentLocale, array $targetLanguages): array
    {
        $translations = [];

        foreach ($this->fields as $field) {
            $fieldTranslations = $this->normalizeFieldTranslations($this->model->getTranslations($field), $currentLocale);
            $sourceText = $fieldTranslations[$currentLocale] ?? null;

            if ($sourceText === null || !$this->shouldTranslateField($field, $sourceText)) {
                $translations[$field] = $fieldTranslations;
                continue;
            }

            foreach ($targetLanguages as $targetLanguage) {
                if (empty($fieldTranslations[$targetLanguage])) {
                    $result = $translator->translate($sourceText, $currentLocale, $targetLanguage);
                    $fieldTranslations[$targetLanguage] = $result->text;
                }
            }

            $translations[$field] = $fieldTranslations;
        }

        return $translations;
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
