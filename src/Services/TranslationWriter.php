<?php

namespace CodingPartners\TranslaGenius\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Persists a computed set of translations onto one model, guarding against
 * a concurrent write to the same field/locale (see TranslateFields for why
 * only this write step - not the translation calls themselves - runs under
 * a row lock).
 *
 * Knows nothing about queues, jobs, or how the translations were computed:
 * "given a model and a [field => [locale => text]] map, apply whatever
 * isn't already there." That narrow, self-contained shape is what makes it
 * usable outside the job (a manual backfill command, for instance) and
 * testable without a Translator/provider in the picture at all.
 */
class TranslationWriter
{
    /**
     * @param Model $model
     * @param array<string, array<string, string>> $translations
     * @return void
     */
    public function apply(Model $model, array $translations): void
    {
        if (empty($translations)) {
            return;
        }

        DB::transaction(function () use ($model, $translations) {
            $locked = $model->newQuery()->lockForUpdate()->find($model->getKey());

            // Model was deleted between dispatch and execution - nothing to save.
            if (!$locked) {
                return;
            }

            $locked->withoutEvents(function () use ($locked, $translations) {
                foreach ($translations as $field => $values) {
                    foreach ($values as $locale => $value) {
                        $existing = $locked->getTranslation($field, $locale, false);

                        if (empty($existing)) {
                            $locked->setTranslation($field, $locale, $value);
                        }
                    }
                }

                if ($locked->isDirty()) {
                    $locked->save();
                }
            });
        });
    }
}
