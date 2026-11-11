<?php

namespace CodingPartners\TranslaGenius\Services;

use CodingPartners\TranslaGenius\Contracts\HasTranslatableAttributes;
use CodingPartners\TranslaGenius\Traits\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     * Persist $translations onto $model.
     *
     * Without $sourceSnapshots (create, backfill, filling gaps): a pair that
     * already has a value is skipped - an existing translation is never
     * overwritten.
     *
     * With a snapshot for a field (re-translation after its source text
     * changed): that field's pairs overwrite what's stored, but only if, under
     * the row lock, the stored source text still equals the snapshot the
     * translation was made from. If the source changed again in the meantime,
     * a newer job owns the field, so this field's pairs are discarded.
     *
     * @param  Model&HasTranslatableAttributes  $model
     * @param  array<string, array<string, string>>  $translations  Map of
     *                                                              field => [locale => text] to apply.
     * @param  array<string, array{locale: string, text: string}>  $sourceSnapshots
     *                                                                               field => the source locale and text its translations were made from.
     * @return int Number of pairs written.
     *
     * @throws InvalidArgumentException if $model doesn't use the
     *                                  Translatable trait (or otherwise doesn't implement
     *                                  getTranslation()/setTranslation()) - PHP's type system can't
     *                                  express this precondition on a trait, so it's enforced here
     *                                  instead, with a clear message rather than the confusing
     *                                  BadMethodCallException that would otherwise surface deep
     *                                  inside the transaction below.
     */
    public function apply(Model $model, array $translations, array $sourceSnapshots = []): int
    {
        if (empty($translations)) {
            return 0;
        }

        if (! method_exists($model, 'getTranslation') || ! method_exists($model, 'setTranslation')) {
            throw new InvalidArgumentException(sprintf(
                '%s must use the %s trait (getTranslation()/setTranslation() are missing).',
                get_class($model),
                Translatable::class
            ));
        }

        return DB::transaction(function () use ($model, $translations, $sourceSnapshots) {
            $locked = $model->newQuery()->lockForUpdate()->find($model->getKey());

            // Model was deleted between dispatch and execution - nothing to save.
            if (! $locked) {
                return 0;
            }

            $written = 0;

            $locked->withoutEvents(function () use ($locked, $translations, $sourceSnapshots, &$written) {
                foreach ($translations as $field => $values) {
                    $snapshot = $sourceSnapshots[$field] ?? null;

                    if ($snapshot !== null
                        && $locked->getTranslation($field, $snapshot['locale'], false) !== $snapshot['text']) {
                        Log::info('TranslationWriter: discarding a re-translation; the source text changed again', [
                            'model_class' => get_class($locked),
                            'model_id' => $locked->getKey(),
                            'field' => $field,
                        ]);

                        continue;
                    }

                    foreach ($values as $locale => $value) {
                        if ($snapshot !== null || empty($locked->getTranslation($field, $locale, false))) {
                            $locked->setTranslation($field, $locale, $value);
                            $written++;
                        }
                    }
                }

                if ($locked->isDirty()) {
                    $locked->save();
                }
            });

            return $written;
        });
    }

    public const WRITTEN = 'written';

    public const SOURCE_CHANGED = 'source_changed';

    public const TARGET_CHANGED = 'target_changed';

    public const MISSING = 'missing';

    /**
     * Overwrite one stale target of a re-translation, under the row lock, only
     * if both snapshots still hold:
     *
     * - the stored source text equals $source['text'] (else a newer edit owns
     *   the field: SOURCE_CHANGED), and
     * - the stored target equals $staleValue, the value it had when the plan
     *   was made (else it was already replaced - by an earlier attempt of this
     *   job - or edited by hand since: TARGET_CHANGED). Null skips this check.
     *
     * @param  Model&HasTranslatableAttributes  $model
     * @param  string  $locale  Target locale.
     * @param  string  $text  New translation.
     * @param  array{locale: string, text: string}  $source  Source snapshot.
     * @param  string|null  $staleValue  Target snapshot.
     * @return string One of WRITTEN, SOURCE_CHANGED, TARGET_CHANGED, MISSING.
     */
    public function applyRetranslation(Model $model, string $field, string $locale, string $text, array $source, ?string $staleValue): string
    {
        return DB::transaction(function () use ($model, $field, $locale, $text, $source, $staleValue) {
            $locked = $model->newQuery()->lockForUpdate()->find($model->getKey());

            if (! $locked) {
                return self::MISSING;
            }

            if ($locked->getTranslation($field, $source['locale'], false) !== $source['text']) {
                return self::SOURCE_CHANGED;
            }

            if ($staleValue !== null && (string) $locked->getTranslation($field, $locale, false) !== $staleValue) {
                return self::TARGET_CHANGED;
            }

            $locked->withoutEvents(function () use ($locked, $field, $locale, $text) {
                $locked->setTranslation($field, $locale, $text);
                $locked->save();
            });

            return self::WRITTEN;
        });
    }
}
