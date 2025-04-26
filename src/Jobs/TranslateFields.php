<?php

namespace CodingPartners\TranslaGenius\Jobs;

use CodingPartners\TranslaGenius\Services\AutoTranslationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TranslateFields implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    protected $model;
    protected $fields;

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
                $this->model->withoutEvents(function () use ($translations) {
                    foreach ($translations as $field => $values) {
                        foreach ($values as $locale => $value) {
                            $this->model->setTranslation($field, $locale, $value);
                        }
                    }
                    $this->model->save();
                });

                Log::info('Translation completed successfully', [
                    'model_id' => $this->model->id,
                    'model_class' => get_class($this->model),
                ]);
            }
        } catch (\Throwable $th) {
            Log::error('Translation job failed', [
                'error' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
                'model_class' => get_class($this->model),
                'model_id' => $this->model->id ?? null,
            ]);
        }
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

            foreach ($targetLanguages as $targetLanguage) {
                if (empty($fieldTranslations[$targetLanguage])) {
                    $translated = $translationService->translate(
                        $fieldTranslations[$currentLocale],
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
