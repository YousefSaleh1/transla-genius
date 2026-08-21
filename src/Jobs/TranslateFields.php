<?php

namespace CodingPartners\TranslaGenius\Jobs;

use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use CodingPartners\TranslaGenius\Services\AutoTranslationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TranslateFields implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    protected $model;
    protected $fields;

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
     */
    public function __construct($model, $fields)
    {
        $this->model = $model;
        $this->fields = $fields;
    }

    /**
     * Execute the job.
     */
    public function handle(AutoTranslationService $translationService)
    {
        try {
            $currentLocale = get_current_locale();
            $targetLanguages = get_supported_languages();

            $translations = $this->prepareTranslations($translationService, $currentLocale, $targetLanguages);

            if (!empty($translations)) {
                $this->applyTranslations($translations);

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
     * Persist the computed translations, guarding against a concurrent job
     * (dispatched by a later update on the same model) already having
     * written some of the same field/locale values while this job's HTTP
     * calls were in flight.
     *
     * The translation calls themselves happen outside any lock/transaction
     * (see prepareTranslations() in handle()) - they're slow, external I/O,
     * and holding a row lock across them would block unrelated writes for
     * as long as the API takes to respond. Instead, only this final
     * decide-and-write step re-fetches the row under `lockForUpdate()`,
     * re-checks what's still actually missing, and writes just that -
     * so a concurrent job's newer result is never clobbered by this job's
     * possibly-stale computation.
     *
     * @param array $translations
     * @return void
     */
    protected function applyTranslations(array $translations): void
    {
        DB::transaction(function () use ($translations) {
            $model = $this->model->newQuery()->lockForUpdate()->find($this->model->getKey());

            // Model was deleted between dispatch and execution - nothing to save.
            if (!$model) {
                return;
            }

            $model->withoutEvents(function () use ($model, $translations) {
                foreach ($translations as $field => $values) {
                    foreach ($values as $locale => $value) {
                        $existing = $model->getTranslation($field, $locale, false);

                        if (empty($existing)) {
                            $model->setTranslation($field, $locale, $value);
                        }
                    }
                }

                if ($model->isDirty()) {
                    $model->save();
                }
            });
        });
    }

    /**
     * Prepare translations for the given model and fields.
     *
     * @param AutoTranslationService $translationService
     * @param string $currentLocale
     * @param array $targetLanguages
     * @return array
     */
    protected function prepareTranslations(AutoTranslationService $translationService, string $currentLocale, array $targetLanguages): array
    {
        $translations = [];

        foreach ($this->fields as $field) {
            $fieldTranslations = $this->normalizeFieldTranslations($this->model->{$field}, $currentLocale);
            $sourceText = $fieldTranslations[$currentLocale] ?? null;

            if ($sourceText === null || !$this->shouldTranslateField($field, $sourceText)) {
                $translations[$field] = $fieldTranslations;
                continue;
            }

            foreach ($targetLanguages as $targetLanguage) {
                if (empty($fieldTranslations[$targetLanguage])) {
                    $translated = $translationService->translate(
                        $sourceText,
                        $currentLocale,
                        $targetLanguage
                    );
                    $fieldTranslations[$targetLanguage] = $translated;
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
     * Normalize field translations to array format.
     *
     * @param mixed $value
     * @param string $currentLocale
     * @return array
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
