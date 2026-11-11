<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Events\TranslationApproved;
use CodingPartners\TranslaGenius\Events\TranslationCompleted;
use CodingPartners\TranslaGenius\Events\TranslationPendingReview;
use CodingPartners\TranslaGenius\Events\TranslationRejected;
use CodingPartners\TranslaGenius\Events\TranslationSuperseded;
use CodingPartners\TranslaGenius\Exceptions\InvalidReviewStateException;
use CodingPartners\TranslaGenius\Exceptions\ReviewConflictException;
use CodingPartners\TranslaGenius\Exceptions\StaleReviewException;
use CodingPartners\TranslaGenius\Facades\TranslaGenius;
use CodingPartners\TranslaGenius\Models\TranslationReview;
use CodingPartners\TranslaGenius\Models\TranslationUsageLog;
use CodingPartners\TranslaGenius\Providers\FallbackProvider;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use CodingPartners\TranslaGenius\Services\Translator;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

/**
 * The review workflow (TRANSLATION_REVIEW) through the real job flow: the
 * sync queue runs jobs inline, the provider is faked ("<text>|<locale>", or a
 * JSON object of those for a batch) and every request is recorded.
 *
 * Fixture: source en, targets ar/es; fields name and description.
 */
class ReviewWorkflowTest extends TestCase
{
    /** @var array<int, string> */
    private array $requests = [];

    /** @var array<int, array{prompt: int, completion: int}> */
    private array $billed = [];

    /** host => status to fail with */
    private array $failingHosts = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        (include __DIR__.'/../../src/database/migrations/create_translation_reviews_table.php.stub')->up();

        config([
            'translagenius.review' => [
                'enabled' => true,
                'models' => [],
                'fields' => [],
                'locales' => [],
                'while_pending' => 'keep',
                'while_pending_overrides' => [],
                'on_reject' => 'leave',
            ],
        ]);

        Http::fake(function (Request $request) {
            $host = parse_url($request->url(), PHP_URL_HOST);
            $system = $request['messages'][0]['content'];
            $text = $request['messages'][1]['content'];

            if (isset($this->failingHosts[$host])) {
                $this->requests[] = "{$host} failed";

                return Http::response(['error' => ['message' => 'down']], $this->failingHosts[$host]);
            }

            if (preg_match('/into each of these languages: (.+?)\. Respond/', $system, $m) === 1) {
                $locales = explode(', ', $m[1]);
                $this->requests[] = "batch {$text} ".implode(',', $locales);
                $content = json_encode(array_combine($locales, array_map(fn ($l) => "{$text}|{$l}", $locales)));
            } else {
                preg_match('/ to (\w+)\./', $system, $m);
                $this->requests[] = "single {$text} {$m[1]}";
                $content = "{$text}|{$m[1]}";
            }

            $usage = ['prompt_tokens' => 20 + count($this->billed), 'completion_tokens' => 5 + count($this->billed)];
            $this->billed[] = ['prompt' => $usage['prompt_tokens'], 'completion' => $usage['completion_tokens']];

            return Http::response(['choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']], 'usage' => $usage]);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('translation_reviews');
        Schema::dropIfExists('translation_usage_logs');

        parent::tearDown();
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

    protected function review(TestTranslatableModel $model, string $field, string $locale, string $status = 'pending'): TranslationReview
    {
        return TranslationReview::query()->for($model)->field($field)->locale($locale)->where('status', $status)->latest('id')->firstOrFail();
    }

    protected function statuses(): array
    {
        return TranslationReview::orderBy('id')->get()->map(fn ($r) => "{$r->field}.{$r->locale} {$r->status} {$r->text}")->all();
    }

    protected function createRecord(string $name = 'A', string $description = 'D'): TestTranslatableModel
    {
        $model = TestTranslatableModel::create(['name' => ['en' => $name], 'description' => ['en' => $description]]);

        return $model->fresh();
    }

    // --- lifecycle -------------------------------------------------------------

    public function test_produced_translations_are_pending_not_published()
    {
        Event::fake([TranslationCompleted::class, TranslationPendingReview::class]);

        $model = $this->createRecord();

        $this->assertSame(['single A ar', 'single A es', 'single D ar', 'single D es'], $this->takeRequests());
        $this->assertSame(['en' => 'A'], $this->stored($model));
        $this->assertSame(4, TranslationReview::pending()->count());
        $this->assertSame('A|ar', $this->review($model, 'name', 'ar')->text);
        $this->assertSame('', $this->review($model, 'name', 'ar')->published_snapshot);

        Event::assertDispatchedTimes(TranslationCompleted::class, 4);
        $this->assertCount(4, Event::dispatched(TranslationCompleted::class, fn ($e) => $e->pendingReview === true));
        Event::assertDispatchedTimes(TranslationPendingReview::class, 4);

        $this->assertTrue(TestTranslatableModel::pendingReview()->whereKey($model->id)->exists());
        $this->assertTrue(TestTranslatableModel::pendingReview('name', 'ar')->whereKey($model->id)->exists());
        $this->assertFalse(TestTranslatableModel::fullyTranslated()->whereKey($model->id)->exists());
    }

    public function test_pending_to_approved()
    {
        $model = $this->createRecord();
        $reviewer = TestTranslatableModel::withoutEvents(fn () => TestTranslatableModel::create(['name' => ['en' => 'reviewer'], 'description' => ['en' => '']]));
        Event::fake([TranslationApproved::class]);

        $review = TranslaGenius::reviews()->approve($this->review($model, 'name', 'ar'), reviewer: $reviewer);

        $this->assertSame('approved', $review->status);
        $this->assertNull($review->approved_text);
        $this->assertSame((string) $reviewer->id, $review->reviewer_id);
        $this->assertSame($reviewer->getMorphClass(), $review->reviewer_type);
        $this->assertNotNull($review->reviewed_at);
        $this->assertSame(['ar' => 'A|ar', 'en' => 'A'], $this->stored($model));
        Event::assertDispatched(TranslationApproved::class, fn ($e) => $e->edited === false && $e->reviewer->is($reviewer));
    }

    public function test_pending_to_approved_with_edit()
    {
        $model = $this->createRecord();
        Event::fake([TranslationApproved::class]);

        $review = TranslaGenius::reviews()->approve($this->review($model, 'name', 'ar'), editedText: 'Better AR', note: 'tone');

        $this->assertSame('approved', $review->status);
        $this->assertSame('A|ar', $review->text);
        $this->assertSame('Better AR', $review->approved_text);
        $this->assertSame('tone', $review->note);
        $this->assertSame(['ar' => 'Better AR', 'en' => 'A'], $this->stored($model));
        Event::assertDispatched(TranslationApproved::class, fn ($e) => $e->edited === true);
    }

    public function test_pending_to_rejected_leaves_the_locale_missing_until_retranslate()
    {
        $model = $this->createRecord();
        Event::fake([TranslationRejected::class]);

        $review = TranslaGenius::reviews()->reject($this->review($model, 'name', 'ar'), note: 'wrong');
        $this->takeRequests();

        $this->assertSame('rejected', $review->status);
        $this->assertSame('wrong', $review->note);
        $this->assertSame(['en' => 'A'], $this->stored($model));
        Event::assertDispatched(TranslationRejected::class, fn ($e) => $e->note === 'wrong');

        // A later save that dispatches a job (another field's source changed)
        // doesn't re-request the rejected pair.
        $model->refresh()->setTranslation('description', 'en', 'D2')->save();
        $this->assertSame(['single D2 ar', 'single D2 es'], $this->takeRequests());

        // An explicit retranslate() does - and it becomes a new pending review.
        TranslaGenius::reviews()->retranslate($review);
        $this->assertSame(['single A ar'], $this->takeRequests());
        $this->assertSame('A|ar', $this->review($model, 'name', 'ar')->text);
    }

    public function test_on_reject_retranslate_requests_a_fresh_translation_automatically()
    {
        config(['translagenius.review.on_reject' => 'retranslate']);
        $model = $this->createRecord();
        $this->takeRequests();

        TranslaGenius::reviews()->reject($this->review($model, 'name', 'ar'));

        $this->assertSame(['single A ar'], $this->takeRequests());
        $this->assertSame(1, TranslationReview::query()->for($model)->field('name')->locale('ar')->pending()->count());
    }

    public function test_source_change_while_pending_supersedes_and_produces_a_new_pending_row()
    {
        $model = $this->createRecord();
        $old = $this->review($model, 'name', 'ar');
        Event::fake([TranslationSuperseded::class, TranslationPendingReview::class]);

        $model->setTranslation('name', 'en', 'B')->save();

        $this->assertSame('superseded', $old->fresh()->status);
        $this->assertSame('B|ar', $this->review($model, 'name', 'ar')->text);
        Event::assertDispatched(TranslationSuperseded::class, fn ($e) => $e->review->id === $old->id && $e->reason === 'source_changed');
        Event::assertDispatchedTimes(TranslationPendingReview::class, 2);

        $this->expectException(InvalidReviewStateException::class);
        TranslaGenius::reviews()->approve($old);
    }

    // --- visibility on the re-translation path ----------------------------------

    protected function publishedRecord(): TestTranslatableModel
    {
        $model = $this->createRecord();
        TranslaGenius::reviews()->approveAll($model);
        $this->takeRequests();

        return $model->fresh();
    }

    public function test_visibility_keep_shows_the_stale_text_until_approval()
    {
        $model = $this->publishedRecord();
        $this->assertSame(['ar' => 'A|ar', 'en' => 'A', 'es' => 'A|es'], $this->stored($model));

        $model->setTranslation('name', 'en', 'B')->save();

        $this->assertSame(['single B ar', 'single B es'], $this->takeRequests());
        $this->assertSame(['ar' => 'A|ar', 'en' => 'B', 'es' => 'A|es'], $this->stored($model));

        TranslaGenius::reviews()->approve($this->review($model, 'name', 'ar'));
        $this->assertSame(['ar' => 'B|ar', 'en' => 'B', 'es' => 'A|es'], $this->stored($model));
    }

    public function test_visibility_clear_removes_the_stale_text_until_approval()
    {
        config(['translagenius.review.while_pending' => 'clear']);
        $model = $this->publishedRecord();

        $model->setTranslation('name', 'en', 'B')->save();

        $this->assertSame(['en' => 'B'], $this->stored($model));
        $this->assertSame('', $this->review($model, 'name', 'ar')->published_snapshot);

        TranslaGenius::reviews()->approve($this->review($model, 'name', 'ar'));
        $this->assertSame(['ar' => 'B|ar', 'en' => 'B'], $this->stored($model));
    }

    public function test_visibility_can_be_overridden_per_model_field()
    {
        config(['translagenius.review.while_pending_overrides' => [TestTranslatableModel::class => ['description' => 'clear']]]);
        $model = $this->publishedRecord();

        $model->setTranslation('name', 'en', 'B');
        $model->setTranslation('description', 'en', 'E');
        $model->save();

        $this->assertSame(['ar' => 'A|ar', 'en' => 'B', 'es' => 'A|es'], $this->stored($model));   // keep
        $this->assertSame(['en' => 'E'], $this->stored($model, 'description'));                    // clear
    }

    // --- stale approvals ---------------------------------------------------------

    public function test_approving_after_the_source_changed_supersedes_and_publishes_nothing()
    {
        $model = $this->createRecord();
        $pending = $this->review($model, 'name', 'ar');

        // The source changes, but its job hasn't run yet.
        Queue::fake();
        $model->setTranslation('name', 'en', 'B')->save();

        try {
            TranslaGenius::reviews()->approve($pending);
            $this->fail('Expected StaleReviewException.');
        } catch (StaleReviewException $e) {
            $this->assertSame($pending->id, $e->review->id);
        }

        $this->assertSame('superseded', $pending->fresh()->status);
        $this->assertSame(['en' => 'B'], $this->stored($model));
    }

    public function test_approving_after_a_hand_edit_refuses_unless_forced()
    {
        $model = $this->publishedRecord();
        $model->setTranslation('name', 'en', 'B')->save();
        $pending = $this->review($model, 'name', 'ar');

        // A translator publishes their own "ar" by hand meanwhile.
        $model->refresh()->setTranslation('name', 'ar', 'Hand AR')->save();

        try {
            TranslaGenius::reviews()->approve($pending);
            $this->fail('Expected ReviewConflictException.');
        } catch (ReviewConflictException $e) {
            $this->assertSame('Hand AR', $e->currentValue);
        }

        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('Hand AR', $this->stored($model)['ar']);

        TranslaGenius::reviews()->approve($pending, force: true);
        $this->assertSame('B|ar', $this->stored($model)['ar']);
    }

    public function test_approve_all_skips_stale_and_conflicting_reviews_and_reports_them()
    {
        $model = $this->createRecord();
        $model->refresh()->setTranslation('description', 'ar', 'Hand AR')->save(); // conflict for description.ar
        Queue::fake();
        $model->refresh()->setTranslation('name', 'en', 'B')->save();             // name.* now stale

        $summary = TranslaGenius::reviews()->approveAll($model);

        $this->assertSame(['approved' => 1, 'skipped_stale' => 2, 'skipped_conflict' => 1], $summary);
        $this->assertSame('D|es', $this->stored($model, 'description')['es']);
    }

    // --- cache -------------------------------------------------------------------

    protected function useCache(): void
    {
        config(['cache.default' => 'array', 'translagenius.cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 3600]]);
        Cache::store('array')->flush();
    }

    public function test_an_edit_on_approval_updates_the_cache_entry()
    {
        $this->useCache();
        $first = $this->createRecord('Same', 'x');
        TranslaGenius::reviews()->approve($this->review($first, 'name', 'ar'), editedText: 'Corrected AR');
        $this->takeRequests();

        $second = $this->createRecord('Same', 'x');

        $this->assertSame([], $this->takeRequests());
        $pending = $this->review($second, 'name', 'ar');
        $this->assertSame('Corrected AR', $pending->text);
        $this->assertTrue($pending->cached);
    }

    public function test_an_edit_with_cache_opt_out_leaves_the_entry_alone()
    {
        $this->useCache();
        $first = $this->createRecord('Same', 'x');
        TranslaGenius::reviews()->approve($this->review($first, 'name', 'ar'), editedText: 'Only for this record', cacheEdit: false);

        $second = $this->createRecord('Same', 'x');

        $this->assertSame('Same|ar', $this->review($second, 'name', 'ar')->text);
    }

    public function test_rejection_removes_the_cache_entry()
    {
        $this->useCache();
        $first = $this->createRecord('Same', 'x');
        TranslaGenius::reviews()->reject($this->review($first, 'name', 'ar'));
        $this->takeRequests();

        $second = $this->createRecord('Same', 'x');

        // name.ar is requested again (the rejected text was evicted); the rest are hits.
        $this->assertSame(['single Same ar'], $this->takeRequests());
        $this->assertFalse($this->review($second, 'name', 'ar')->cached);
        $this->assertTrue($this->review($second, 'name', 'es')->cached);
    }

    // --- backfill, locales -------------------------------------------------------

    public function test_backfill_skips_pending_pairs_and_re_requests_rejected_ones()
    {
        $model = $this->createRecord();
        TranslaGenius::reviews()->reject($this->review($model, 'name', 'ar'));
        $this->takeRequests();

        $this->artisan('translagenius:backfill', ['model' => TestTranslatableModel::class, '--force' => true])->assertSuccessful();

        // name.es and description.* are pending (skipped); name.ar was rejected (requested).
        $this->assertSame(['single A ar'], $this->takeRequests());
        $this->assertSame('A|ar', $this->review($model, 'name', 'ar')->text);
    }

    public function test_review_locales_reviews_only_the_listed_locales()
    {
        config(['translagenius.review.locales' => 'ar']);

        $model = $this->createRecord();

        $this->assertSame(['en' => 'A', 'es' => 'A|es'], $this->stored($model));
        $this->assertSame(['ar'], TranslationReview::pending()->distinct()->pluck('locale')->all());
    }

    public function test_review_models_and_fields_scope_what_is_reviewed()
    {
        config(['translagenius.review.models' => [TestTranslatableModel::class => ['description']]]);

        $model = $this->createRecord();

        $this->assertSame(['ar' => 'A|ar', 'en' => 'A', 'es' => 'A|es'], $this->stored($model));
        $this->assertSame(['description'], TranslationReview::pending()->distinct()->pluck('field')->all());

        config(['translagenius.review.models' => [], 'translagenius.review.fields' => ['name']]);
        $other = $this->createRecord('N', 'M');
        $this->assertSame(['ar' => 'M|ar', 'en' => 'M', 'es' => 'M|es'], $this->stored($other, 'description'));
        $this->assertSame(2, TranslationReview::query()->for($other)->field('name')->pending()->count());
    }

    // --- request counts with batching and fallback ---------------------------------

    protected function requestsFor(bool $review, callable $arrange): array
    {
        config(['translagenius.review.enabled' => $review]);
        $arrange();
        $this->requests = [];

        $this->createRecord();

        return $this->takeRequests();
    }

    public function test_batching_sends_the_same_requests_with_review_on_or_off()
    {
        $batching = fn () => config(['translagenius.batch.enabled' => true]);

        $off = $this->requestsFor(false, $batching);
        $on = $this->requestsFor(true, $batching);

        $this->assertSame(['batch A ar,es', 'batch D ar,es'], $off);
        $this->assertSame($off, $on);
        $this->assertSame(4, TranslationReview::pending()->count());
    }

    public function test_a_fallback_chain_sends_the_same_requests_with_review_on_or_off()
    {
        $chain = function () {
            config([
                'translagenius.providers.primary' => ['driver' => OpenAiCompatibleProvider::class, 'key' => 'k', 'url' => 'https://primary.test/v1', 'model' => 'm'],
                'translagenius.providers.secondary' => ['driver' => OpenAiCompatibleProvider::class, 'key' => 'k', 'url' => 'https://secondary.test/v1', 'model' => 'm'],
                'translagenius.providers.resilient' => ['driver' => FallbackProvider::class, 'chain' => ['primary', 'secondary']],
                'translagenius.default' => 'resilient',
            ]);
            $this->failingHosts['primary.test'] = 503;
        };

        $off = $this->requestsFor(false, $chain);
        $on = $this->requestsFor(true, $chain);

        $this->assertCount(8, $off); // 4 pairs x (primary fails, secondary serves)
        $this->assertSame($off, $on);
        $this->assertSame(['secondary'], TranslationReview::pending()->distinct()->pluck('provider')->all());
    }

    // --- usage ---------------------------------------------------------------------

    public function test_usage_is_logged_at_production_and_unchanged_by_decisions()
    {
        (include __DIR__.'/../../src/database/migrations/create_translation_usage_logs_table.php.stub')->up();
        config(['translagenius.usage_tracking.enabled' => true]);

        $model = $this->createRecord();
        $rowsAfterProduction = TranslationUsageLog::count();

        TranslaGenius::reviews()->approve($this->review($model, 'name', 'ar'));
        TranslaGenius::reviews()->reject($this->review($model, 'name', 'es'));
        TranslaGenius::reviews()->approve($this->review($model, 'description', 'ar'), editedText: 'X');

        $this->assertSame(4, $rowsAfterProduction);
        $this->assertSame(4, TranslationUsageLog::count());
        $this->assertSame(array_sum(array_column($this->billed, 'prompt')), (int) TranslationUsageLog::sum('prompt_tokens'));
        $this->assertSame(array_sum(array_column($this->billed, 'completion')), (int) TranslationUsageLog::sum('completion_tokens'));
    }

    // --- prune and commands -----------------------------------------------------------

    public function test_prune_deletes_old_decided_reviews_only()
    {
        $model = $this->createRecord();
        TranslaGenius::reviews()->approve($this->review($model, 'name', 'ar'));
        TranslaGenius::reviews()->reject($this->review($model, 'name', 'es'));
        TranslationReview::query()->update(['updated_at' => now()->subDays(100)]);
        $model->refresh()->setTranslation('description', 'en', 'D2')->save(); // supersedes 2, adds 2 pending (fresh)

        $this->artisan('translagenius:review:prune', ['--days' => 90])
            ->expectsOutputToContain('Deleted 2 decided review(s)')
            ->assertSuccessful();

        $this->assertSame(
            ['description.ar superseded D|ar', 'description.es superseded D|es', 'description.ar pending D2|ar', 'description.es pending D2|es'],
            $this->statuses()
        );
    }

    public function test_review_list_and_approve_all_commands()
    {
        $this->createRecord();
        $this->createRecord('B', 'E');

        $this->artisan('translagenius:review:list')
            ->expectsTable(['Model', 'Field', 'Locale', 'Pending', 'Oldest'], TranslationReview::pending()
                ->selectRaw('translatable_type, field, locale, COUNT(*) as pending, MIN(created_at) as oldest')
                ->groupBy('translatable_type', 'field', 'locale')->orderBy('translatable_type')->orderBy('field')->orderBy('locale')
                ->get()->map(fn ($r) => [$r->translatable_type, $r->field, $r->locale, $r->pending, $r->oldest])->all())
            ->expectsOutputToContain('Total pending: 8')
            ->assertSuccessful();

        $this->artisan('translagenius:review:approve-all', ['model' => TestTranslatableModel::class, '--field' => 'name'])
            ->expectsConfirmation('Approve and publish these 4 translation(s) as produced?', 'yes')
            ->expectsOutputToContain('Approved 4.')
            ->assertSuccessful();

        $this->assertSame(4, TranslationReview::pending()->count());
        $this->assertSame(['ar' => 'B|ar', 'en' => 'B', 'es' => 'B|es'], $this->stored(TestTranslatableModel::whereNotNull('id')->orderByDesc('id')->first()));
    }

    public function test_pending_query_filters()
    {
        $model = $this->createRecord();
        $this->createRecord('B', 'E');

        $this->assertSame(8, TranslaGenius::reviews()->pending()->count());
        $this->assertSame(4, TranslaGenius::reviews()->pending()->for($model)->count());
        $this->assertSame(4, TranslaGenius::reviews()->pending()->forModel(TestTranslatableModel::class)->field('name')->count());
        $this->assertSame(2, TranslaGenius::reviews()->pending()->for($model)->locale('ar')->count());
        $this->assertSame(0, TranslaGenius::reviews()->pending()->olderThan(now()->subDay())->count());
    }

    // --- review off ------------------------------------------------------------------

    public function test_with_review_off_the_review_table_is_never_touched()
    {
        config(['translagenius.review.enabled' => false]);
        Schema::dropIfExists('translation_reviews'); // not even needed

        DB::enableQueryLog();
        $model = $this->createRecord();
        $model->setTranslation('name', 'en', 'B')->save();
        $this->artisan('translagenius:backfill', ['model' => TestTranslatableModel::class, '--force' => true]);
        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertStringNotContainsString('translation_reviews', $queries);
        $this->assertSame(['ar' => 'B|ar', 'en' => 'B', 'es' => 'B|es'], $this->stored($model));
    }
}
