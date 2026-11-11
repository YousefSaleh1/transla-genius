<?php

namespace CodingPartners\TranslaGenius\Console\Commands;

use CodingPartners\TranslaGenius\Models\TranslationReview;
use CodingPartners\TranslaGenius\Review\ReviewManager;
use Illuminate\Console\Command;

/**
 * Approve every pending review of a model class (optionally one field/locale)
 * as produced - for clearing a backlog, or before switching review off.
 * Stale or conflicting reviews are skipped and reported.
 */
class ReviewApproveAllCommand extends Command
{
    protected $signature = 'translagenius:review:approve-all
        {model : Model class whose pending translations to approve}
        {--field= : Only this field}
        {--locale= : Only this target locale}
        {--force : Approve without an interactive confirmation}';

    protected $description = 'Approve all pending translations of a model as produced';

    public function handle(ReviewManager $reviews): int
    {
        $model = $this->argument('model');
        $field = $this->option('field') ?: null;
        $locale = $this->option('locale') ?: null;

        $count = TranslationReview::query()->pending()->forModel($model)
            ->when($field, fn ($q) => $q->field($field))
            ->when($locale, fn ($q) => $q->locale($locale))
            ->count();

        $this->info("{$count} pending translation(s) for [{$model}]" . ($field ? " field [{$field}]" : '') . ($locale ? " locale [{$locale}]" : '') . '.');

        if ($count === 0) {
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm("Approve and publish these {$count} translation(s) as produced?", false)) {
            $this->warn('Aborted: nothing approved.');

            return self::SUCCESS;
        }

        $summary = $reviews->approveAll($model, $field, $locale);

        $this->info("Approved {$summary['approved']}.");
        if ($summary['skipped_stale'] > 0) {
            $this->warn("Skipped {$summary['skipped_stale']} whose source text changed (now superseded).");
        }
        if ($summary['skipped_conflict'] > 0) {
            $this->warn("Skipped {$summary['skipped_conflict']} whose published text was edited since; review those individually.");
        }

        return self::SUCCESS;
    }
}
