<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use CodingPartners\TranslaGenius\TranslaGeniusServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Exercises the config file the package actually ships
 * (src/config/translaGenius.php), driven only by environment variables -
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
     * @param array<string, string> $env
     */
    protected function withEnv(array $env): void
    {
        foreach ($env as $key => $value) {
            $this->envKeys[] = $key;
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        $this->app['config']->set('translaGenius', require __DIR__ . '/../../src/config/translaGenius.php');
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

    protected function translate(): \CodingPartners\TranslaGenius\DTOs\TranslationResult
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
            && $r['max_completion_tokens'] === 200
            && !isset($r['max_tokens']));
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
            && $r['max_tokens'] === 200);
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
            && !$r->hasHeader('Authorization'));
    }

    /**
     * Someone on the default today, with only the legacy TRANSLATION_* vars
     * set, must keep getting exactly the same requests.
     */
    public function test_default_stays_openrouter_and_legacy_env_vars_keep_working()
    {
        $this->fakeEndpoints();
        $this->withEnv(['TRANSLATION_API_KEY' => 'legacy-key', 'TRANSLATION_MODEL' => 'openai/gpt-4o-mini']);

        $this->assertSame('openrouter', config('translaGenius.default'));
        $result = $this->translate();

        $this->assertSame('openrouter', $result->provider);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $r->header('Authorization') === ['Bearer legacy-key']
            && $r['model'] === 'openai/gpt-4o-mini'
            && $r['max_tokens'] === 200
            && !$r->hasHeader('HTTP-Referer'));
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
}
