<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;
use CodingPartners\TranslaGenius\Events\TranslationRequestBilled;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\ScopedTranslatableModel;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * A record that is gone (deleted, soft-deleted, or hidden by a global
 * scope) is not an error: a job queued for it is dropped, and a
 * translation made while it disappeared is billed as `missing`.
 */
class MissingRecordTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('failed_jobs');
        ScopedTranslatableModel::$hiddenIds = [];

        parent::tearDown();
    }

    public function test_a_record_deleted_before_its_job_runs_leaves_no_failed_job()
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        config([
            'queue.default' => 'database',
            'queue.failed' => ['driver' => 'database-uuids', 'database' => config('database.default'), 'table' => 'failed_jobs'],
            'queue.connections.database' => ['driver' => 'database', 'connection' => config('database.default'), 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 900, 'after_commit' => false],
        ]);

        $model = TestTranslatableModel::create(['name' => ['en' => 'Hello'], 'description' => ['en' => '']]);
        DB::table('test_translatable_models')->where('id', $model->id)->delete();

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 3]);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_a_translation_for_a_row_hidden_meanwhile_is_billed_as_missing_with_its_own_log_line()
    {
        $billed = [];
        Event::listen(TranslationRequestBilled::class, function (TranslationRequestBilled $e) use (&$billed) {
            $billed[] = $e->outcome;
        });
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logs) {
            $logs[] = $e->message;
        });

        $model = ScopedTranslatableModel::withoutEvents(fn () => ScopedTranslatableModel::create(['name' => ['en' => 'Hello'], 'description' => ['en' => '']]));
        Http::fake(function (Request $request) use ($model) {
            ScopedTranslatableModel::$hiddenIds = [$model->id]; // soft-deleted, or another tenant's, while translating

            return Http::response(['choices' => [['message' => ['content' => 'مرحبا'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2]]);
        });

        (new TranslateFields(new TranslationJobPayload($model, ['name'], 'en')))
            ->handle($this->app->make(TranslatorInterface::class), $this->app->make(TranslationWriter::class));

        $this->assertSame([TranslationRequestBilled::MISSING], $billed);
        $this->assertNotEmpty(array_filter($logs, fn ($m) => str_contains($m, 'the record is gone')));
        $this->assertEmpty(array_filter($logs, fn ($m) => str_contains($m, 'source text changed')));
        Http::assertSentCount(1); // the rest of the field isn't requested
    }
}
