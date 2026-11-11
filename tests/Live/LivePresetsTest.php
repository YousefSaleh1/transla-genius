<?php

namespace CodingPartners\TranslaGenius\Tests\Live;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationBatchRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\TranslaGeniusServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Opt-in smoke tests against the real provider APIs: one short translation
 * per shipped preset, using the shipped config and your own keys.
 *
 * Excluded from the default run (phpunit.xml) and from CI. Each preset is
 * skipped unless its key is in the environment, e.g.:
 *
 *     OPENAI_API_KEY=sk-... GEMINI_API_KEY=... vendor/bin/phpunit --group live
 *
 * These make real, billed API calls (a few tokens each).
 */
#[Group('live')]
class LivePresetsTest extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [TranslaGeniusServiceProvider::class];
    }

    /**
     * preset => [env vars that must all be set, human-readable requirement]
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function presets(): array
    {
        return [
            'openrouter' => ['openrouter', ['OPENROUTER_API_KEY|TRANSLATION_API_KEY']],
            'openai' => ['openai', ['OPENAI_API_KEY']],
            'gemini' => ['gemini', ['GEMINI_API_KEY']],
            'deepseek' => ['deepseek', ['DEEPSEEK_API_KEY']],
            'mistral' => ['mistral', ['MISTRAL_API_KEY']],
            'custom' => ['custom', ['TRANSLATION_API_URL', 'TRANSLATION_MODEL']],
        ];
    }

    /**
     * @param  array<int, string>  $required  Each item is one env var, or
     *                                        alternatives separated by "|".
     */
    /**
     * @param  array<int, string>  $required
     */
    protected function skipUnlessConfigured(string $preset, array $required): void
    {
        foreach ($required as $alternatives) {
            $set = array_filter(explode('|', $alternatives), fn ($name) => (string) getenv($name) !== '');

            if ($set === []) {
                $this->markTestSkipped("Set {$alternatives} to run the live {$preset} test.");
            }
        }
    }

    #[DataProvider('presets')]
    public function test_preset_translates_a_short_string(string $preset, array $required)
    {
        $this->skipUnlessConfigured($preset, $required);

        putenv("TRANSLATION_PROVIDER={$preset}");
        $_ENV['TRANSLATION_PROVIDER'] = $_SERVER['TRANSLATION_PROVIDER'] = $preset;
        $this->app['config']->set('translagenius', require __DIR__.'/../../src/config/translagenius.php');

        Http::record();

        try {
            $result = $this->app->make(TranslationProviderInterface::class)
                ->translate(new TranslationRequest('Good morning, how are you today?', 'en', 'es'));
        } finally {
            putenv('TRANSLATION_PROVIDER');
            unset($_ENV['TRANSLATION_PROVIDER'], $_SERVER['TRANSLATION_PROVIDER']);
        }

        $this->assertNotSame('', trim($result->text));
        $this->assertSame($preset, $result->provider);

        [$request, $response] = Http::recorded()->last();
        $this->assertNotSame('length', $response->json('choices.0.finish_reason'), 'Translation was truncated.');

        // The preset's configured reasoning_effort (default or *_REASONING_EFFORT)
        // really went out, so a passing run also proves the API accepts it.
        $configuredEffort = trim((string) config("translagenius.providers.{$preset}.reasoning_effort"));
        $this->assertSame(
            $configuredEffort === '' ? null : $configuredEffort,
            $request->data()['reasoning_effort'] ?? null
        );

        fwrite(STDERR, sprintf(
            "\n  [live %s] %s, reasoning_effort %s -> \"%s\" (finish_reason: %s, tokens: %s/%s)\n",
            $preset,
            config("translagenius.providers.{$preset}.model"),
            $configuredEffort === '' ? 'not sent' : $configuredEffort,
            $result->text,
            var_export($response->json('choices.0.finish_reason'), true),
            var_export($result->promptTokens, true),
            var_export($result->completionTokens, true)
        ));
    }

    /**
     * One batch request per preset: 3 target locales, all 3 must come back
     * valid from that single request (batching is forced on for the preset,
     * whatever TRANSLATION_BATCH says).
     *
     * @param  array<int, string>  $required
     */
    #[DataProvider('presets')]
    public function test_preset_translates_three_locales_in_one_batch_request(string $preset, array $required)
    {
        $this->skipUnlessConfigured($preset, $required);

        $this->app['config']->set('translagenius', require __DIR__.'/../../src/config/translagenius.php');
        $this->app['config']->set("translagenius.providers.{$preset}.batch", true);
        $this->app['config']->set('translagenius.default', $preset);

        Http::record();

        $provider = $this->app->make(TranslationProviderInterface::class);
        $batch = $provider->translateBatch(new TranslationBatchRequest(
            'Good morning, how are you today?',
            'en',
            ['es', 'fr', 'de'],
        ));

        // On a real network a transient 429/503 followed by the provider's own
        // retry is normal; what batching guarantees is one *successful* request.
        $attempts = Http::recorded();
        $successful = $attempts->filter(fn ($pair) => $pair[1]->successful())->values();
        $this->assertCount(1, $successful, 'Expected exactly one successful (2xx) batch response.');
        $this->assertSame(['es', 'fr', 'de'], array_keys($batch->translations), 'Every locale must come back valid.');
        foreach ($batch->translations as $text) {
            $this->assertNotSame('', trim($text));
        }
        $this->assertSame($preset, $batch->provider);

        [$request, $response] = $successful->first();
        $this->assertNotSame('length', $response->json('choices.0.finish_reason'));

        $statuses = $attempts->map(fn ($pair) => $pair[1]->status())->implode(', ');

        fwrite(STDERR, sprintf(
            "\n  [live batch %s] %s%s, response_format %s -> %s (tokens: %s/%s)\n",
            $preset,
            $attempts->count() > 1 ? "attempts: {$statuses}; " : '',
            config("translagenius.providers.{$preset}.model"),
            json_encode($request->data()['response_format']['type'] ?? 'none'),
            json_encode($batch->translations, JSON_UNESCAPED_UNICODE),
            var_export($batch->promptTokens, true),
            var_export($batch->completionTokens, true)
        ));
    }
}
