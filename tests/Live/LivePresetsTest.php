<?php

namespace CodingPartners\TranslaGenius\Tests\Live;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
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
     * @param array<int, string> $required Each item is one env var, or
     *        alternatives separated by "|".
     */
    #[DataProvider('presets')]
    public function test_preset_translates_a_short_string(string $preset, array $required)
    {
        foreach ($required as $alternatives) {
            $set = array_filter(explode('|', $alternatives), fn ($name) => (string) getenv($name) !== '');

            if ($set === []) {
                $this->markTestSkipped("Set {$alternatives} to run the live {$preset} test.");
            }
        }

        putenv("TRANSLATION_PROVIDER={$preset}");
        $_ENV['TRANSLATION_PROVIDER'] = $_SERVER['TRANSLATION_PROVIDER'] = $preset;
        $this->app['config']->set('translaGenius', require __DIR__ . '/../../src/config/translaGenius.php');
        $this->app->forgetInstance(TranslationProviderInterface::class);

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

        [, $response] = Http::recorded()->last();
        $this->assertNotSame('length', $response->json('choices.0.finish_reason'), 'Translation was truncated.');

        fwrite(STDERR, sprintf(
            "\n  [live %s] %s -> \"%s\" (finish_reason: %s, tokens: %s/%s)\n",
            $preset,
            config("translaGenius.providers.{$preset}.model"),
            $result->text,
            var_export($response->json('choices.0.finish_reason'), true),
            var_export($result->promptTokens, true),
            var_export($result->completionTokens, true)
        ));
    }
}
