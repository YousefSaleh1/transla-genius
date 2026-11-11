<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class IndexesAndLogLevelsTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('translation_reviews');
        Schema::dropIfExists('translation_usage_logs');

        parent::tearDown();
    }

    /** @return array<int, array<int, string>> each index's columns */
    protected function indexColumns(string $table): array
    {
        return array_map(fn (array $index) => $index['columns'], Schema::getIndexes($table));
    }

    /** pendingReview() and the review queue filter by type and status, then join on the owner key. */
    public function test_the_review_table_indexes_type_status_and_owner()
    {
        (include __DIR__.'/../../src/database/migrations/create_translation_reviews_table.php.stub')->up();

        $this->assertContains(['translatable_type', 'status', 'translatable_id'], $this->indexColumns('translation_reviews'));
    }

    /** Spend reports filter the usage log by date. */
    public function test_the_usage_table_indexes_created_at()
    {
        (include __DIR__.'/../../src/database/migrations/create_translation_usage_logs_table.php.stub')->up();

        $this->assertContains(['created_at'], $this->indexColumns('translation_usage_logs'));
    }

    /** A routine, successful job logs nothing at info level or above: per-call and per-job lines are debug. */
    public function test_a_successful_job_logs_only_at_debug()
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'مرحبا'], 'finish_reason' => 'stop']]])]);
        $levels = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$levels) {
            $levels[] = "{$e->level}: {$e->message}";
        });

        $model = TestTranslatableModel::withoutEvents(fn () => TestTranslatableModel::create(['name' => ['en' => 'Hello'], 'description' => ['en' => '']]));
        (new TranslateFields(new TranslationJobPayload($model, ['name'], 'en')))
            ->handle($this->app->make(TranslatorInterface::class), $this->app->make(TranslationWriter::class));

        $this->assertNotEmpty($levels);
        $this->assertSame([], array_values(array_filter($levels, fn ($line) => ! str_starts_with($line, 'debug: '))));
    }
}
