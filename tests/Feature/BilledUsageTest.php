<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;
use CodingPartners\TranslaGenius\Events\TranslationCompleted;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Models\TranslationUsageLog;
use CodingPartners\TranslaGenius\Providers\FallbackProvider;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Services\Translator;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use CodingPartners\TranslaGenius\TranslaGeniusServiceProvider;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Every billed provider response ends up in the usage log exactly once:
 * SUM(tokens)/SUM(cost) over translation_usage_logs equals what the fake
 * provider "billed", in every scenario - including responses whose output
 * was never saved.
 *
 * Usage tracking is enabled at boot, so the listeners are registered the
 * real way. Two OpenAI-compatible providers with different rates, plus a
 * chain of them; source en, targets ar/es/fr.
 */
class BilledUsageTest extends Orchestra
{
    private const RATES = [
        'primary.test' => ['provider' => 'primary', 'prompt' => 1.0, 'completion' => 2.0],
        'secondary.test' => ['provider' => 'secondary', 'prompt' => 3.0, 'completion' => 4.0],
    ];

    /** @var array<int, array{host: string, prompt: int, completion: int}> Every response that reported usage. */
    private array $billed = [];

    /** host => closure(Request): array{content?: string, finish_reason?: string, status?: int, usage?: bool} */
    private array $behaviour = [];

    protected function getPackageProviders($app)
    {
        return [TranslaGeniusServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('translagenius.usage_tracking.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Queue::fake();
        Cache::store('array')->flush();

        (include __DIR__.'/../database/migrations/2024_01_01_000000_create_test_tables.php')->up();
        (include __DIR__.'/../../src/database/migrations/create_translation_usage_logs_table.php.stub')->up();

        $member = fn (string $host) => [
            'driver' => OpenAiCompatibleProvider::class,
            'key' => 'k',
            'url' => "https://{$host}/v1/chat/completions",
            'model' => 'm',
            'cost_per_1k_prompt_tokens' => self::RATES[$host]['prompt'],
            'cost_per_1k_completion_tokens' => self::RATES[$host]['completion'],
        ];

        config([
            'translagenius.supported_languages' => ['en', 'ar', 'es', 'fr'],
            'translagenius.providers' => [
                'primary' => $member('primary.test'),
                'secondary' => $member('secondary.test'),
                'chain' => ['driver' => FallbackProvider::class, 'chain' => ['primary', 'secondary']],
            ],
            'translagenius.default' => 'primary',
            'translagenius.batch.enabled' => false,
            'translagenius.cache' => ['enabled' => false],
        ]);

        Http::fake(fn (Request $request) => $this->answer($request));
        $this->reresolve();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_translatable_models');
        Schema::dropIfExists('translation_usage_logs');

        parent::tearDown();
    }

    protected function answer(Request $request): PromiseInterface
    {
        $host = parse_url($request->url(), PHP_URL_HOST);
        $system = $request['messages'][0]['content'];
        $isBatch = preg_match('/into each of these languages: (.+?)\. Respond/', $system, $m) === 1;
        $locales = $isBatch ? explode(', ', $m[1]) : [preg_match('/ to (\w+)\./', $system, $s) ? $s[1] : '?'];

        $spec = isset($this->behaviour[$host]) ? ($this->behaviour[$host])($request, $isBatch) : [];
        $spec += [
            'content' => $isBatch
                ? json_encode(array_combine($locales, array_map(fn ($l) => "{$host}:{$l}", $locales)))
                : "{$host}:{$locales[0]}",
            'finish_reason' => 'stop',
            'status' => 200,
            'usage' => true,
        ];

        $prompt = 100 + 7 * count($this->billed);
        $completion = 20 + 3 * count($this->billed);
        $body = $spec['status'] >= 400
            ? ['error' => ['message' => 'bad']]
            : ['choices' => [['message' => ['content' => $spec['content']], 'finish_reason' => $spec['finish_reason']]]];

        if ($spec['usage']) {
            $body['usage'] = ['prompt_tokens' => $prompt, 'completion_tokens' => $completion];
            $this->billed[] = ['host' => $host, 'prompt' => $prompt, 'completion' => $completion];
        }

        return Http::response($body, $spec['status']);
    }

    protected function reresolve(): void
    {
        $this->app->forgetInstance(TranslationProviderInterface::class);
        $this->app->forgetInstance(Translator::class);
        $this->app->forgetInstance(TranslatorInterface::class);
    }

    protected function runJob(array $name = ['en' => 'Hello'], array $fields = ['name']): TranslateFields
    {
        $model = TestTranslatableModel::create(['name' => $name, 'description' => ['en' => '']]);
        $job = (new TranslateFields(new TranslationJobPayload($model, $fields)))->withFakeQueueInteractions();

        try {
            $job->handle($this->app->make(TranslatorInterface::class), new TranslationWriter);
        } catch (TranslationFailedException) {
            // A retryable failure would go back to the queue; irrelevant to the sums.
        }

        return $job;
    }

    /** Billed totals from the fake vs. the usage log's SUM()s. */
    protected function assertLogMatchesBilling(): void
    {
        $billedPrompt = array_sum(array_column($this->billed, 'prompt'));
        $billedCompletion = array_sum(array_column($this->billed, 'completion'));
        $billedCost = array_sum(array_map(
            fn ($b) => $b['prompt'] / 1000 * self::RATES[$b['host']]['prompt'] + $b['completion'] / 1000 * self::RATES[$b['host']]['completion'],
            $this->billed
        ));

        $this->assertNotEmpty($this->billed);
        $this->assertSame($billedPrompt, (int) TranslationUsageLog::sum('prompt_tokens'), 'prompt tokens');
        $this->assertSame($billedCompletion, (int) TranslationUsageLog::sum('completion_tokens'), 'completion tokens');
        $this->assertSame(number_format($billedCost, 6, '.', ''), number_format((float) TranslationUsageLog::sum('estimated_cost'), 6, '.', ''), 'cost');

        // Per provider, too: each request is costed with its own rates.
        foreach (self::RATES as $host => $rate) {
            $mine = array_filter($this->billed, fn ($b) => $b['host'] === $host);
            $this->assertSame(
                array_sum(array_column($mine, 'prompt')),
                (int) TranslationUsageLog::where('provider', $rate['provider'])->sum('prompt_tokens'),
                "prompt tokens for {$rate['provider']}"
            );
        }
    }

    /** @return array<int, string> "outcome provider target_locale" for every non-translated row */
    protected function unusedRows(): array
    {
        return TranslationUsageLog::where('outcome', '!=', 'translated')->orderBy('id')->get()
            ->map(fn ($r) => "{$r->outcome} {$r->provider} {$r->target_locale}")->all();
    }

    // -----------------------------------------------------------------------

    public function test_success()
    {
        $this->runJob()->assertNotFailed();

        $this->assertLogMatchesBilling();
        $this->assertSame([], $this->unusedRows());
        $this->assertSame(3, TranslationUsageLog::where('outcome', 'translated')->count());
    }

    public function test_a_truncated_single_reply()
    {
        $this->behaviour['primary.test'] = fn (Request $r) => str_contains($r['messages'][0]['content'], ' to es.')
            ? ['content' => 'half', 'finish_reason' => 'length']
            : [];

        $this->runJob()->assertFailed();

        $this->assertCount(2, $this->billed); // ar, then the truncated es (fr never sent)
        $this->assertLogMatchesBilling();
        $this->assertSame(['truncated primary es'], $this->unusedRows());
    }

    public function test_a_truncated_batch_outside_a_chain()
    {
        config(['translagenius.batch.enabled' => true]);
        $this->reresolve();
        $this->behaviour['primary.test'] = fn (Request $r, bool $isBatch) => $isBatch
            ? ['content' => '{"ar": "x', 'finish_reason' => 'length']
            : [];

        $this->runJob()->assertNotFailed();

        $this->assertCount(4, $this->billed); // the batch, then 3 singles
        $this->assertLogMatchesBilling();
        $this->assertSame(['truncated primary ar,es,fr'], $this->unusedRows());
    }

    public function test_a_chain_whose_primary_is_billed_but_truncated_and_whose_secondary_succeeds()
    {
        config(['translagenius.default' => 'chain']);
        $this->reresolve();
        $this->behaviour['primary.test'] = fn () => ['content' => 'half', 'finish_reason' => 'length'];

        $this->runJob()->assertNotFailed();

        $this->assertCount(6, $this->billed); // per locale: primary (truncated), then secondary
        $this->assertLogMatchesBilling();
        $this->assertSame(['truncated primary ar', 'truncated primary es', 'truncated primary fr'], $this->unusedRows());
        $this->assertSame(3, TranslationUsageLog::where('outcome', 'translated')->where('provider', 'secondary')->count());
    }

    public function test_a_chain_whose_primary_batch_is_truncated_and_whose_secondary_batch_succeeds()
    {
        config(['translagenius.default' => 'chain', 'translagenius.batch.enabled' => true]);
        $this->reresolve();
        $this->behaviour['primary.test'] = fn (Request $r, bool $isBatch) => $isBatch
            ? ['content' => '{"ar": "x', 'finish_reason' => 'length']
            : [];

        $this->runJob()->assertNotFailed();

        $this->assertCount(2, $this->billed);
        $this->assertLogMatchesBilling();
        $this->assertSame(['truncated primary ar,es,fr'], $this->unusedRows());
    }

    public function test_an_unparseable_batch()
    {
        config(['translagenius.batch.enabled' => true]);
        $this->reresolve();
        $this->behaviour['primary.test'] = fn (Request $r, bool $isBatch) => $isBatch ? ['content' => 'Sure! Here you go.'] : [];

        $this->runJob()->assertNotFailed();

        $this->assertCount(4, $this->billed);
        $this->assertLogMatchesBilling();
        $this->assertSame(['unusable primary ar,es,fr'], $this->unusedRows());
    }

    public function test_an_error_response_that_still_reports_usage()
    {
        $this->behaviour['primary.test'] = fn () => ['status' => 400];

        $this->runJob()->assertFailed();

        $this->assertCount(1, $this->billed);
        $this->assertLogMatchesBilling();
        $this->assertSame(['failed primary ar'], $this->unusedRows());
    }

    public function test_responses_without_usage_are_not_logged_as_billed()
    {
        $this->behaviour['primary.test'] = fn () => ['status' => 503, 'usage' => false];

        $this->runJob();

        $this->assertSame([], $this->billed);
        $this->assertSame(0, TranslationUsageLog::count());
    }

    public function test_a_translation_discarded_because_the_source_changed_again()
    {
        $model = TestTranslatableModel::withoutEvents(fn () => TestTranslatableModel::create([
            'name' => ['en' => 'B', 'ar' => 'A:ar', 'es' => 'A:es', 'fr' => 'A:fr'],
            'description' => ['en' => ''],
        ]));
        $this->behaviour['primary.test'] = function () use ($model) {
            DB::table('test_translatable_models')->where('id', $model->id)
                ->update(['name' => json_encode(['en' => 'C', 'ar' => 'A:ar', 'es' => 'A:es', 'fr' => 'A:fr'])]);

            return [];
        };

        (new TranslateFields(new TranslationJobPayload(TestTranslatableModel::find($model->id), ['name'], retranslate: [
            'name' => ['locale' => 'en', 'text' => 'B', 'keep' => []],
        ])))->handle($this->app->make(TranslatorInterface::class), new TranslationWriter);

        $this->assertCount(1, $this->billed);
        $this->assertLogMatchesBilling();
        $this->assertSame(['discarded primary ar'], $this->unusedRows());
        $this->assertSame(TestTranslatableModel::class, TranslationUsageLog::first()->model_class);
    }

    public function test_runs_with_cache_hits()
    {
        config(['translagenius.cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 3600]]);
        $this->reresolve();

        $this->runJob()->assertNotFailed();
        $this->runJob()->assertNotFailed(); // identical text: all hits

        $this->assertCount(3, $this->billed);
        $this->assertLogMatchesBilling();
        $this->assertSame(3, TranslationUsageLog::where('cached', true)->whereNull('prompt_tokens')->whereNull('estimated_cost')->count());
        $this->assertSame([], $this->unusedRows());
    }

    public function test_runs_with_cache_hits_and_a_truncated_batch()
    {
        config([
            'translagenius.cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 3600],
            'translagenius.batch.enabled' => true,
        ]);
        $this->reresolve();
        $this->runJob(['en' => 'Hello'])->assertNotFailed(); // batch: caches ar/es/fr

        $this->behaviour['primary.test'] = fn (Request $r, bool $isBatch) => $isBatch
            ? ['content' => '{"es": "x', 'finish_reason' => 'length']
            : [];
        $this->runJob(['en' => 'Hello'])->assertNotFailed();      // all hits, 0 requests
        $this->runJob(['en' => 'Goodbye'])->assertNotFailed();    // truncated batch + 3 singles

        $this->assertCount(5, $this->billed);
        $this->assertLogMatchesBilling();
        $this->assertSame(['truncated primary ar,es,fr'], $this->unusedRows());
    }

    public function test_a_billed_translation_whose_save_throws()
    {
        $model = TestTranslatableModel::create(['name' => ['en' => 'Hello'], 'description' => ['en' => '']]);
        $failingWriter = new class extends TranslationWriter
        {
            public function fillMissing(Model $model, string $field, string $locale, string $text, array $source): string
            {
                if ($field === 'name' && $locale === 'es') {
                    throw new \RuntimeException('database went away');
                }

                return parent::fillMissing($model, $field, $locale, $text, $source);
            }
        };

        try {
            (new TranslateFields(new TranslationJobPayload($model, ['name'], sourceLocale: 'en')))->handle($this->app->make(TranslatorInterface::class), $failingWriter);
            $this->fail('The save failure must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('database went away', $e->getMessage());
        }

        $this->assertCount(2, $this->billed); // ar (saved), es (billed, save threw); fr never sent
        $this->assertLogMatchesBilling();
        $this->assertSame(['unsaved primary es'], $this->unusedRows());
        $this->assertSame(1, TranslationUsageLog::where('outcome', 'translated')->count());
    }

    /**
     * A pair filled by someone else while its request was in flight: nothing
     * is written for it, so it is billed as discarded and not announced.
     */
    public function test_a_pair_filled_while_its_request_was_in_flight()
    {
        $completed = [];
        Event::listen(TranslationCompleted::class, function (TranslationCompleted $e) use (&$completed) {
            $completed[] = $e->targetLocale;
        });
        $this->behaviour['primary.test'] = function (Request $r, bool $isBatch) {
            if (str_contains($r['messages'][0]['content'], ' to es.')) {
                DB::table('test_translatable_models')->update(['name' => json_encode(['en' => 'Hello', 'es' => 'Hola (by hand)'])]);
            }

            return [];
        };

        $this->runJob()->assertNotFailed();

        $this->assertCount(3, $this->billed);
        $this->assertLogMatchesBilling();
        $this->assertSame(['discarded primary es'], $this->unusedRows());
        $this->assertSame(2, TranslationUsageLog::where('outcome', 'translated')->count());
        $this->assertSame(['ar', 'fr'], $completed);
        $this->assertSame('Hola (by hand)', TestTranslatableModel::first()->getTranslation('name', 'es', false));
    }

    /** The record was deleted while a request was in flight: billed as discarded, the rest not requested. */
    public function test_a_record_deleted_while_a_request_was_in_flight()
    {
        $this->behaviour['primary.test'] = function () {
            DB::table('test_translatable_models')->delete();

            return [];
        };

        $this->runJob()->assertNotFailed();

        $this->assertCount(1, $this->billed);
        $this->assertLogMatchesBilling();
        $this->assertSame(['discarded primary ar'], $this->unusedRows());
        $this->assertSame(0, TranslationUsageLog::where('outcome', 'translated')->count());
    }

    /**
     * A blank reply is usually a passing provider hiccup: it fails the job
     * attempt as retryable (no HTTP retry, the provider did answer), and the
     * next attempt translates. Both requests are billed.
     */
    public function test_a_blank_reply_is_retried_by_the_queue()
    {
        $replies = ["  \n", 'Hola'];
        $this->behaviour['primary.test'] = function () use (&$replies) {
            return ['content' => array_shift($replies) ?? 'unexpected'];
        };
        config(['translagenius.supported_languages' => ['en', 'es']]);

        $model = TestTranslatableModel::create(['name' => ['en' => 'Hello'], 'description' => ['en' => '']]);

        // Attempt 1: the blank reply fails the attempt, retryably.
        $first = (new TranslateFields(new TranslationJobPayload($model, ['name'], 'en')))->withFakeQueueInteractions();
        try {
            $first->handle($this->app->make(TranslatorInterface::class), new TranslationWriter);
            $this->fail('Expected the retryable failure to propagate to the queue.');
        } catch (TranslationFailedException $e) {
            $this->assertTrue($e->isRetryable());
        }
        $first->assertNotFailed();
        $this->assertCount(1, $this->billed, 'No HTTP-level retry of a blank reply.');

        // Attempt 2: the queue runs the job again.
        $second = (new TranslateFields(new TranslationJobPayload($model, ['name'], 'en')))->withFakeQueueInteractions();
        $second->handle($this->app->make(TranslatorInterface::class), new TranslationWriter);
        $second->assertNotFailed();

        $this->assertSame('Hola', TestTranslatableModel::find($model->id)->getTranslation('name', 'es', false));
        $this->assertCount(2, $this->billed);
        $this->assertLogMatchesBilling();
        $this->assertSame(['unusable primary es'], $this->unusedRows());
        $this->assertSame(1, TranslationUsageLog::where('outcome', 'translated')->count());
    }

    /** In a chain, a blank reply still moves on to the next member. */
    public function test_a_blank_reply_in_a_chain_moves_to_the_secondary()
    {
        config(['translagenius.default' => 'chain', 'translagenius.supported_languages' => ['en', 'es']]);
        $this->reresolve();
        $this->behaviour['primary.test'] = fn () => ['content' => ' '];

        $this->runJob()->assertNotFailed();

        $this->assertSame('secondary.test:es', TestTranslatableModel::first()->getTranslation('name', 'es', false));
        $this->assertLogMatchesBilling();
        $this->assertSame(['unusable primary es'], $this->unusedRows());
    }
}
