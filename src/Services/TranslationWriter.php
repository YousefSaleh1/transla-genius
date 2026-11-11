<?php

namespace CodingPartners\TranslaGenius\Services;

use CodingPartners\TranslaGenius\Contracts\HasTranslatableAttributes;
use CodingPartners\TranslaGenius\Support\StoredTranslations;
use CodingPartners\TranslaGenius\Support\Text;
use CodingPartners\TranslaGenius\Traits\Translatable;
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
 * each method writes one field/locale pair and returns what happened
 * (WRITTEN, SOURCE_CHANGED, TARGET_CHANGED, MISSING), so the job can bill
 * and announce exactly what was saved.
 *
 * Type-hinting this class in a custom job's handle() (as TranslateFields
 * does) is supported; its methods are @internal and may change.
 */
class TranslationWriter
{
    public const WRITTEN = 'written';

    public const SOURCE_CHANGED = 'source_changed';

    public const TARGET_CHANGED = 'target_changed';

    public const MISSING = 'missing';

    /**
     * Fill one missing target, under the row lock, only if:
     *
     * - the stored source text still equals $source['text'], the text it was
     *   translated from (else the source was edited while this translation
     *   was being made, and the edit's own job will translate the new text:
     *   SOURCE_CHANGED). Writing it anyway would store the old text's
     *   translation where the edit's job sees a value it didn't expect, and
     *   keeps as a hand edit; and
     * - the target is still empty (else something filled it meanwhile:
     *   TARGET_CHANGED). An existing translation is never overwritten.
     *
     * @param  Model&HasTranslatableAttributes  $model
     * @param  string  $locale  Target locale.
     * @param  string  $text  The translation.
     * @param  array{locale: string, text: string}  $source  The source it was made from.
     * @return string One of WRITTEN, SOURCE_CHANGED, TARGET_CHANGED, MISSING.
     *
     * @throws InvalidArgumentException If $model doesn't use the Translatable trait.
     *
     * @internal Called by TranslateFields; not covered by the backward compatibility promise.
     */
    public function fillMissing(Model $model, string $field, string $locale, string $text, array $source): string
    {
        $this->assertTranslatable($model);

        return DB::transaction(function () use ($model, $field, $locale, $text, $source) {
            $locked = $model->newQuery()->lockForUpdate()->whereKey($model->getKey())->first();

            if (! $locked) {
                return self::MISSING;
            }

            if (StoredTranslations::get($locked, $field, $source['locale']) !== $source['text']) {
                return self::SOURCE_CHANGED;
            }

            if (! Text::isBlank(StoredTranslations::get($locked, $field, $locale))) {
                return self::TARGET_CHANGED;
            }

            $locked->withoutEvents(function () use ($locked, $field, $locale, $text) {
                $locked->setTranslation($field, $locale, $text);
                $locked->save();
            });

            return self::WRITTEN;
        });
    }

    /**
     * Overwrite one stale target of a re-translation, under the row lock, only
     * if both snapshots still hold:
     *
     * - the stored source text equals $source['text'] (else a newer edit owns
     *   the field: SOURCE_CHANGED), and
     * - the stored target hashes to $staleHash, the value it had when the plan
     *   was made (else it was already replaced - by an earlier attempt of this
     *   job - or edited by hand since: TARGET_CHANGED). Null skips this check.
     *
     * @param  Model&HasTranslatableAttributes  $model
     * @param  string  $locale  Target locale.
     * @param  string  $text  New translation.
     * @param  array{locale: string, text: string}  $source  Source snapshot.
     * @param  string|null  $staleHash  Target snapshot: Support\Text::hash() of its value then.
     * @return string One of WRITTEN, SOURCE_CHANGED, TARGET_CHANGED, MISSING.
     *
     * @throws InvalidArgumentException If $model doesn't use the Translatable trait.
     *
     * @internal Called by TranslateFields; not covered by the backward compatibility promise.
     */
    public function applyRetranslation(Model $model, string $field, string $locale, string $text, array $source, ?string $staleHash): string
    {
        $this->assertTranslatable($model);

        return DB::transaction(function () use ($model, $field, $locale, $text, $source, $staleHash) {
            $locked = $model->newQuery()->lockForUpdate()->whereKey($model->getKey())->first();

            if (! $locked) {
                return self::MISSING;
            }

            if (StoredTranslations::get($locked, $field, $source['locale']) !== $source['text']) {
                return self::SOURCE_CHANGED;
            }

            if ($staleHash !== null && Text::hash((string) StoredTranslations::get($locked, $field, $locale)) !== $staleHash) {
                return self::TARGET_CHANGED;
            }

            $locked->withoutEvents(function () use ($locked, $field, $locale, $text) {
                $locked->setTranslation($field, $locale, $text);
                $locked->save();
            });

            return self::WRITTEN;
        });
    }

    /**
     * PHP's type system can't require a trait, so the precondition is
     * checked here, before any query, with a clear message instead of the
     * BadMethodCallException that would otherwise surface inside the
     * transaction.
     *
     * @throws InvalidArgumentException
     */
    private function assertTranslatable(Model $model): void
    {
        if (! method_exists($model, 'getTranslation') || ! method_exists($model, 'setTranslation')) {
            throw new InvalidArgumentException(sprintf(
                '%s must use the %s trait (getTranslation()/setTranslation() are missing).',
                get_class($model),
                Translatable::class
            ));
        }
    }
}
