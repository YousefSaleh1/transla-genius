<?php

namespace CodingPartners\TranslaGenius\Console\Commands;

use CodingPartners\TranslaGenius\Models\TranslationReview;
use Illuminate\Console\Command;

/** Counts of pending reviews per model / field / locale. */
class ReviewListCommand extends Command
{
    protected $signature = 'translagenius:review:list
        {model? : Only this model class}
        {--field= : Only this field}
        {--locale= : Only this target locale}';

    protected $description = 'Show how many translations are waiting for review, per model, field and locale';

    public function handle(): int
    {
        $query = TranslationReview::query()->pending();

        if ($model = $this->argument('model')) {
            $query->forModel($model);
        }
        if ($field = $this->option('field')) {
            $query->field($field);
        }
        if ($locale = $this->option('locale')) {
            $query->locale($locale);
        }

        $rows = $query->selectRaw('translatable_type, field, locale, COUNT(*) as pending, MIN(created_at) as oldest')
            ->groupBy('translatable_type', 'field', 'locale')
            ->orderBy('translatable_type')->orderBy('field')->orderBy('locale')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No translations are waiting for review.');

            return self::SUCCESS;
        }

        $this->table(
            ['Model', 'Field', 'Locale', 'Pending', 'Oldest'],
            $rows->map(fn ($r) => [$r->translatable_type, $r->field, $r->locale, $r->pending, $r->oldest])->all()
        );
        $this->info('Total pending: '.$rows->sum('pending'));

        return self::SUCCESS;
    }
}
