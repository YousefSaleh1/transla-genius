<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\Events\TranslationCompleted;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Services\Translator;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

/**
 * Editing a field's source-locale text re-translates its existing target
 * locales (they are stale), keeps target locales the same save provided,
 * and never lets an older job overwrite a newer source's translations.
 *
 * Fixture: source en, targets ar/es; the sync queue runs jobs inline unless
 * a test fakes it. The fake answers "<text>|<locale>" (or a JSON object of
 * those for a batch) and records "<single|batch> <text> <locales>".
 */
class StaleTranslationTest extends TestCase
{
    /** @var array<int, string> */
    private array $requests = [];

    /** Runs inside the fake before it answers (to simulate a concurrent edit). */
    private ?\Closure $beforeAnswer = null;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::fake(function (Request $request) {
            if ($this->beforeAnswer !== null) {
                ($this->beforeAnswer)($request);
            }

            $system = $request['messages'][0]['content'];
            $text = $request['messages'][1]['content'];

            if (preg_match('/into each of these languages: (.+?)\. Respond/', $system, $m) === 1) {
                $locales = explode(', ', $m[1]);
                $this->requests[] = "batch {$text} " . implode(',', $locales);
                $content = json_encode(array_combine($locales, array_map(fn ($l) => "{$text}|{$l}", $locales)));
            } else {
                preg_match('/ to (\w+)\./', $system, $m);
                $this->requests[] = "single {$text} {$m[1]}";
                $content = "{$text}|{$m[1]}";
            }

            return Http::response(['choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']]]);
        });
    }

    protected function reresolve(): void
    {
        $this->app->forgetInstance(TranslationProviderInterface::class);
        $this->app->forgetInstance(Translator::class);
        $this->app->forgetInstance(TranslatorInterface::class);
    }

    protected function takeRequests(): array
    {
        $requests = $this->requests;
        $this->requests = [];
        sort($requests);

        return $requests;
    }

    protected function stored(TestTranslatableModel $model, string $field = 'name'): array
    {
        $translations = TestTranslatableModel::find($model->id)->getTranslations($field);
        ksort($translations);

        return $translations;
    }

    protected function createTranslated(string $name = 'A', string $description = 'D'): TestTranslatableModel
    {
        $model = TestTranslatableModel::create(['name' => ['en' => $name], 'description' => ['en' => $description]]);
        $this->takeRequests();

        // The job wrote through its own locked copy; edit the stored record,
        // as an app loading it fresh would.
        return $model->fresh();
    }

    // --- the update flow ---------------------------------------------------

    public function test_changing_the_source_re_translates_and_overwrites_its_targets()
    {
        $model = $this->createTranslated();
        $this->assertSame(['ar' => 'A|ar', 'en' => 'A', 'es' => 'A|es'], $this->stored($model));

        $model->setTranslation('name', 'en', 'B')->save();

        $this->assertSame(['single B ar', 'single B es'], $this->takeRequests());
        $this->assertSame(['ar' => 'B|ar', 'en' => 'B', 'es' => 'B|es'], $this->stored($model));
        // The field whose source didn't change is untouched.
        $this->assertSame(['ar' => 'D|ar', 'en' => 'D', 'es' => 'D|es'], $this->stored($model, 'description'));
    }

    public function test_updating_without_changing_a_source_leaves_translations_alone()
    {
        $model = $this->createTranslated();

        // A target-only edit (a hand correction) dispatches nothing.
        $model->setTranslation('name', 'ar', 'Hand-fixed AR')->save();
        // Neither does re-saving the same source.
        $model->setTranslation('name', 'en', 'A')->save();

        $this->assertSame([], $this->takeRequests());
        $this->assertSame(['ar' => 'Hand-fixed AR', 'en' => 'A', 'es' => 'A|es'], $this->stored($model));
    }

    public function test_a_target_provided_in_the_same_save_is_kept_and_the_others_re_translated()
    {
        $model = $this->createTranslated();

        $model->setTranslation('name', 'en', 'B');
        $model->setTranslation('name', 'ar', 'Hand-written AR for B');
        $model->save();

        $this->assertSame(['single B es'], $this->takeRequests());
        $this->assertSame(['ar' => 'Hand-written AR for B', 'en' => 'B', 'es' => 'B|es'], $this->stored($model));
    }

    // --- concurrency -------------------------------------------------------

    public function test_a_job_for_an_older_source_does_nothing_once_the_source_changed_again()
    {
        $model = $this->createTranslated();
        Queue::fake();

        $model->setTranslation('name', 'en', 'B')->save();
        $model->setTranslation('name', 'en', 'C')->save();
        [$jobForB, $jobForC] = Queue::pushed(TranslateFields::class)->all();

        Event::fake([TranslationCompleted::class]);
        $this->runQueued($jobForB);

        $this->assertSame([], $this->takeRequests(), 'The stale job must not even request a translation.');
        Event::assertNotDispatched(TranslationCompleted::class);
        $this->assertSame(['ar' => 'A|ar', 'en' => 'C', 'es' => 'A|es'], $this->stored($model));

        $this->runQueued($jobForC);

        $this->assertSame(['single C ar', 'single C es'], $this->takeRequests());
        $this->assertSame(['ar' => 'C|ar', 'en' => 'C', 'es' => 'C|es'], $this->stored($model));
    }

    public function test_a_result_for_a_source_that_changed_mid_translation_is_discarded()
    {
        $model = $this->createTranslated();
        Queue::fake();

        $model->setTranslation('name', 'en', 'B')->save();
        [$jobForB] = Queue::pushed(TranslateFields::class)->all();

        // While job B is waiting on the provider, the source becomes C
        // (written directly, as another process would).
        $this->beforeAnswer = function () use ($model) {
            $this->beforeAnswer = null;
            DB::table('test_translatable_models')->where('id', $model->id)
                ->update(['name' => json_encode(['en' => 'C', 'ar' => 'A|ar', 'es' => 'A|es'])]);
        };

        Event::fake([TranslationCompleted::class]);
        $this->runQueued($jobForB);

        // B's translation came back but was discarded under the lock, and the
        // rest of the field was abandoned: 1 request, nothing written.
        $this->assertSame(['single B ar'], $this->takeRequests());
        Event::assertNotDispatched(TranslationCompleted::class);
        $this->assertSame(['ar' => 'A|ar', 'en' => 'C', 'es' => 'A|es'], $this->stored($model));
    }

    // --- with batching and the cache ---------------------------------------

    public function test_re_translation_with_batching_on()
    {
        config(['translaGenius.batch.enabled' => true]);
        $this->reresolve();
        $model = $this->createTranslated();

        $model->setTranslation('name', 'en', 'B')->save();

        $this->assertSame(['batch B ar,es'], $this->takeRequests());
        $this->assertSame(['ar' => 'B|ar', 'en' => 'B', 'es' => 'B|es'], $this->stored($model));
    }

    public function test_re_translation_with_the_cache_on()
    {
        config(['cache.default' => 'array', 'translaGenius.cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 3600]]);
        Cache::store('array')->flush();
        $this->reresolve();
        $model = $this->createTranslated();

        // New source text: a cache miss, so it's requested.
        $model->setTranslation('name', 'en', 'B')->save();
        $this->assertSame(['single B ar', 'single B es'], $this->takeRequests());
        $this->assertSame(['ar' => 'B|ar', 'en' => 'B', 'es' => 'B|es'], $this->stored($model));

        // Back to a source seen before: served from the cache, still overwritten.
        $model->refresh()->setTranslation('name', 'en', 'A')->save();
        $this->assertSame([], $this->takeRequests());
        $this->assertSame(['ar' => 'A|ar', 'en' => 'A', 'es' => 'A|es'], $this->stored($model));
    }

    // --- backfill is unchanged ---------------------------------------------

    public function test_backfill_still_fills_only_missing_locales()
    {
        $model = TestTranslatableModel::withoutEvents(fn () => TestTranslatableModel::create([
            'name' => ['en' => 'B', 'ar' => 'stale translation of A'],
            'description' => ['en' => 'D', 'ar' => 'D|ar', 'es' => 'D|es'],
        ]));

        $this->artisan('translagenius:backfill', ['model' => TestTranslatableModel::class, '--force' => true])->assertSuccessful();

        $this->assertSame(['single B es'], $this->takeRequests());
        $this->assertSame(['ar' => 'stale translation of A', 'en' => 'B', 'es' => 'B|es'], $this->stored($model));
    }

    protected function runQueued(TranslateFields $job): void
    {
        // As a worker would: the model is re-read from the database.
        $fresh = unserialize(serialize($job));
        $fresh->handle($this->app->make(TranslatorInterface::class), new TranslationWriter());
    }
}
