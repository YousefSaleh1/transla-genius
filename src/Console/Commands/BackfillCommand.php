<?php

namespace CodingPartners\TranslaGenius\Console\Commands;

use CodingPartners\TranslaGenius\Console\Concerns\ReadsStringInput;
use CodingPartners\TranslaGenius\Contracts\HasTranslatableAttributes;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Support\Locales;
use CodingPartners\TranslaGenius\Support\StoredTranslations;
use CodingPartners\TranslaGenius\Support\Text;
use CodingPartners\TranslaGenius\Support\TranslationJobDispatcher;
use CodingPartners\TranslaGenius\Traits\Translatable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Finds existing records that aren't fully translated and dispatches the
 * same job - with the same field list - that the Translatable trait's own
 * `created`/`updated` listeners would dispatch (see
 * Traits\Translatable::bootTranslatable()). This command reuses that job
 * exactly rather than reimplementing any translation-decision logic:
 * everything about which locales get translated, which fields are skipped
 * via shouldTranslateField(), and which lifecycle events fire, is entirely
 * that job's own concern, unchanged.
 *
 * Resolves which job class to dispatch via
 * Traits\Translatable::getTranslationJobClass() - the public accessor for
 * the trait's `protected` translationJobClass() extension point - so a
 * model overriding it gets its own job dispatched here exactly
 * as it would from the automatic save-triggered flow, not silently
 * downgraded to the default TranslateFields.
 *
 * No target-locale filtering option is offered: TranslateFields always
 * translates into every locale from translagenius.supported_languages
 * (already-present field/locale pairs are skipped internally, at no API
 * cost - see TranslateFields::translateMissingPairs()), and that target set
 * isn't parameterized per-dispatch. A `--locales=` option here could only
 * ever narrow which *records* this command considers "not fully
 * translated", not which locales the dispatched job actually attempts for
 * them - a record matching the filter for a missing 'fr' but also missing
 * 'es' would still get 'es' translated too, silently exceeding what
 * `--locales=fr` implied. Implementing it correctly would mean either
 * mutating global config per-dispatch (unsafe once jobs may run on a real
 * queue, after this command has already moved on and reverted it) or
 * changing TranslateFields itself, both out of scope here. Simpler and
 * honest: this command always targets the model's full configured
 * language set, exactly like the automatic flow does.
 *
 * @see Translatable::scopeNotFullyTranslated()
 * @see Translatable::getTranslationJobClass()
 * @see TranslateFields
 */
class BackfillCommand extends Command
{
    use ReadsStringInput;

    protected $signature = 'translagenius:backfill
        {model : Fully-qualified Eloquent model class name that uses the Translatable trait}
        {--chunk=100 : Number of records to load per chunk via chunkById()}
        {--force : Dispatch without an interactive confirmation prompt - required for non-interactive/CI use}
        {--dry-run : Only report how many records/calls would be affected; dispatch nothing}
        {--source= : Locale the content is authored in (default: TRANSLATION_SOURCE_LOCALE, then app.locale)}';

    protected $description = "Dispatch the model's translation job for existing records that are missing translations";

    /**
     * Validate {model}, count and report the affected records/estimated
     * API calls, then - unless --dry-run - require confirmation (--force,
     * or an interactive prompt) before chunking through matching records
     * and dispatching the model's configured translation job
     * (Traits\Translatable::getTranslationJobClass() - TranslateFields
     * unless overridden) for each.
     *
     * @return int self::SUCCESS on a clean run (including an aborted or
     *             dry-run one), self::FAILURE if {model} is invalid or
     *             --chunk isn't a positive integer.
     */
    public function handle(): int
    {
        $modelClass = $this->stringArgument('model') ?? '';

        $validationError = $this->validateModelClass($modelClass);

        if ($validationError !== null) {
            $this->error($validationError);

            return self::FAILURE;
        }

        $chunkSize = (int) $this->option('chunk');

        if ($chunkSize < 1) {
            $this->error('--chunk must be a positive integer.');

            return self::FAILURE;
        }

        /** @var Model&HasTranslatableAttributes $modelInstance validateModelClass() checked it extends Model and uses Translatable. */
        $modelInstance = new $modelClass;
        $translatableFields = $modelInstance->getTranslatableAttributes();

        if (empty($translatableFields)) {
            $this->warn("[{$modelClass}] has no translatable fields configured (no \$translatable property or #[Translatable] attribute) - nothing to backfill.");

            return self::SUCCESS;
        }

        $jobClass = $modelInstance::getTranslationJobClass();
        $jobClassLabel = class_basename($jobClass);

        // There's no request here, so the source locale is explicit:
        // --source, then TRANSLATION_SOURCE_LOCALE, then app.locale.
        $sourceLocale = $this->stringOption('source') ?? TranslationJobDispatcher::defaultSourceLocale();
        $targetLocales = Locales::targets($sourceLocale);

        $this->line("Source locale: {$sourceLocale}; target locale(s): ".implode(', ', $targetLocales));

        $buildQuery = fn () => $modelInstance->newQuery()->scopes(['notFullyTranslated' => [$sourceLocale]]);

        // A record whose every translatable field is blank in the source
        // locale has nothing to translate from; its job would request
        // nothing. notFullyTranslated() matches it all the same (its targets
        // are missing too), so it is filtered out here, per chunk: "blank"
        // means empty after trim() (Support\Text::isBlank()), which needs a
        // per-driver raw JSON expression to say in SQL on five drivers. The
        // same filter runs in the counting pass and the dispatch pass, so
        // the count shown is what gets dispatched.
        $hasSourceText = fn ($record): bool => $this->hasSourceText($record, $translatableFields, $sourceLocale);

        $matchingCount = 0;
        $skippedCount = 0;
        $buildQuery()->chunkById($chunkSize, function ($records) use (&$matchingCount, &$skippedCount, $hasSourceText) {
            foreach ($records as $record) {
                $hasSourceText($record) ? $matchingCount++ : $skippedCount++;
            }
        });

        $this->info("Found {$matchingCount} [{$modelClass}] record(s) not fully translated.");

        if ($skippedCount > 0) {
            $this->line("Skipped {$skippedCount} record(s) with no [{$sourceLocale}] text to translate from.");
        }

        if ($matchingCount === 0) {
            return self::SUCCESS;
        }

        $estimatedCalls = $matchingCount * count($translatableFields) * count($targetLocales);
        $this->line(
            "Up to {$estimatedCalls} translation API call(s) may result (records x fields x locales - "
            .'field/locale pairs already translated are skipped automatically, at no API cost).'
        );

        if ($this->option('dry-run')) {
            $this->info('Dry run: no jobs dispatched.');

            return self::SUCCESS;
        }

        $confirmed = $this->option('force')
            || $this->confirm("Dispatch {$jobClassLabel} for these {$matchingCount} record(s), translating from [{$sourceLocale}]?", false);

        if (! $confirmed) {
            $this->warn('Aborted: no jobs dispatched.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($matchingCount);
        $bar->start();

        $dispatched = 0;

        $buildQuery()->chunkById($chunkSize, function ($records) use (&$dispatched, $bar, $jobClass, $sourceLocale, $hasSourceText) {
            foreach ($records as $record) {
                if (! $hasSourceText($record)) {
                    continue;
                }

                // With review on, pairs awaiting review are skipped by the job,
                // and pairs that were rejected are requested again.
                TranslationJobDispatcher::dispatch($jobClass, $record, $record->getTranslatableAttributes(), [], $sourceLocale, ['include_rejected' => true]);
                $dispatched++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Dispatched {$jobClassLabel} for {$dispatched} record(s).");

        return self::SUCCESS;
    }

    /**
     * Whether any translatable field of $record has text in $sourceLocale.
     *
     * @param  Model&HasTranslatableAttributes  $record
     * @param  array<int, string>  $translatableFields
     */
    protected function hasSourceText(Model $record, array $translatableFields, string $sourceLocale): bool
    {
        foreach ($translatableFields as $field) {
            if (! Text::isBlank(StoredTranslations::get($record, $field, $sourceLocale))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check that $modelClass is a usable target: it must exist, extend
     * Eloquent's Model, and use the Translatable trait - checked up front
     * with a clear, actionable message rather than failing deep inside the
     * chunk loop (or query builder) with a cryptic error.
     *
     * @return string|null An error message if invalid, null if valid.
     */
    protected function validateModelClass(string $modelClass): ?string
    {
        if (! class_exists($modelClass)) {
            return "Class [{$modelClass}] does not exist.";
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            return "[{$modelClass}] is not an Eloquent model (must extend ".Model::class.').';
        }

        if (! in_array(Translatable::class, class_uses_recursive($modelClass), true)) {
            return "[{$modelClass}] does not use the ".Translatable::class.' trait.';
        }

        return null;
    }
}
