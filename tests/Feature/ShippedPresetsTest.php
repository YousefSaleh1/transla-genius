<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationBatchRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationBatchResult;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;
use CodingPartners\TranslaGenius\Providers\CachingProvider;
use CodingPartners\TranslaGenius\Providers\FallbackProvider;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use CodingPartners\TranslaGenius\TranslaGeniusServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Exercises the config file the package actually ships
 * (src/config/translagenius.php), driven only by environment variables -
 * the "zero-code" path. Each test sets env vars, reloads the shipped
 * config, and resolves the provider fresh from the container.
 */
class ShippedPresetsTest extends Orchestra
{
    /** @var array<int, string> */
    private array $envKeys = [];

    protected function getPackageProviders($app)
    {
        return [TranslaGeniusServiceProvider::class];
    }

    protected function tearDown(): void
    {
        foreach ($this->envKeys as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    /**
     * Set env vars, then reload the shipped config so env() sees them.
     *
     * @param  array<string, string>  $env
     */
    protected function withEnv(array $env): void
    {
        foreach ($env as $key => $value) {
            $this->envKeys[] = $key;
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        $this->app['config']->set('translagenius', require __DIR__.'/../../src/config/translagenius.php');
        $this->app->forgetInstance(TranslationProviderInterface::class);
    }

    protected function fakeEndpoints(): void
    {
        $reply = fn (string $text) => Http::response([
            'choices' => [['message' => ['content' => $text]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]);

        Http::fake([
            'api.openai.com/*' => $reply('from-openai'),
            'generativelanguage.googleapis.com/*' => $reply('from-gemini'),
            'llm.internal.test/*' => $reply('from-custom'),
            'openrouter.ai/*' => $reply('from-openrouter'),
        ]);
    }

    protected function translate(): TranslationResult
    {
        return $this->app->make(TranslationProviderInterface::class)
            ->translate(new TranslationRequest('Hello', 'en', 'ar'));
    }

    public function test_openai_preset_is_selected_by_env_alone()
    {
        $this->fakeEndpoints();
        $this->withEnv(['TRANSLATION_PROVIDER' => 'openai', 'OPENAI_API_KEY' => 'sk-openai']);

        $this->assertInstanceOf(OpenAiCompatibleProvider::class, $this->app->make(TranslationProviderInterface::class));
        $result = $this->translate();

        $this->assertSame('from-openai', $result->text);
        $this->assertSame('openai', $result->provider);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/chat/completions'
            && $r->header('Authorization') === ['Bearer sk-openai']
            && $r['model'] === 'gpt-6-luna'
            && ! isset($r['max_completion_tokens'])
            && ! isset($r['max_tokens']));
    }

    public function test_gemini_preset_is_selected_by_env_alone()
    {
        $this->fakeEndpoints();
        $this->withEnv(['TRANSLATION_PROVIDER' => 'gemini', 'GEMINI_API_KEY' => 'g-key']);

        $result = $this->translate();

        $this->assertSame('from-gemini', $result->text);
        $this->assertSame('gemini', $result->provider);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'
            && $r->header('Authorization') === ['Bearer g-key']
            && $r['model'] === 'gemini-3.5-flash-lite'
            && ! isset($r['max_tokens']));
    }

    public function test_custom_preset_uses_the_generic_translation_env_vars()
    {
        $this->fakeEndpoints();
        $this->withEnv([
            'TRANSLATION_PROVIDER' => 'custom',
            'TRANSLATION_API_URL' => 'https://llm.internal.test/v1/chat/completions',
            'TRANSLATION_API_KEY' => 'local-key',
            'TRANSLATION_MODEL' => 'llama3.1',
        ]);

        $result = $this->translate();

        $this->assertSame('from-custom', $result->text);
        $this->assertSame('custom', $result->provider);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://llm.internal.test/v1/chat/completions'
            && $r->header('Authorization') === ['Bearer local-key']
            && $r['model'] === 'llama3.1');
    }

    public function test_custom_preset_can_send_the_key_in_another_header()
    {
        $this->fakeEndpoints();
        $this->withEnv([
            'TRANSLATION_PROVIDER' => 'custom',
            'TRANSLATION_API_URL' => 'https://llm.internal.test/openai/v1/chat/completions',
            'TRANSLATION_API_KEY' => 'azure-style-key',
            'TRANSLATION_MODEL' => 'my-deployment',
            'TRANSLATION_AUTH_HEADER' => 'api-key',
        ]);

        $this->translate();

        Http::assertSent(fn (Request $r) => $r->header('api-key') === ['azure-style-key']
            && ! $r->hasHeader('Authorization'));
    }

    /**
     * Someone on the default today, with only the legacy TRANSLATION_* vars
     * set, must keep getting exactly the same requests.
     */
    public function test_default_stays_openrouter_and_legacy_env_vars_keep_working()
    {
        $this->fakeEndpoints();
        $this->withEnv(['TRANSLATION_API_KEY' => 'legacy-key', 'TRANSLATION_MODEL' => 'openai/gpt-4o-mini']);

        $this->assertSame('openrouter', config('translagenius.default'));
        $result = $this->translate();

        $this->assertSame('openrouter', $result->provider);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $r->header('Authorization') === ['Bearer legacy-key']
            && $r['model'] === 'openai/gpt-4o-mini'
            && ! isset($r['max_tokens'])
            && ! $r->hasHeader('HTTP-Referer'));
    }

    public function test_openrouter_attribution_headers_come_from_env()
    {
        $this->fakeEndpoints();
        $this->withEnv([
            'OPENROUTER_API_KEY' => 'or-key',
            'OPENROUTER_SITE_URL' => 'https://myapp.test',
            'OPENROUTER_APP_NAME' => 'My App',
        ]);

        $this->translate();

        Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Bearer or-key']
            && $r->header('HTTP-Referer') === ['https://myapp.test']
            && $r->header('X-OpenRouter-Title') === ['My App']);
    }

    /** @param array<string, int> $counts host => expected request count */
    protected function assertRequestCounts(array $counts): void
    {
        foreach ($counts as $host => $expected) {
            $actual = Http::recorded(fn (Request $r) => parse_url($r->url(), PHP_URL_HOST) === $host)->count();
            $this->assertSame($expected, $actual, "Requests sent to {$host}");
        }
    }

    public function test_env_only_fallback_serves_from_secondary_when_primary_fails()
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'down']], 503),
            'generativelanguage.googleapis.com/*' => Http::response(['choices' => [['message' => ['content' => 'from-gemini']]]]),
        ]);
        $this->withEnv([
            'TRANSLATION_PROVIDER' => 'fallback',
            'TRANSLATION_FALLBACK_CHAIN' => 'openai,gemini',
            'OPENAI_API_KEY' => 'sk-openai',
            'GEMINI_API_KEY' => 'g-key',
        ]);

        $result = $this->translate();

        $this->assertSame('from-gemini', $result->text);
        $this->assertSame('gemini', $result->provider);
        // Chain members get TRANSLATION_FALLBACK_HTTP_RETRIES (default 0)
        // HTTP retries: the primary is tried once, then the secondary once.
        $this->assertRequestCounts(['api.openai.com' => 1, 'generativelanguage.googleapis.com' => 1]);
    }

    public function test_env_only_fallback_never_calls_secondary_when_primary_succeeds()
    {
        $this->fakeEndpoints();
        $this->withEnv([
            'TRANSLATION_PROVIDER' => 'fallback',
            'TRANSLATION_FALLBACK_CHAIN' => 'openai,gemini',
            'OPENAI_API_KEY' => 'sk-openai',
            'GEMINI_API_KEY' => 'g-key',
        ]);

        $result = $this->translate();

        $this->assertSame('openai', $result->provider);
        $this->assertRequestCounts(['api.openai.com' => 1, 'generativelanguage.googleapis.com' => 0]);
    }

    public function test_fallback_chain_env_trims_whitespace_and_ignores_empty_items()
    {
        $this->fakeEndpoints();
        $this->withEnv([
            'TRANSLATION_PROVIDER' => 'fallback',
            'TRANSLATION_FALLBACK_CHAIN' => ' openai , ,gemini ,',
            'OPENAI_API_KEY' => 'sk-openai',
            'GEMINI_API_KEY' => 'g-key',
        ]);

        $this->assertSame('openai', $this->translate()->provider);
    }

    public function test_fallback_without_chain_env_has_a_clear_error()
    {
        $this->withEnv(['TRANSLATION_PROVIDER' => 'fallback']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fallback provider [fallback] has no `chain` configured');

        $this->app->make(TranslationProviderInterface::class);
    }

    public function test_fallback_chain_env_with_a_single_provider_is_rejected()
    {
        $this->withEnv(['TRANSLATION_PROVIDER' => 'fallback', 'TRANSLATION_FALLBACK_CHAIN' => 'openai, ']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least 2 providers');

        $this->app->make(TranslationProviderInterface::class);
    }

    public function test_fallback_chain_env_referencing_itself_is_rejected_as_a_cycle()
    {
        $this->withEnv(['TRANSLATION_PROVIDER' => 'fallback', 'TRANSLATION_FALLBACK_CHAIN' => 'openai,fallback']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('provider chain forms a cycle: fallback -> fallback');

        $this->app->make(TranslationProviderInterface::class);
    }

    public function test_explicit_max_tokens_env_is_sent_in_each_presets_own_field()
    {
        $this->fakeEndpoints();
        $this->withEnv(['TRANSLATION_PROVIDER' => 'openai', 'OPENAI_API_KEY' => 'k', 'TRANSLATION_MAX_TOKENS' => '800']);
        $this->translate();
        Http::assertSent(fn (Request $r) => $r['max_completion_tokens'] === 800 && ! isset($r['max_tokens']));

        $this->withEnv(['TRANSLATION_PROVIDER' => 'gemini', 'GEMINI_API_KEY' => 'k']);
        $this->translate();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'googleapis') && $r['max_tokens'] === 800);
    }

    public function test_empty_max_tokens_env_sends_no_limit()
    {
        $this->fakeEndpoints();
        $this->withEnv(['TRANSLATION_PROVIDER' => 'gemini', 'GEMINI_API_KEY' => 'k', 'TRANSLATION_MAX_TOKENS' => '']);

        $this->translate();

        Http::assertSent(fn (Request $r) => ! isset($r['max_tokens']) && ! isset($r['max_completion_tokens']));
    }

    public function test_temperature_is_omitted_by_default_except_on_openrouter()
    {
        $this->fakeEndpoints();

        $this->withEnv(['TRANSLATION_PROVIDER' => 'openai', 'OPENAI_API_KEY' => 'k']);
        $this->translate();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.openai.com') && ! array_key_exists('temperature', $r->data()));

        $this->withEnv(['TRANSLATION_PROVIDER' => 'openrouter', 'OPENROUTER_API_KEY' => 'k']);
        $this->translate();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'openrouter.ai') && $r['temperature'] === 0.3);
    }

    public function test_empty_temperature_env_omits_the_field_even_on_openrouter()
    {
        $this->fakeEndpoints();
        $this->withEnv(['OPENROUTER_API_KEY' => 'k', 'TRANSLATION_TEMPERATURE' => '']);

        $this->translate();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'openrouter.ai') && ! array_key_exists('temperature', $r->data()));
    }

    public function test_explicit_temperature_env_is_sent_to_any_preset()
    {
        $this->fakeEndpoints();
        $this->withEnv(['TRANSLATION_PROVIDER' => 'gemini', 'GEMINI_API_KEY' => 'k', 'TRANSLATION_TEMPERATURE' => '0.1']);

        $this->translate();

        Http::assertSent(fn (Request $r) => $r['temperature'] === 0.1);
    }

    public function test_reasoning_effort_defaults_per_preset()
    {
        $this->fakeEndpoints();
        Http::fake(['api.deepseek.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']]])]);

        $expected = ['openai' => 'none', 'deepseek' => 'none', 'gemini' => null, 'openrouter' => null];
        $hosts = ['openai' => 'api.openai.com', 'deepseek' => 'api.deepseek.com', 'gemini' => 'googleapis', 'openrouter' => 'openrouter.ai'];

        foreach ($expected as $preset => $effort) {
            $this->withEnv(['TRANSLATION_PROVIDER' => $preset, strtoupper($preset).'_API_KEY' => 'k']);
            $this->translate();

            Http::assertSent(fn (Request $r) => str_contains($r->url(), $hosts[$preset])
                && ($effort === null ? ! array_key_exists('reasoning_effort', $r->data()) : $r['reasoning_effort'] === $effort));
        }
    }

    public function test_reasoning_effort_env_overrides_and_empty_omits()
    {
        $this->fakeEndpoints();

        $this->withEnv(['TRANSLATION_PROVIDER' => 'gemini', 'GEMINI_API_KEY' => 'k', 'GEMINI_REASONING_EFFORT' => 'low']);
        $this->translate();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'googleapis') && $r['reasoning_effort'] === 'low');

        $this->withEnv(['TRANSLATION_PROVIDER' => 'openai', 'OPENAI_API_KEY' => 'k', 'OPENAI_REASONING_EFFORT' => '']);
        $this->translate();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.openai.com') && ! array_key_exists('reasoning_effort', $r->data()));
    }

    protected function batchReply(): void
    {
        Http::fake(fn (Request $r) => Http::response(['choices' => [['message' => [
            'content' => json_encode(['ar' => 'A', 'es' => 'E']),
        ], 'finish_reason' => 'stop']]]));
    }

    protected function translateBatch(): TranslationBatchResult
    {
        $provider = $this->app->make(TranslationProviderInterface::class);
        $this->assertTrue($provider->supportsBatching());

        return $provider->translateBatch(new TranslationBatchRequest('Hello', 'en', ['ar', 'es']));
    }

    public function test_batching_is_off_by_default_and_on_with_translation_batch()
    {
        $this->withEnv(['TRANSLATION_PROVIDER' => 'gemini', 'GEMINI_API_KEY' => 'k']);
        $this->assertFalse($this->app->make(TranslationProviderInterface::class)->supportsBatching());

        $this->withEnv(['TRANSLATION_BATCH' => 'true']);
        $this->assertTrue($this->app->make(TranslationProviderInterface::class)->supportsBatching());

        $this->withEnv(['GEMINI_BATCH' => 'false']);
        $this->assertFalse($this->app->make(TranslationProviderInterface::class)->supportsBatching());
    }

    public function test_openai_batch_uses_a_strict_json_schema_of_the_requested_locales()
    {
        $this->batchReply();
        $this->withEnv(['TRANSLATION_PROVIDER' => 'openai', 'OPENAI_API_KEY' => 'k', 'OPENAI_BATCH' => 'true']);

        $this->assertSame(['ar' => 'A', 'es' => 'E'], $this->translateBatch()->translations);

        Http::assertSent(fn (Request $r) => $r['response_format'] === [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'translations',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => ['ar' => ['type' => 'string'], 'es' => ['type' => 'string']],
                    'required' => ['ar', 'es'],
                    'additionalProperties' => false,
                ],
            ],
        ]);
    }

    public function test_deepseek_batch_uses_json_object_mode_and_the_prompt_says_json()
    {
        $this->batchReply();
        $this->withEnv(['TRANSLATION_PROVIDER' => 'deepseek', 'DEEPSEEK_API_KEY' => 'k', 'TRANSLATION_BATCH' => 'true']);

        $this->translateBatch();

        Http::assertSent(fn (Request $r) => $r['response_format'] === ['type' => 'json_object']
            && str_contains($r['messages'][0]['content'], 'json'));
    }

    public function test_gemini_batch_relies_on_the_prompt_alone()
    {
        $this->batchReply();
        $this->withEnv(['TRANSLATION_PROVIDER' => 'gemini', 'GEMINI_API_KEY' => 'k', 'TRANSLATION_BATCH' => 'true']);

        $this->translateBatch();

        Http::assertSent(fn (Request $r) => ! array_key_exists('response_format', $r->data()));
    }

    public function test_translation_cache_env_wraps_the_resolved_provider_or_chain()
    {
        $this->withEnv(['TRANSLATION_PROVIDER' => 'gemini', 'GEMINI_API_KEY' => 'k']);
        $this->assertSame(OpenAiCompatibleProvider::class, get_class($this->app->make(TranslationProviderInterface::class)));

        $this->withEnv(['TRANSLATION_CACHE' => 'true']);
        $provider = $this->app->make(TranslationProviderInterface::class);
        $this->assertInstanceOf(CachingProvider::class, $provider);
        $this->assertInstanceOf(OpenAiCompatibleProvider::class, $provider->inner());

        $this->withEnv([
            'TRANSLATION_PROVIDER' => 'fallback',
            'TRANSLATION_FALLBACK_CHAIN' => 'openai,gemini',
            'OPENAI_API_KEY' => 'k',
        ]);
        $provider = $this->app->make(TranslationProviderInterface::class);
        $this->assertInstanceOf(CachingProvider::class, $provider);
        $this->assertInstanceOf(FallbackProvider::class, $provider->inner());
    }
}
