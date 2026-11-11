<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The writer's decisions, each made against the row as locked at write
 * time, never against the job's possibly stale copy of the model.
 */
class TranslationWriterLockTest extends TestCase
{
    private const SOURCE = ['locale' => 'en', 'text' => 'Hello'];

    protected function record(array $name = ['en' => 'Hello']): TestTranslatableModel
    {
        return TestTranslatableModel::withoutEvents(fn () => TestTranslatableModel::create(['name' => $name, 'description' => ['en' => '']]));
    }

    /** Change the row behind the job's back: the job's instance keeps the old values. */
    protected function concurrently(TestTranslatableModel $model, array $name): void
    {
        DB::table('test_translatable_models')->where('id', $model->id)->update(['name' => json_encode($name)]);
    }

    protected function storedName(TestTranslatableModel $model): array
    {
        return TestTranslatableModel::find($model->id)->getTranslations('name');
    }

    public function test_fill_missing_writes_a_missing_target()
    {
        $model = $this->record();

        $this->assertSame(TranslationWriter::WRITTEN, (new TranslationWriter)->fillMissing($model, 'name', 'ar', 'مرحبا', self::SOURCE));
        $this->assertSame('مرحبا', $this->storedName($model)['ar']);
    }

    public function test_fill_missing_keeps_a_target_written_concurrently()
    {
        $model = $this->record();
        $this->concurrently($model, ['en' => 'Hello', 'ar' => 'CONCURRENT']);

        $this->assertSame(TranslationWriter::TARGET_CHANGED, (new TranslationWriter)->fillMissing($model, 'name', 'ar', 'STALE', self::SOURCE));
        $this->assertSame('CONCURRENT', $this->storedName($model)['ar']);
    }

    public function test_fill_missing_discards_when_the_source_changed_concurrently()
    {
        $model = $this->record();
        $this->concurrently($model, ['en' => 'Goodbye']);

        $this->assertSame(TranslationWriter::SOURCE_CHANGED, (new TranslationWriter)->fillMissing($model, 'name', 'ar', 'مرحبا', self::SOURCE));
        $this->assertArrayNotHasKey('ar', $this->storedName($model));
    }

    public function test_fill_missing_on_a_deleted_record()
    {
        $model = $this->record();
        DB::table('test_translatable_models')->where('id', $model->id)->delete();

        $this->assertSame(TranslationWriter::MISSING, (new TranslationWriter)->fillMissing($model, 'name', 'ar', 'مرحبا', self::SOURCE));
    }

    public function test_apply_retranslation_overwrites_only_the_stale_value()
    {
        $model = $this->record(['en' => 'Hello', 'ar' => 'OLD']);
        $writer = new TranslationWriter;

        $this->assertSame(TranslationWriter::WRITTEN, $writer->applyRetranslation($model, 'name', 'ar', 'NEW', self::SOURCE, 'OLD'));
        $this->assertSame('NEW', $this->storedName($model)['ar']);

        // A hand edit since the plan was made survives.
        $this->concurrently($model, ['en' => 'Hello', 'ar' => 'BY HAND']);
        $this->assertSame(TranslationWriter::TARGET_CHANGED, $writer->applyRetranslation($model, 'name', 'ar', 'NEWER', self::SOURCE, 'NEW'));
        $this->assertSame('BY HAND', $this->storedName($model)['ar']);

        // So does a newer source.
        $this->concurrently($model, ['en' => 'Goodbye', 'ar' => 'BY HAND']);
        $this->assertSame(TranslationWriter::SOURCE_CHANGED, $writer->applyRetranslation($model, 'name', 'ar', 'NEWEST', self::SOURCE, 'BY HAND'));
    }

    /**
     * Both read the row with a lock in a transaction. SQLite's grammar
     * compiles the lock to nothing (it serializes writers itself); every
     * other driver must send it.
     */
    public function test_both_read_the_row_under_a_lock()
    {
        $model = $this->record(['en' => 'Hello', 'ar' => 'OLD']);
        $selects = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$selects) {
            if (str_starts_with(strtolower(ltrim($q->sql)), 'select')) {
                $selects[] = strtolower($q->sql);
            }
        });

        $writer = new TranslationWriter;
        $writer->fillMissing($model, 'name', 'es', 'Hola', self::SOURCE);
        $writer->applyRetranslation($model, 'name', 'ar', 'NEW', self::SOURCE, 'OLD');

        $this->assertCount(2, $selects);
        $driver = DB::connection()->getDriverName();
        foreach ($selects as $sql) {
            match ($driver) {
                'sqlite' => $this->assertStringNotContainsString('for update', $sql),
                'sqlsrv' => $this->assertStringContainsString('with(rowlock,updlock,holdlock)', str_replace(' ', '', $sql)),
                default => $this->assertStringContainsString('for update', $sql),
            };
        }
    }
}
