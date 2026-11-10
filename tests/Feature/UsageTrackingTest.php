<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\Events\TranslationCompleted;
use CodingPartners\TranslaGenius\Models\TranslationUsageLog;
use CodingPartners\TranslaGenius\Tests\Support\FakeConfigurableProvider;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use CodingPartners\TranslaGenius\TranslaGeniusServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Phase 4e, translaGenius.usage_tracking.enabled = true: proves
 * Listeners\LogTranslationUsage is actually wired up (not just "would
 * write correctly if it ran") and that it writes a real
 * Models\TranslationUsageLog row per Events\TranslationCompleted through
 * the real automatic flow - including the null-cost case when no rate is
 * configured for the reporting provider, per the config's own "null, never
 * 0" contract.
 *
 * `usage_tracking.enabled` is set in getEnvironmentSetUp() rather than
 * inside a test method: TranslaGeniusServiceProvider::boot() reads it once,
 * at boot time, to decide whether to register the listener at all -
 * setting it later (after boot already ran) would be too late. See
 * UsageTrackingDisabledTest for the inverse (default/false) case.
 */
class UsageTrackingTest extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [TranslaGeniusServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('translaGenius.usage_tracking.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        FakeConfigurableProvider::reset();

        Config::set('translaGenius.supported_languages', ['en', 'ar', 'es']);

        $modelsMigration = include __DIR__ . '/../database/migrations/2024_01_01_000000_create_test_tables.php';
        $modelsMigration->up();

        $usageLogMigration = include __DIR__ . '/../../src/database/migrations/create_translation_usage_logs_table.php.stub';
        $usageLogMigration->up();
    }

    protected function tearDown(): void
    {
        FakeConfigurableProvider::reset();
        Schema::dropIfExists('test_translatable_models');
        Schema::dropIfExists('translation_usage_logs');
        parent::tearDown();
    }

    public function test_listener_is_registered_when_usage_tracking_is_enabled()
    {
        $this->assertTrue($this->app['events']->hasListeners(TranslationCompleted::class));
    }

    public function test_completed_translations_write_usage_log_rows_with_computed_cost()
    {
        Config::set('translaGenius.providers.fake', [
            'cost_per_1k_prompt_tokens' => 1.0,
            'cost_per_1k_completion_tokens' => 2.0,
        ]);

        $this->app->singleton(
            TranslationProviderInterface::class,
            fn () => new FakeConfigurableProvider([
                'id' => 'fake',
                'prefix' => 'x:',
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
            ])
        );

        $model = TestTranslatableModel::create([
            'name' => ['en' => 'Hello'],
            'description' => ['en' => 'World'],
        ]);

        // 2 fields x 2 target languages (ar, es) = 4 completed translations.
        $this->assertSame(4, TranslationUsageLog::count());

        $row = TranslationUsageLog::where('field', 'name')->where('target_locale', 'es')->first();

        $this->assertNotNull($row);
        $this->assertSame('fake', $row->provider);
        $this->assertSame(TestTranslatableModel::class, $row->model_class);
        $this->assertSame((string) $model->id, $row->model_id);
        $this->assertSame('en', $row->source_locale);
        $this->assertSame('es', $row->target_locale);
        $this->assertSame(100, $row->prompt_tokens);
        $this->assertSame(50, $row->completion_tokens);

        // (100/1000)*1.0 + (50/1000)*2.0 = 0.1 + 0.1 = 0.2
        $this->assertSame('0.200000', $row->estimated_cost);
    }

    public function test_estimated_cost_is_null_not_zero_when_no_rate_is_configured_for_the_provider()
    {
        // Deliberately no cost rates on a translaGenius.providers.fake entry.
        $this->app->singleton(
            TranslationProviderInterface::class,
            fn () => new FakeConfigurableProvider([
                'id' => 'fake',
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
            ])
        );

        TestTranslatableModel::create([
            'name' => ['en' => 'Hello'],
            'description' => ['en' => 'World'],
        ]);

        $this->assertGreaterThan(0, TranslationUsageLog::count());
        $this->assertSame(0, TranslationUsageLog::whereNotNull('estimated_cost')->count());
    }

    public function test_estimated_cost_is_null_when_rate_is_configured_but_provider_reports_no_tokens()
    {
        Config::set('translaGenius.providers.fake', [
            'cost_per_1k_prompt_tokens' => 1.0,
            'cost_per_1k_completion_tokens' => 2.0,
        ]);

        // No prompt_tokens/completion_tokens configured on the fake - it
        // reports null for both, same as a provider that doesn't surface
        // usage metadata at all.
        $this->app->singleton(
            TranslationProviderInterface::class,
            fn () => new FakeConfigurableProvider(['id' => 'fake'])
        );

        TestTranslatableModel::create([
            'name' => ['en' => 'Hello'],
            'description' => ['en' => 'World'],
        ]);

        $this->assertGreaterThan(0, TranslationUsageLog::count());
        $this->assertSame(0, TranslationUsageLog::whereNotNull('estimated_cost')->count());
    }
}
