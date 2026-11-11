<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\Events\TranslationCompleted;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
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

    /** Target locale whose single requests answer 503 (to fail an attempt). */
    private ?string $failingLocale = null;

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
                $this->requests[] = "batch {$text} ".implode(',', $locales);
                $content = json_encode(array_combine($locales, array_map(fn ($l) => "{$text}|{$l}", $locales)));
            } else {
                preg_match('/ to (\w+)\./', $system, $m);
                $this->requests[] = "single {$text} {$m[1]}";
                $content = "{$text}|{$m[1]}";

                if ($m[1] === $this->failingLocale) {
                    return Http::response(['error' => ['message' => 'unavailable']], 503);
                }
            }

            return Http::response(['choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']]]);
        });
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
        config(['translagenius.batch.enabled' => true]);
        $model = $this->createTranslated();

        $model->setTranslation('name', 'en', 'B')->save();

        $this->assertSame(['batch B ar,es'], $this->takeRequests());
        $this->assertSame(['ar' => 'B|ar', 'en' => 'B', 'es' => 'B|es'], $this->stored($model));
    }

    public function test_re_translation_with_the_cache_on()
    {
        config(['cache.default' => 'array', 'translagenius.cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 3600]]);
        Cache::store('array')->flush();
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
        $fresh->handle($this->app->make(TranslatorInterface::class), new TranslationWriter);
    }

    // --- target snapshots (retries, hand edits) ------------------------------

    protected function queuedRetranslation(TestTranslatableModel $model, string $newSource): TranslateFields
    {
        Queue::fake();
        $model->setTranslation('name', 'en', $newSource)->save();

        return Queue::pushed(TranslateFields::class)->last();
    }

    /** Run a queued job like a worker attempt; a retryable failure is swallowed (the queue would retry). */
    protected function attempt(TranslateFields $job): void
    {
        try {
            $this->runQueued($job);
        } catch (TranslationFailedException $e) {
            $this->assertTrue($e->isRetryable());
        }
    }

    public function test_a_retry_sends_no_requests_for_targets_already_overwritten()
    {
        $job = $this->queuedRetranslation($this->createTranslated(), 'B');
        $model = TestTranslatableModel::first();

        // Attempt 1: ar is re-translated and saved; es keeps failing.
        $this->failingLocale = 'es';
        $this->attempt($job);
        $this->assertSame(['single B ar', 'single B es', 'single B es'], $this->takeRequests());
        $this->assertSame(['ar' => 'B|ar', 'en' => 'B', 'es' => 'A|es'], $this->stored($model));

        // Attempt 2 (same payload): ar no longer holds its stale value - 0 requests for it.
        $this->failingLocale = null;
        $this->attempt($job);
        $this->assertSame(['single B es'], $this->takeRequests());
        $this->assertSame(['ar' => 'B|ar', 'en' => 'B', 'es' => 'B|es'], $this->stored($model));
    }

    public function test_a_hand_edit_made_after_the_source_edit_but_before_the_job_survives()
    {
        $job = $this->queuedRetranslation($this->createTranslated(), 'B');
        $model = TestTranslatableModel::first();

        // A translator fixes "ar" for the new source before the job runs.
        // (A target-only edit dispatches nothing on its own.)
        $model->setTranslation('name', 'ar', 'Hand-written AR for B')->save();
        $this->assertCount(1, Queue::pushed(TranslateFields::class));

        $this->runQueued($job);

        $this->assertSame(['single B es'], $this->takeRequests());
        $this->assertSame(['ar' => 'Hand-written AR for B', 'en' => 'B', 'es' => 'B|es'], $this->stored($model));
    }

    public function test_a_hand_edit_made_while_the_job_is_translating_survives()
    {
        $job = $this->queuedRetranslation($this->createTranslated(), 'B');
        $model = TestTranslatableModel::first();

        // While the "ar" request is in flight, someone saves a hand-written "ar".
        $this->beforeAnswer = function (Request $request) use ($model) {
            if (str_contains($request['messages'][0]['content'], ' to ar.')) {
                DB::table('test_translatable_models')->where('id', $model->id)
                    ->update(['name' => json_encode(['en' => 'B', 'ar' => 'Hand-written AR for B', 'es' => 'A|es'])]);
            }
        };

        Event::fake([TranslationCompleted::class]);
        $this->runQueued($job);

        // ar was requested but its result is not written; es carries on.
        $this->assertSame(['single B ar', 'single B es'], $this->takeRequests());
        $this->assertSame(['ar' => 'Hand-written AR for B', 'en' => 'B', 'es' => 'B|es'], $this->stored($model));
        Event::assertDispatchedTimes(TranslationCompleted::class, 1);
        Event::assertDispatched(TranslationCompleted::class, fn ($e) => $e->targetLocale === 'es');
    }

    public function test_target_snapshots_with_batching_on()
    {
        config(['translagenius.batch.enabled' => true, 'translagenius.supported_languages' => ['en', 'ar', 'es', 'fr']]);
        $model = $this->createTranslated();
        $job = $this->queuedRetranslation($model, 'B');

        // A hand edit to "fr" before the job: only ar and es are batched.
        $model->refresh()->setTranslation('name', 'fr', 'Hand FR')->save();
        $this->runQueued($job);

        $this->assertSame(['batch B ar,es'], $this->takeRequests());
        $this->assertSame(['ar' => 'B|ar', 'en' => 'B', 'es' => 'B|es', 'fr' => 'Hand FR'], $this->stored($model));

        // Running the same job again (a retry) requests nothing at all.
        $this->runQueued($job);
        $this->assertSame([], $this->takeRequests());
    }

    public function test_target_snapshots_with_the_cache_on()
    {
        config(['cache.default' => 'array', 'translagenius.cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 3600]]);
        Cache::store('array')->flush();

        // Warm the cache for "B" through another record.
        TestTranslatableModel::create(['name' => ['en' => 'B'], 'description' => ['en' => 'D2']]);
        $model = $this->createTranslated();
        $job = $this->queuedRetranslation($model, 'B');

        // Hand edit to "ar"; the cache holds a translation for it, but the
        // stale-value check keeps the hand edit.
        $model->refresh()->setTranslation('name', 'ar', 'Hand AR')->save();
        $this->runQueued($job);

        $this->assertSame([], $this->takeRequests(), 'es comes from the cache; ar is skipped.');
        $this->assertSame(['ar' => 'Hand AR', 'en' => 'B', 'es' => 'B|es'], $this->stored($model));
    }
}
