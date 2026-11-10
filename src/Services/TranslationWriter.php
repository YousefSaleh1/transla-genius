<?php

namespace CodingPartners\TranslaGenius\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Persists a computed set of translations onto one model, guarding against
 * a concurrent write to the same field/locale (see TranslateFields for why
 * only this write step - not the translation calls themselves - runs under
 * a row lock).
 *
 * That row lock (lockForUpdate()) is a genuine, working mutual-exclusion
 * mechanism on MySQL, MariaDB, PostgreSQL, and SQL Server. On SQLite it
 * compiles to nothing at all - SQLiteGrammar::compileLock() returns an
 * empty string unconditionally. The concurrent-write protection still
 * holds there, but for a different reason: SQLite serializes all writers
 * at the database-file/WAL level regardless of query-level lock hints, so
 * the race this class exists to prevent can't structurally occur on
 * SQLite in the first place. Don't read "works on every database" as "the
 * row lock does the work everywhere" - it doesn't, on SQLite specifically.
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
     * Persist $translations onto $model, skipping any field/locale that
     * already has a value (never overwrites an existing translation).
     *
     * @param Model&\CodingPartners\TranslaGenius\Contracts\HasTranslatableAttributes $model
     * @param array<string, array<string, string>> $translations Map of
     *        field => [locale => text] to apply.
     * @return void
     *
     * @throws InvalidArgumentException if $model doesn't use the
     *         Translatable trait (or otherwise doesn't implement
     *         getTranslation()/setTranslation()) - PHP's type system can't
     *         express this precondition on a trait, so it's enforced here
     *         instead, with a clear message rather than the confusing
     *         BadMethodCallException that would otherwise surface deep
     *         inside the transaction below.
     */
    public function apply(Model $model, array $translations): void
    {
        if (empty($translations)) {
            return;
        }

        if (!method_exists($model, 'getTranslation') || !method_exists($model, 'setTranslation')) {
            throw new InvalidArgumentException(sprintf(
                '%s must use the %s trait (getTranslation()/setTranslation() are missing).',
                get_class($model),
                \CodingPartners\TranslaGenius\Traits\Translatable::class
            ));
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
