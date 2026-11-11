<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Models\TranslationUsageLog;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use CodingPartners\TranslaGenius\TranslaGeniusServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Usage tracking through the shipped config, configured by env vars only:
 * switching to a non-default preset must log that preset's name and cost
 * it with that preset's own rates.
 */
class ShippedPresetUsageTrackingTest extends Orchestra
{
    private const ENV = [
        'TRANSLATION_USAGE_TRACKING_ENABLED' => 'true',
        'TRANSLATION_PROVIDER' => 'gemini',
        'GEMINI_API_KEY' => 'g-key',
        'GEMINI_COST_PER_1K_PROMPT_TOKENS' => '0.5',
        'GEMINI_COST_PER_1K_COMPLETION_TOKENS' => '2',
        // Rates on another preset must not leak into gemini's rows.
        'OPENROUTER_COST_PER_1K_PROMPT_TOKENS' => '99',
    ];

    protected function getPackageProviders($app)
    {
        return [TranslaGeniusServiceProvider::class];
    }

    /**
     * Env must be in place before the service provider boots: that is when
     * usage_tracking.enabled decides whether the listener is registered.
     */
    protected function getEnvironmentSetUp($app)
    {
        foreach (self::ENV as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        $app['config']->set('translagenius', require __DIR__.'/../../src/config/translagenius.php');
        $app['config']->set('translagenius.supported_languages', ['en', 'ar']);
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        (include __DIR__.'/../database/migrations/2024_01_01_000000_create_test_tables.php')->up();
        (include __DIR__.'/../../src/database/migrations/create_translation_usage_logs_table.php.stub')->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_translatable_models');
        Schema::dropIfExists('translation_usage_logs');

        foreach (array_keys(self::ENV) as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    public function test_non_default_preset_is_logged_with_its_own_name_and_rates()
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'مرحبا']]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            ]),
        ]);

        TestTranslatableModel::create([
            'name' => ['en' => 'Hello'],
            'description' => ['en' => 'World'],
        ]);

        // 2 fields x 1 target language (ar).
        $this->assertSame(2, TranslationUsageLog::count());

        $row = TranslationUsageLog::where('field', 'name')->first();
        $this->assertSame('gemini', $row->provider);
        $this->assertSame(100, $row->prompt_tokens);
        $this->assertSame(50, $row->completion_tokens);
        // (100/1000)*0.5 + (50/1000)*2 = 0.05 + 0.1 = 0.15
        $this->assertSame('0.150000', $row->estimated_cost);

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://generativelanguage.googleapis.com/'));
    }
}
