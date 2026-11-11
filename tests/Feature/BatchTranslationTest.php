<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;
use CodingPartners\TranslaGenius\Events\TranslationCompleted;
use CodingPartners\TranslaGenius\Events\TranslationRequestBilled;
use CodingPartners\TranslaGenius\Events\TranslationStarted;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Listeners\LogTranslationUsage;
use CodingPartners\TranslaGenius\Models\TranslationUsageLog;
use CodingPartners\TranslaGenius\Providers\FallbackProvider;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Services\Translator;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

/**
 * Batching: all missing target locales of one field in one request.
 *
 * Fixture: source en, targets ar/es/fr; fields name and description (6
 * pairs). The provider is the TestCase's OpenAI-compatible fixture with
 * batching switched on (or off) per test. The fake endpoint tells a batch
 * request from a single one by its prompt, and records every request with
 * the tokens it "billed".
 */
class BatchTranslationTest extends TestCase
{
    private const BATCH_TOKENS = ['prompt_tokens' => 120, 'completion_tokens' => 45];

    private const SINGLE_TOKENS = ['prompt_tokens' => 50, 'completion_tokens' => 12];

    /** @var array<int, array{type: string, field: string, locales: array<int, string>}> */
    private array $requests = [];

    /** @var array<int, array{prompt_tokens: int, completion_tokens: int}> */
    private array $billed = [];

    /**
     * Per field: how to answer its batch request. 'valid' (default), or a
     * closure (array $locales): array{content: string, finish_reason?: string}.
     *
     * @var array<string, mixed>
     */
    private array $batchReply = [];

    /** Replaces answer() entirely for tests that need a different endpoint. */
    private ?\Closure $override = null;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Queue::fake();
        config(['translagenius.supported_languages' => ['en', 'ar', 'es', 'fr']]);

        Http::fake(fn (Request $request) => $this->answer($request));
    }

    protected function answer(Request $request): PromiseInterface
    {
        if ($this->override !== null) {
            return ($this->override)($request);
        }

        $system = $request['messages'][0]['content'];
        $field = $request['messages'][1]['content'] === 'Source name' ? 'name' : 'description';

        if (preg_match('/into each of these languages: (.+?)\. Respond/', $system, $m) === 1) {
            $locales = explode(', ', $m[1]);
            $this->requests[] = ['type' => 'batch', 'field' => $field, 'locales' => $locales];
            $this->billed[] = self::BATCH_TOKENS;

            $reply = ($this->batchReply[$field] ?? null)
                ? ($this->batchReply[$field])($locales)
                : ['content' => json_encode(array_combine($locales, array_map(fn ($l) => "{$field}:{$l}", $locales)))];

            return Http::response([
                'choices' => [['message' => ['content' => $reply['content']], 'finish_reason' => $reply['finish_reason'] ?? 'stop']],
                'usage' => self::BATCH_TOKENS,
            ]);
        }

        preg_match('/ to (\w+)\./', $system, $m);
        $this->requests[] = ['type' => 'single', 'field' => $field, 'locales' => [$m[1]]];
        $this->billed[] = self::SINGLE_TOKENS;

        return Http::response([
            'choices' => [['message' => ['content' => "{$field}:{$m[1]}"], 'finish_reason' => 'stop']],
            'usage' => self::SINGLE_TOKENS,
        ]);
    }

    protected function batching(bool $on): void
    {
        config(['translagenius.batch.enabled' => $on]);
        $this->app->forgetInstance(TranslationProviderInterface::class);
        $this->app->forgetInstance(Translator::class);
        $this->app->forgetInstance(TranslatorInterface::class);
    }

    protected function createModel(): TestTranslatableModel
    {
        return TestTranslatableModel::create([
            'name' => ['en' => 'Source name'],
            'description' => ['en' => 'Source description'],
        ]);
    }

    protected function runJob(TestTranslatableModel $model, ?TranslatorInterface $translator = null): TranslateFields
    {
        $job = (new TranslateFields(new TranslationJobPayload($model, ['name', 'description'])))->withFakeQueueInteractions();
        $job->handle($translator ?? $this->app->make(TranslatorInterface::class), $this->app->make(TranslationWriter::class));

        return $job;
    }

    /** @return array<string, string> "field:locale" => stored text, for all 6 pairs */
    protected function stored(TestTranslatableModel $model): array
    {
        $fresh = TestTranslatableModel::find($model->id);
        $out = [];

        foreach (['name', 'description'] as $field) {
            foreach (['ar', 'es', 'fr'] as $locale) {
                $out["{$field}:{$locale}"] = $fresh->getTranslation($field, $locale, false);
            }
        }

        return $out;
    }

    protected function allSix(): array
    {
        $out = [];
        foreach (['name', 'description'] as $field) {
            foreach (['ar', 'es', 'fr'] as $locale) {
                $out["{$field}:{$locale}"] = "{$field}:{$locale}";
            }
        }

        return $out;
    }

    /** Locale => text, ignoring key order (MySQL's JSON type normalizes it). */
    protected function assertSameTranslations(array $expected, array $actual): void
    {
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    protected function requestTypes(): array
    {
        return array_map(fn ($r) => $r['type'].' '.$r['field'].' '.implode(',', $r['locales']), $this->requests);
    }

    // --- request counts --------------------------------------------------

    public function test_batching_on_sends_one_request_per_field()
    {
        $this->batching(true);
        $model = $this->createModel();

        $this->runJob($model)->assertNotFailed();

        $this->assertSame(['batch name ar,es,fr', 'batch description ar,es,fr'], $this->requestTypes());
        $this->assertSame($this->allSix(), $this->stored($model));
    }

    public function test_batching_off_sends_one_request_per_pair()
    {
        $this->batching(false);
        $model = $this->createModel();

        $this->runJob($model)->assertNotFailed();

        $this->assertCount(6, $this->requests);
        $this->assertSame(['single'], array_values(array_unique(array_column($this->requests, 'type'))));
        $this->assertSame($this->allSix(), $this->stored($model));
    }

    public function test_a_field_missing_a_single_locale_is_not_batched()
    {
        $this->batching(true);
        $model = TestTranslatableModel::create([
            'name' => ['en' => 'Source name', 'ar' => 'x', 'es' => 'y'],
            'description' => ['en' => 'Source description'],
        ]);

        $this->runJob($model);

        $this->assertSame(['single name fr', 'batch description ar,es,fr'], $this->requestTypes());
    }

    // --- partial and bad replies -----------------------------------------

    public function test_reply_missing_one_locale_sends_one_single_request_for_it()
    {
        $this->batching(true);
        $this->batchReply['name'] = fn ($locales) => ['content' => json_encode(['ar' => 'name:ar', 'fr' => 'name:fr'])];
        $model = $this->createModel();

        $this->runJob($model)->assertNotFailed();

        $this->assertSame(['batch name ar,es,fr', 'single name es', 'batch description ar,es,fr'], $this->requestTypes());
        $this->assertSame($this->allSix(), $this->stored($model));
    }

    public function test_invalid_values_count_as_missing_and_extra_keys_are_ignored()
    {
        $this->batching(true);
        $this->batchReply['name'] = fn ($locales) => ['content' => json_encode([
            'ar' => 'name:ar',
            'es' => '   ',          // blank: invalid
            'fr' => ['nested'],     // not a string: invalid
            'de' => 'name:de',      // not requested: ignored
        ])];
        $model = $this->createModel();

        $this->runJob($model)->assertNotFailed();

        $this->assertSame(
            ['batch name ar,es,fr', 'single name es', 'single name fr', 'batch description ar,es,fr'],
            $this->requestTypes()
        );
        $this->assertSame($this->allSix(), $this->stored($model));
        $this->assertSame('', TestTranslatableModel::find($model->id)->getTranslation('name', 'de', false));
    }

    public function test_unparseable_reply_sends_the_whole_field_one_locale_at_a_time()
    {
        $this->batching(true);
        $this->batchReply['name'] = fn ($locales) => ['content' => 'Sure! Here are your translations: ar = ...'];
        $model = $this->createModel();

        $this->runJob($model)->assertNotFailed();

        $this->assertSame(
            ['batch name ar,es,fr', 'single name ar', 'single name es', 'single name fr', 'batch description ar,es,fr'],
            $this->requestTypes()
        );
        $this->assertSame($this->allSix(), $this->stored($model));
    }

    public function test_a_json_list_or_scalar_is_unparseable_too()
    {
        $this->batching(true);
        $this->batchReply['name'] = fn ($locales) => ['content' => '["name:ar", "name:es", "name:fr"]'];
        $this->batchReply['description'] = fn ($locales) => ['content' => '"description"'];
        $model = $this->createModel();

        $this->runJob($model)->assertNotFailed();

        $this->assertCount(8, $this->requests); // 2 batch + 6 single
        $this->assertSame($this->allSix(), $this->stored($model));
    }

    public function test_one_surrounding_code_fence_is_tolerated()
    {
        $this->batching(true);
        $this->batchReply['name'] = fn ($locales) => ['content' => "```json\n".json_encode(['ar' => 'name:ar', 'es' => 'name:es', 'fr' => 'name:fr'])."\n```"];
        $model = $this->createModel();

        $this->runJob($model)->assertNotFailed();

        $this->assertSame(['batch name ar,es,fr', 'batch description ar,es,fr'], $this->requestTypes());
    }

    public function test_truncated_batch_falls_back_to_one_request_per_locale()
    {
        $this->batching(true);
        $this->batchReply['name'] = fn ($locales) => ['content' => '{"ar": "name:ar", "es": "na', 'finish_reason' => 'length'];
        $model = $this->createModel();

        $job = $this->runJob($model);

        $job->assertNotFailed();
        $job->assertNotReleased();
        $this->assertSame(
            ['batch name ar,es,fr', 'single name ar', 'single name es', 'single name fr', 'batch description ar,es,fr'],
            $this->requestTypes()
        );
        $this->assertSame($this->allSix(), $this->stored($model));
    }

    public function test_a_truncated_single_locale_request_still_fails_the_job()
    {
        $this->batching(false);
        $this->override = fn (Request $r) => Http::response(['choices' => [['message' => ['content' => 'half'], 'finish_reason' => 'length']]]);

        $job = $this->runJob($this->createModel());

        $job->assertFailedWith(TranslationFailedException::class);
        Http::assertSentCount(1);
    }

    // --- fallback chains -------------------------------------------------

    protected function chainMember(string $name, string $host, bool $batch = true): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider([
            'name' => $name,
            'key' => 'k',
            'url' => "https://{$host}/v1/chat/completions",
            'model' => 'm',
            'batch' => $batch,
            'http_retries' => 0,
        ]);
    }

    public function test_truncated_batch_in_a_chain_moves_to_the_secondary()
    {
        $this->override = fn (Request $r) => str_contains($r->url(), 'primary.test')
            ? Http::response(['choices' => [['message' => ['content' => '{"ar": "x'], 'finish_reason' => 'length']]])
            : Http::response(['choices' => [['message' => [
                'content' => json_encode(['ar' => 'S:ar', 'es' => 'S:es', 'fr' => 'S:fr']),
            ], 'finish_reason' => 'stop']]]);

        $translator = new Translator(new FallbackProvider([
            $this->chainMember('primary', 'primary.test'),
            $this->chainMember('secondary', 'secondary.test'),
        ]));
        $model = TestTranslatableModel::create(['name' => ['en' => 'Source name'], 'description' => ['en' => '']]);

        (new TranslateFields(new TranslationJobPayload($model, ['name'])))->handle($translator, new TranslationWriter);

        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), 'primary.test')));
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), 'secondary.test')));
        $this->assertSameTranslations(
            ['en' => 'Source name', 'ar' => 'S:ar', 'es' => 'S:es', 'fr' => 'S:fr'],
            TestTranslatableModel::find($model->id)->getTranslations('name')
        );
    }

    public function test_when_every_member_truncates_the_batch_the_locales_go_through_the_chain_one_at_a_time()
    {
        $this->override = function (Request $r) {
            $isBatch = str_contains($r['messages'][0]['content'], 'each of these languages');
            $host = parse_url($r->url(), PHP_URL_HOST);

            if ($isBatch) {
                return Http::response(['choices' => [['message' => ['content' => '{"ar": "x'], 'finish_reason' => 'length']]]);
            }

            preg_match('/ to (\w+)\./', $r['messages'][0]['content'], $m);

            return Http::response(['choices' => [['message' => ['content' => "{$host}:{$m[1]}"], 'finish_reason' => 'stop']]]);
        };

        $translator = new Translator(new FallbackProvider([
            $this->chainMember('primary', 'primary.test'),
            $this->chainMember('secondary', 'secondary.test'),
        ]));
        $model = TestTranslatableModel::create(['name' => ['en' => 'Source name'], 'description' => ['en' => '']]);

        (new TranslateFields(new TranslationJobPayload($model, ['name'])))->handle($translator, new TranslationWriter);

        // 1 batch per member (both truncated), then 3 singles via the chain,
        // all served by the primary.
        $this->assertCount(4, Http::recorded(fn ($r) => str_contains($r->url(), 'primary.test')));
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), 'secondary.test')));
        $this->assertSameTranslations(
            ['en' => 'Source name', 'ar' => 'primary.test:ar', 'es' => 'primary.test:es', 'fr' => 'primary.test:fr'],
            TestTranslatableModel::find($model->id)->getTranslations('name')
        );
    }

    public function test_a_single_only_primary_is_skipped_for_the_batch_but_serves_single_calls()
    {
        $calls = [];
        $singleOnly = new class($calls) implements TranslationProviderInterface
        {
            public function __construct(private array &$calls) {}

            public function translate(TranslationRequest $request): TranslationResult
            {
                $this->calls[] = $request->targetLocale;

                return new TranslationResult("P:{$request->targetLocale}", provider: 'single-only');
            }
        };

        // The batch-capable secondary answers the batch with only "ar"; the
        // missing es/fr then go through the whole chain, primary first.
        $this->override = fn (Request $r) => Http::response(['choices' => [['message' => [
            'content' => json_encode(['ar' => 'S:ar']),
        ], 'finish_reason' => 'stop']]]);

        $translator = new Translator(new FallbackProvider([$singleOnly, $this->chainMember('secondary', 'secondary.test')]));
        $model = TestTranslatableModel::create(['name' => ['en' => 'Source name'], 'description' => ['en' => '']]);

        (new TranslateFields(new TranslationJobPayload($model, ['name'])))->handle($translator, new TranslationWriter);

        Http::assertSentCount(1);
        $this->assertSame(['es', 'fr'], $calls);
        $this->assertSameTranslations(
            ['en' => 'Source name', 'ar' => 'S:ar', 'es' => 'P:es', 'fr' => 'P:fr'],
            TestTranslatableModel::find($model->id)->getTranslations('name')
        );
    }

    // --- single-call-only providers --------------------------------------

    public function test_a_provider_without_batch_support_gets_one_call_per_locale()
    {
        $this->batching(true);
        $calls = [];
        $provider = new class($calls) implements TranslationProviderInterface
        {
            public function __construct(private array &$calls) {}

            public function translate(TranslationRequest $request): TranslationResult
            {
                $this->calls[] = "{$request->field}:{$request->targetLocale}";

                return new TranslationResult("{$request->field}:{$request->targetLocale}");
            }
        };
        $model = $this->createModel();

        $this->runJob($model, new Translator($provider))->assertNotFailed();

        $this->assertSame(array_keys($this->allSix()), $calls);
        $this->assertSame($this->allSix(), $this->stored($model));
        Http::assertNothingSent();
    }

    // --- events ----------------------------------------------------------

    public function test_events_fire_once_per_pair_with_a_partial_batch()
    {
        Event::fake([TranslationStarted::class, TranslationCompleted::class]);
        $this->batching(true);
        $this->batchReply['name'] = fn ($locales) => ['content' => json_encode(['ar' => 'name:ar'])];
        $model = $this->createModel();

        $this->runJob($model);

        foreach (array_keys($this->allSix()) as $pair) {
            [$field, $locale] = explode(':', $pair);
            $match = fn ($e) => $e->field === $field && $e->targetLocale === $locale;
            $this->assertCount(1, Event::dispatched(TranslationStarted::class, $match), "Started {$pair}");
            $this->assertCount(1, Event::dispatched(TranslationCompleted::class, $match), "Completed {$pair}");
        }

        // The name batch's 3 pairs share its request id; only "ar" (the first
        // pair it completed) carries the batch's tokens.
        $nameAr = Event::dispatched(TranslationCompleted::class, fn ($e) => $e->field === 'name' && $e->targetLocale === 'ar')->first()[0];
        $this->assertSame(self::BATCH_TOKENS['prompt_tokens'], $nameAr->result->promptTokens);
        $descriptionRows = Event::dispatched(TranslationCompleted::class, fn ($e) => $e->field === 'description');
        $this->assertCount(1, $descriptionRows->map(fn ($e) => $e[0]->result->requestId)->unique());
        $this->assertSame(1, $descriptionRows->filter(fn ($e) => $e[0]->result->promptTokens !== null)->count());
    }

    // --- usage totals ----------------------------------------------------

    /** @return array<string, int|string> */
    protected function runWithUsageLogging(callable $arrange): array
    {
        (include __DIR__.'/../../src/database/migrations/create_translation_usage_logs_table.php.stub')->up();
        Event::listen(TranslationCompleted::class, LogTranslationUsage::class);
        Event::listen(TranslationRequestBilled::class, [LogTranslationUsage::class, 'handleBilled']);
        config([
            'translagenius.providers.openrouter.cost_per_1k_prompt_tokens' => 1.0,
            'translagenius.providers.openrouter.cost_per_1k_completion_tokens' => 2.0,
        ]);

        $arrange();
        $this->runJob($this->createModel())->assertNotFailed();

        $prompt = array_sum(array_column($this->billed, 'prompt_tokens'));
        $completion = array_sum(array_column($this->billed, 'completion_tokens'));

        $result = [
            'billed_prompt' => $prompt,
            'logged_prompt' => (int) TranslationUsageLog::sum('prompt_tokens'),
            'billed_completion' => $completion,
            'logged_completion' => (int) TranslationUsageLog::sum('completion_tokens'),
            'billed_cost' => number_format($prompt / 1000 * 1.0 + $completion / 1000 * 2.0, 6, '.', ''),
            'logged_cost' => number_format((float) TranslationUsageLog::sum('estimated_cost'), 6, '.', ''),
            'rows' => TranslationUsageLog::count(),
            'translated_rows' => TranslationUsageLog::where('outcome', 'translated')->count(),
            'outcomes' => TranslationUsageLog::where('outcome', '!=', 'translated')->orderBy('id')
                ->get(['outcome', 'field', 'target_locale'])->map(fn ($r) => "{$r->outcome} {$r->field} {$r->target_locale}")->all(),
        ];

        Schema::dropIfExists('translation_usage_logs');

        return $result;
    }

    protected function assertUsageMatchesBilling(array $usage): void
    {
        $this->assertSame($usage['billed_prompt'], $usage['logged_prompt']);
        $this->assertSame($usage['billed_completion'], $usage['logged_completion']);
        $this->assertSame($usage['billed_cost'], $usage['logged_cost']);
        $this->assertSame(6, $usage['translated_rows']);
    }

    public function test_usage_totals_match_billing_batched()
    {
        $this->assertUsageMatchesBilling($this->runWithUsageLogging(fn () => $this->batching(true)));
    }

    public function test_usage_totals_match_billing_unbatched()
    {
        $this->assertUsageMatchesBilling($this->runWithUsageLogging(fn () => $this->batching(false)));
    }

    public function test_usage_totals_match_billing_with_a_truncated_batch()
    {
        $usage = $this->runWithUsageLogging(function () {
            $this->batching(true);
            $this->batchReply['name'] = fn ($locales) => ['content' => '{"ar": "na', 'finish_reason' => 'length'];
        });

        $this->assertUsageMatchesBilling($usage);
        $this->assertCount(5, $this->requests); // name: batch + 3 single; description: batch
        $this->assertSame(['truncated name ar,es,fr'], $usage['outcomes']);
        $this->assertSame(7, $usage['rows']);
    }

    public function test_usage_totals_match_billing_with_a_partial_and_an_unparseable_batch()
    {
        $usage = $this->runWithUsageLogging(function () {
            $this->batching(true);
            $this->batchReply['name'] = fn ($locales) => ['content' => json_encode(['fr' => 'name:fr'])];
            $this->batchReply['description'] = fn ($locales) => ['content' => 'not json'];
        });

        $this->assertUsageMatchesBilling($usage);
        $this->assertCount(7, $this->requests); // 2 batch + 2 (name) + 3 (description) single
        // The partial name batch is a normal translated request (its tokens
        // are on the "fr" row); the unparseable description batch gets its own row.
        $this->assertSame(['unusable description ar,es,fr'], $usage['outcomes']);
    }

    // --- prompt and request shape ------------------------------------------

    public function test_batch_request_keeps_instructions_in_system_and_content_in_user()
    {
        $this->batching(true);
        config(['translagenius.batch.prompt' => null]);
        $model = $this->createModel();

        $this->runJob($model);

        Http::assertSent(function (Request $r) {
            return $r['messages'][1] === ['role' => 'user', 'content' => 'Source name']
                && count($r['messages']) === 2
                && $r['messages'][0]['role'] === 'system'
                && str_starts_with($r['messages'][0]['content'], "You are a translation engine. Translate the user's message from en into each of these languages: ar, es, fr.")
                && str_contains($r['messages'][0]['content'], 'json object')
                && ! str_contains($r['messages'][0]['content'], 'Source name')
                && ! isset($r['response_format']);
        });
    }

    public function test_custom_batch_prompt_with_placeholders()
    {
        $this->batching(true);
        config(['translagenius.providers.openrouter.batch_prompt' => 'B {model}.{field} {source_locale} -> {target_locales}; {target_locale} {unknown}']);
        $sent = [];
        $this->override = function (Request $r) use (&$sent) {
            $sent[] = $r['messages'][0]['content'];

            return Http::response(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']]]);
        };
        $this->app->forgetInstance(TranslationProviderInterface::class);
        $this->app->forgetInstance(Translator::class);
        $this->app->forgetInstance(TranslatorInterface::class);
        $model = TestTranslatableModel::create(['name' => ['en' => 'x'], 'description' => ['en' => '']]);

        // "ok" isn't a JSON object, so each locale then goes one at a time.
        (new TranslateFields(new TranslationJobPayload($model, ['name'])))->handle($this->app->make(TranslatorInterface::class), new TranslationWriter);

        $this->assertSame('B TestTranslatableModel.name en -> ar, es, fr; {target_locale} {unknown}', $sent[0]);
    }
}
