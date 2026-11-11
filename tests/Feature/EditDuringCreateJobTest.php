<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Events\TranslationCompleted;
use CodingPartners\TranslaGenius\Events\TranslationRequestBilled;
use CodingPartners\TranslaGenius\Models\TranslationReview;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

/**
 * The source text is edited while the create job is translating it. The
 * create job's results for the old text must not be saved (they would look
 * like hand edits to the update job, which would then keep them for good);
 * the update job then fills those targets from the new text.
 *
 * Both jobs go through the real `database` queue and `queue:work --once`.
 */
class EditDuringCreateJobTest extends TestCase
{
    /** Runs once, inside the first provider request. */
    private ?\Closure $duringFirstRequest = null;

    /** @var array<int, TranslationCompleted> */
    private array $completed = [];

    /** @var array<int, TranslationRequestBilled> */
    private array $billed = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->useDatabaseQueue();

        Http::fake(function (Request $request) {
            if ($this->duringFirstRequest !== null) {
                $edit = $this->duringFirstRequest;
                $this->duringFirstRequest = null;
                $edit();
            }

            $system = $request['messages'][0]['content'];
            $text = $request['messages'][1]['content'];
            $usage = ['prompt_tokens' => 10, 'completion_tokens' => 5];

            if (preg_match('/into each of these languages: ([a-z, ]+)\./', $system, $m)) {
                $locales = array_map('trim', explode(',', $m[1]));
                $content = json_encode(array_combine($locales, array_map(fn ($l) => "{$text}|{$l}", $locales)));

                return Http::response(['choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']], 'usage' => $usage]);
            }

            preg_match('/ to (\w+)\./', $system, $m);

            return Http::response(['choices' => [['message' => ['content' => "{$text}|{$m[1]}"], 'finish_reason' => 'stop']], 'usage' => $usage]);
        });

        Event::listen(TranslationCompleted::class, function (TranslationCompleted $e) {
            $this->completed[] = $e;
        });
        Event::listen(TranslationRequestBilled::class, function (TranslationRequestBilled $e) {
            $this->billed[] = $e;
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('translation_reviews');

        parent::tearDown();
    }

    protected function useDatabaseQueue(): void
    {
        Schema::dropIfExists('jobs');
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        config([
            'queue.default' => 'database',
            'queue.connections.database' => [
                'driver' => 'database', 'connection' => config('database.default'), 'table' => 'jobs',
                'queue' => 'default', 'retry_after' => 90, 'after_commit' => false,
            ],
        ]);
    }

    protected function work(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1])->assertSuccessful();
    }

    protected function editName(int $id, string $text): void
    {
        TestTranslatableModel::find($id)->setTranslation('name', 'en', $text)->save();
    }

    /** @return array<string, string> */
    protected function storedName(int $id): array
    {
        $name = TestTranslatableModel::find($id)->getTranslations('name');
        ksort($name);

        return $name;
    }

    protected function createRecord(): TestTranslatableModel
    {
        $model = TestTranslatableModel::create(['name' => ['en' => 'A'], 'description' => ['en' => 'D']]);
        $this->assertSame(1, DB::table('jobs')->count());

        return $model;
    }

    /** The edit lands while the create job's first request is in flight. */
    protected function runEditDuringCreate(): TestTranslatableModel
    {
        $model = $this->createRecord();
        $this->duringFirstRequest = fn () => $this->editName($model->id, 'B');

        $this->work(); // the create job, during which the edit queues the update job
        $this->assertSame(1, DB::table('jobs')->count(), 'The edit must have queued an update job.');
        $this->work(); // the update job
        $this->assertSame(0, DB::table('jobs')->count());

        return $model;
    }

    public function test_an_edit_during_the_create_job_ends_with_the_new_texts_translations()
    {
        $model = $this->runEditDuringCreate();

        $this->assertSame(['ar' => 'B|ar', 'en' => 'B', 'es' => 'B|es'], $this->storedName($model->id));
        // The other field was untouched by the edit and is filled as usual.
        $this->assertSame('D|ar', TestTranslatableModel::find($model->id)->getTranslation('description', 'ar', false));
    }

    public function test_the_discarded_pair_is_billed_as_discarded_and_not_announced()
    {
        $this->runEditDuringCreate();

        // The create job's "A|ar" was billed but never saved.
        $discarded = array_values(array_filter($this->billed, fn ($e) => $e->outcome === TranslationRequestBilled::DISCARDED));
        $this->assertCount(1, $discarded);
        $this->assertSame('name', $discarded[0]->field);
        $this->assertSame(['ar'], $discarded[0]->targetLocales);

        $announced = array_map(fn ($e) => "{$e->field}:{$e->targetLocale}:{$e->result->text}", $this->completed);
        $this->assertNotContains('name:ar:A|ar', $announced);
        $this->assertContains('name:ar:B|ar', $announced);
        $this->assertContains('name:es:B|es', $announced);
    }

    public function test_an_edit_after_the_create_job_finished_still_re_translates()
    {
        $model = $this->createRecord();
        $this->work();
        $this->assertSame(['ar' => 'A|ar', 'en' => 'A', 'es' => 'A|es'], $this->storedName($model->id));

        $this->editName($model->id, 'B');
        $this->work();

        $this->assertSame(['ar' => 'B|ar', 'en' => 'B', 'es' => 'B|es'], $this->storedName($model->id));
    }

    public function test_with_batching_on()
    {
        config(['translagenius.batch.enabled' => true]);

        $model = $this->runEditDuringCreate();

        $this->assertSame(['ar' => 'B|ar', 'en' => 'B', 'es' => 'B|es'], $this->storedName($model->id));
    }

    public function test_with_the_cache_on()
    {
        config(['translagenius.cache.enabled' => true]);

        $model = $this->runEditDuringCreate();

        $this->assertSame(['ar' => 'B|ar', 'en' => 'B', 'es' => 'B|es'], $this->storedName($model->id));
    }

    /**
     * Review on: the stale pair is refused when proposed (the same source
     * check as a direct write), so no pending row is ever created from the
     * old text; the update job proposes the new text's translations.
     */
    public function test_with_review_on_no_pending_row_is_made_from_the_old_text()
    {
        (include __DIR__.'/../../src/database/migrations/create_translation_reviews_table.php.stub')->up();
        config(['translagenius.review.enabled' => true]);

        $model = $this->runEditDuringCreate();

        $rows = TranslationReview::query()->where('field', 'name')->orderBy('locale')->get();
        $this->assertSame(['B'], $rows->pluck('source_text')->unique()->values()->all());
        $this->assertSame(['ar' => 'B|ar', 'es' => 'B|es'], $rows->where('status', TranslationReview::PENDING)->pluck('text', 'locale')->all());
        $this->assertSame(['en' => 'B'], $this->storedName($model->id), 'Nothing is published while pending.');
    }
}
