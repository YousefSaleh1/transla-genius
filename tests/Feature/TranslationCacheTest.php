<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationBatchRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;
use CodingPartners\TranslaGenius\Events\TranslationCompleted;
use CodingPartners\TranslaGenius\Events\TranslationStarted;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Models\TranslationUsageLog;
use CodingPartners\TranslaGenius\Providers\CachingProvider;
use CodingPartners\TranslaGenius\Providers\FallbackProvider;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * TRANSLATION_CACHE: a CachingProvider wrapped around the resolved provider,
 * on a real cache store (array) with faked HTTP.
 *
 * Fixture: source en, targets ar/es/fr, the TestCase's OpenAI-compatible
 * "openrouter" entry. Every request is recorded as "<host> <single|batch>
 * <locales>".
 */
class TranslationCacheTest extends TestCase
{
    private const TOKENS = ['prompt_tokens' => 40, 'completion_tokens' => 10];

    /** @var array<int, string> */
    private array $requests = [];

    /** @var array<int, array{prompt_tokens: int, completion_tokens: int}> */
    private array $billed = [];

    /** Host => HTTP status to fail with (for chain tests). */
    private array $failingHosts = [];

    /** Optional replacement answer. */
    private ?\Closure $override = null;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Queue::fake();
        Cache::store('array')->flush();

        config([
            'cache.default' => 'array',
            'translagenius.supported_languages' => ['en', 'ar', 'es', 'fr'],
            'translagenius.cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 3600],
        ]);

        Http::fake(fn (Request $request) => $this->answer($request));
    }

    protected function answer(Request $request): PromiseInterface
    {
        $host = parse_url($request->url(), PHP_URL_HOST);
        $system = $request['messages'][0]['content'];
        $text = $request['messages'][1]['content'];

        if ($this->override !== null) {
            return ($this->override)($request);
        }

        if (isset($this->failingHosts[$host])) {
            $this->requests[] = "{$host} failed";

            return Http::response(['error' => ['message' => 'down']], $this->failingHosts[$host]);
        }

        if (preg_match('/into each of these languages: (.+?)\. Respond/', $system, $m) === 1) {
            $locales = explode(', ', $m[1]);
            $this->requests[] = "{$host} batch ".implode(',', $locales);
            $this->billed[] = self::TOKENS;
            $content = json_encode(array_combine($locales, array_map(fn ($l) => "{$host}|{$text}|{$l}", $locales)));
        } else {
            preg_match('/ to (\w+)\./', $system, $m);
            $this->requests[] = "{$host} single {$m[1]}";
            $this->billed[] = self::TOKENS;
            $content = "{$host}|{$text}|{$m[1]}";
        }

        return Http::response([
            'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'usage' => self::TOKENS,
        ]);
    }

    protected function provider(): TranslationProviderInterface
    {
        return $this->app->make(TranslationProviderInterface::class);
    }

    protected function translate(string $text, string $target = 'ar', ?string $field = null, ?string $model = null): TranslationResult
    {
        return $this->app->make(TranslatorInterface::class)->translate($text, 'en', $target, $field, $model);
    }

    protected function runJob(array $attributes, array $fields = ['name', 'description']): TestTranslatableModel
    {
        $model = TestTranslatableModel::create($attributes + ['name' => ['en' => ''], 'description' => ['en' => '']]);
        (new TranslateFields(new TranslationJobPayload($model, $fields)))->handle($this->app->make(TranslatorInterface::class), new TranslationWriter);

        return $model;
    }

    protected function takeRequests(): array
    {
        $requests = $this->requests;
        $this->requests = [];

        return $requests;
    }

    // --- wrapping ------------------------------------------------------------

    public function test_off_by_default_the_provider_is_not_wrapped()
    {
        config(['translagenius.cache' => null]);
        $this->assertSame(OpenAiCompatibleProvider::class, get_class($this->provider()));

        config(['translagenius.cache' => ['enabled' => false]]);
        $this->assertSame(OpenAiCompatibleProvider::class, get_class($this->provider()));

        config(['translagenius.cache' => ['enabled' => true]]);
        $this->assertSame(CachingProvider::class, get_class($this->provider()));
        $this->assertInstanceOf(OpenAiCompatibleProvider::class, $this->provider()->inner());
    }

    // --- single path -------------------------------------------------------

    public function test_same_text_and_locale_again_makes_no_request()
    {
        $first = $this->translate('Free shipping');
        $second = $this->translate('Free shipping');

        $this->assertSame(['test-api-url.com single ar'], $this->takeRequests());
        $this->assertSame($first->text, $second->text);
        $this->assertFalse($first->cached);
        $this->assertTrue($second->cached);
        $this->assertNull($second->promptTokens);
        $this->assertNull($second->requestId);
    }

    public function test_different_text_or_target_locale_requests_again()
    {
        $this->translate('Free shipping', 'ar');
        $this->translate('Free returns', 'ar');
        $this->translate('Free shipping', 'es');

        $this->assertCount(3, $this->takeRequests());
    }

    public function test_two_records_with_identical_text_share_hits()
    {
        $this->runJob(['name' => ['en' => 'Blue T-shirt'], 'description' => ['en' => 'Cotton']]);
        $this->assertCount(6, $this->takeRequests());

        $second = $this->runJob(['name' => ['en' => 'Blue T-shirt'], 'description' => ['en' => 'Cotton']]);

        $this->assertSame([], $this->takeRequests());
        $this->assertSame('test-api-url.com|Blue T-shirt|fr', TestTranslatableModel::find($second->id)->getTranslation('name', 'fr', false));
    }

    // --- batch path --------------------------------------------------------

    public function test_batch_with_two_of_three_cached_makes_one_single_request()
    {
        config(['translagenius.batch.enabled' => true]);
        $this->translate('Blue T-shirt', 'ar');
        $this->translate('Blue T-shirt', 'es');
        $this->takeRequests();

        $model = $this->runJob(['name' => ['en' => 'Blue T-shirt']], ['name']);

        $this->assertSame(['test-api-url.com single fr'], $this->takeRequests());
        $this->assertSame(
            ['ar', 'en', 'es', 'fr'],
            collect(TestTranslatableModel::find($model->id)->getTranslations('name'))->keys()->sort()->values()->all()
        );
    }

    public function test_batch_with_all_three_cached_makes_no_request()
    {
        config(['translagenius.batch.enabled' => true]);
        $this->runJob(['name' => ['en' => 'Blue T-shirt']], ['name']);
        $this->assertSame(['test-api-url.com batch ar,es,fr'], $this->takeRequests());

        $this->runJob(['name' => ['en' => 'Blue T-shirt']], ['name']);

        $this->assertSame([], $this->takeRequests());
    }

    public function test_batch_with_one_cached_sends_the_other_two_as_a_batch()
    {
        config(['translagenius.batch.enabled' => true]);
        $this->translate('Blue T-shirt', 'es');
        $this->takeRequests();

        $this->runJob(['name' => ['en' => 'Blue T-shirt']], ['name']);

        $this->assertSame(['test-api-url.com batch ar,fr'], $this->takeRequests());
    }

    // --- key sensitivity ---------------------------------------------------

    public static function outputAffectingSettings(): array
    {
        return [
            'model' => ['model', 'another-model'],
            'prompt' => ['prompt', 'Formally, translate from {source_locale} to {target_locale}.'],
            'temperature' => ['temperature', 0.9],
            'reasoning_effort' => ['reasoning_effort', 'low'],
            'url' => ['url', 'https://test-api-url.com/v2'],
            'body' => ['body', ['top_p' => 0.5]],
        ];
    }

    #[DataProvider('outputAffectingSettings')]
    public function test_changing_an_output_affecting_setting_misses(string $setting, mixed $value)
    {
        $this->translate('Free shipping');
        $this->takeRequests();

        config(["translagenius.providers.openrouter.{$setting}" => $value]);
        $result = $this->translate('Free shipping');

        $this->assertCount(1, $this->takeRequests(), "Changing {$setting} must miss.");
        $this->assertFalse($result->cached);
    }

    public function test_settings_that_do_not_affect_output_keep_hitting()
    {
        $this->translate('Free shipping');
        $this->takeRequests();

        config([
            'translagenius.providers.openrouter.key' => 'rotated-key',
            'translagenius.providers.openrouter.timeout' => 99,
            'translagenius.providers.openrouter.max_tokens' => 999,
            'translagenius.providers.openrouter.temperature' => '0.3', // same value as a string from .env
            'translagenius.retries.http' => 3,
        ]);

        $this->assertTrue($this->translate('Free shipping')->cached);
        $this->assertSame([], $this->takeRequests());
    }

    public function test_field_and_model_context_is_ignored_under_the_default_prompts()
    {
        $this->translate('Blue', 'ar', 'name', 'Product');

        $this->assertTrue($this->translate('Blue', 'ar', 'description', 'Product')->cached);
        $this->assertTrue($this->translate('Blue', 'ar', 'name', 'Category')->cached);
        $this->assertCount(1, $this->takeRequests());
    }

    public function test_field_context_splits_the_key_when_the_prompt_uses_field()
    {
        config(['translagenius.providers.openrouter.prompt' => 'Translate this {field} from {source_locale} to {target_locale}.']);

        $this->translate('Blue', 'ar', 'name', 'Product');
        $this->assertFalse($this->translate('Blue', 'ar', 'description', 'Product')->cached);
        // {model} isn't in the template, so another model still shares the field's entry.
        $this->assertTrue($this->translate('Blue', 'ar', 'name', 'Category')->cached);
        $this->assertCount(2, $this->takeRequests());
    }

    public function test_model_context_splits_the_key_when_a_prompt_uses_model()
    {
        config(['translagenius.batch.prompt' => 'For a {model}: from {source_locale} into {target_locales}, as json.']);

        $this->translate('Blue', 'ar', 'name', 'Product');
        $this->assertFalse($this->translate('Blue', 'ar', 'name', 'Category')->cached);
        $this->assertTrue($this->translate('Blue', 'ar', 'description', 'Product')->cached);
    }

    // --- fallback chains -----------------------------------------------------

    protected function useChain(): void
    {
        config([
            'translagenius.providers.primary' => ['driver' => OpenAiCompatibleProvider::class, 'key' => 'k', 'url' => 'https://primary.test/v1', 'model' => 'm'],
            'translagenius.providers.secondary' => ['driver' => OpenAiCompatibleProvider::class, 'key' => 'k', 'url' => 'https://secondary.test/v1', 'model' => 'm'],
            'translagenius.providers.resilient' => ['driver' => FallbackProvider::class, 'chain' => ['primary', 'secondary']],
            'translagenius.default' => 'resilient',
        ]);
    }

    public function test_a_whole_chain_is_wrapped_and_primary_results_are_cached()
    {
        $this->useChain();
        $this->assertInstanceOf(FallbackProvider::class, $this->provider()->inner());

        $this->translate('Free shipping');
        $second = $this->translate('Free shipping');

        $this->assertTrue($second->cached);
        $this->assertSame('primary', $second->provider);
        $this->assertSame(['primary.test single ar'], $this->takeRequests());
    }

    public function test_a_result_served_by_a_secondary_is_not_cached()
    {
        $this->useChain();
        $this->failingHosts['primary.test'] = 503;

        $outage = $this->translate('Free shipping');
        $this->assertSame('secondary', $outage->provider);
        $this->assertSame(['primary.test failed', 'secondary.test single ar'], $this->takeRequests());

        // Primary recovers: the identical text is requested again - from the
        // primary - and that result is what gets cached.
        unset($this->failingHosts['primary.test']);
        $recovered = $this->translate('Free shipping');
        $this->assertFalse($recovered->cached);
        $this->assertSame('primary', $recovered->provider);
        $this->assertSame(['primary.test single ar'], $this->takeRequests());

        $this->assertTrue($this->translate('Free shipping')->cached);
        $this->assertSame([], $this->takeRequests());
    }

    // --- nothing bad is cached -------------------------------------------------

    public function test_a_failure_is_not_cached()
    {
        $this->failingHosts['test-api-url.com'] = 401;

        try {
            $this->translate('Free shipping');
            $this->fail('Expected a failure.');
        } catch (TranslationFailedException) {
        }

        unset($this->failingHosts['test-api-url.com']);
        $this->assertFalse($this->translate('Free shipping')->cached);
        $this->assertSame(['test-api-url.com failed', 'test-api-url.com single ar'], $this->takeRequests());
    }

    public function test_a_truncated_single_reply_is_not_cached()
    {
        $this->override = fn () => Http::response(['choices' => [['message' => ['content' => 'half'], 'finish_reason' => 'length']]]);

        try {
            $this->translate('Free shipping');
            $this->fail('Expected a failure.');
        } catch (TranslationFailedException) {
        }

        $this->override = null;
        $this->assertFalse($this->translate('Free shipping')->cached);
    }

    public function test_a_truncated_batch_and_an_invalid_batch_value_are_not_cached()
    {
        config(['translagenius.batch.enabled' => true]);
        $replies = [
            ['content' => '{"ar": "x', 'finish_reason' => 'length'],
            ['content' => json_encode(['ar' => 'valid-ar', 'es' => '   ', 'fr' => 'valid-fr']), 'finish_reason' => 'stop'],
        ];
        $this->override = function () use (&$replies) {
            $reply = array_shift($replies);

            return Http::response(['choices' => [['message' => ['content' => $reply['content']], 'finish_reason' => $reply['finish_reason']]]]);
        };

        $batch = $this->provider()->translateBatch(new TranslationBatchRequest('Blue', 'en', ['ar', 'es', 'fr']));
        $this->assertTrue($batch->truncated);

        $batch = $this->provider()->translateBatch(new TranslationBatchRequest('Blue', 'en', ['ar', 'es', 'fr']));
        $this->assertSame(['ar' => 'valid-ar', 'fr' => 'valid-fr'], $batch->translations);

        $this->override = null;
        $this->assertTrue($this->translate('Blue', 'ar')->cached);
        $this->assertTrue($this->translate('Blue', 'fr')->cached);
        $this->assertFalse($this->translate('Blue', 'es')->cached, 'The blank "es" value must not have been cached.');
    }

    // --- events and usage ------------------------------------------------------

    public function test_events_fire_once_per_pair_on_hits_and_carry_the_cached_flag()
    {
        Event::fake([TranslationStarted::class, TranslationCompleted::class]);

        $this->runJob(['name' => ['en' => 'Blue'], 'description' => ['en' => 'Cotton']]);
        $this->runJob(['name' => ['en' => 'Blue'], 'description' => ['en' => 'Cotton']]);

        Event::assertDispatchedTimes(TranslationStarted::class, 12);
        Event::assertDispatchedTimes(TranslationCompleted::class, 12);
        $this->assertCount(6, Event::dispatched(TranslationCompleted::class, fn ($e) => $e->result->cached));
    }

    protected function usageAfterTwoIdenticalRecords(): array
    {
        (include __DIR__.'/../../src/database/migrations/create_translation_usage_logs_table.php.stub')->up();
        config(['translagenius.usage_tracking.enabled' => true]);
        config([
            'translagenius.providers.openrouter.cost_per_1k_prompt_tokens' => 1.0,
            'translagenius.providers.openrouter.cost_per_1k_completion_tokens' => 2.0,
        ]);

        $this->runJob(['name' => ['en' => 'Blue'], 'description' => ['en' => 'Cotton']]);
        $this->runJob(['name' => ['en' => 'Blue'], 'description' => ['en' => 'Cotton']]);

        $prompt = array_sum(array_column($this->billed, 'prompt_tokens'));
        $completion = array_sum(array_column($this->billed, 'completion_tokens'));
        $usage = [
            'requests' => count($this->billed),
            'billed_prompt' => $prompt,
            'logged_prompt' => (int) TranslationUsageLog::sum('prompt_tokens'),
            'billed_completion' => $completion,
            'logged_completion' => (int) TranslationUsageLog::sum('completion_tokens'),
            'billed_cost' => number_format($prompt / 1000 + $completion / 1000 * 2, 6, '.', ''),
            'logged_cost' => number_format((float) TranslationUsageLog::sum('estimated_cost'), 6, '.', ''),
            'rows' => TranslationUsageLog::count(),
            'cached_rows' => TranslationUsageLog::where('cached', true)->count(),
            'cached_rows_with_tokens_or_cost' => TranslationUsageLog::where('cached', true)
                ->where(fn ($q) => $q->whereNotNull('prompt_tokens')->orWhereNotNull('estimated_cost'))->count(),
        ];
        Schema::dropIfExists('translation_usage_logs');

        return $usage;
    }

    public function test_usage_totals_match_billing_with_hits_unbatched()
    {
        $usage = $this->usageAfterTwoIdenticalRecords();

        $this->assertSame(6, $usage['requests']);
        $this->assertSame($usage['billed_prompt'], $usage['logged_prompt']);
        $this->assertSame($usage['billed_completion'], $usage['logged_completion']);
        $this->assertSame($usage['billed_cost'], $usage['logged_cost']);
        $this->assertSame(12, $usage['rows']);
        $this->assertSame(6, $usage['cached_rows']);
        $this->assertSame(0, $usage['cached_rows_with_tokens_or_cost']);
    }

    public function test_usage_totals_match_billing_with_hits_batched()
    {
        config(['translagenius.batch.enabled' => true]);
        $usage = $this->usageAfterTwoIdenticalRecords();

        $this->assertSame(2, $usage['requests']);
        $this->assertSame($usage['billed_prompt'], $usage['logged_prompt']);
        $this->assertSame($usage['billed_completion'], $usage['logged_completion']);
        $this->assertSame($usage['billed_cost'], $usage['logged_cost']);
        $this->assertSame(12, $usage['rows']);
        $this->assertSame(6, $usage['cached_rows']);
        $this->assertSame(0, $usage['cached_rows_with_tokens_or_cost']);
    }

    public function test_a_ttl_of_zero_stores_forever_and_entries_use_the_versioned_prefix()
    {
        config(['translagenius.cache.ttl' => 0]);

        $key = $this->provider()->cacheKey('Free shipping', 'en', 'ar');
        $this->assertMatchesRegularExpression('/^translagenius:v1:[0-9a-f]{64}$/', $key);

        $this->translate('Free shipping');
        $this->travel(400)->days();

        $this->assertTrue($this->translate('Free shipping')->cached);
    }

    /**
     * Text that isn't valid UTF-8 can't be JSON-encoded; two such texts must
     * still get keys of their own, never one shared key.
     */
    public function test_text_that_is_not_valid_utf8_gets_a_key_of_its_own()
    {
        $provider = $this->provider();

        $first = $provider->cacheKey("caf\xe9", 'en', 'ar');
        $second = $provider->cacheKey("na\xefve", 'en', 'ar');

        $this->assertNotSame($first, $second);
        $this->assertSame($first, $provider->cacheKey("caf\xe9", 'en', 'ar'));
        $this->assertMatchesRegularExpression('/^translagenius:v1:[0-9a-f]{64}$/', $first);
    }

    public function test_entries_expire_after_the_ttl()
    {
        $this->translate('Free shipping');
        $this->travel(3601)->seconds();

        $this->assertFalse($this->translate('Free shipping')->cached);
    }
}
