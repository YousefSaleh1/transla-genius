<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\Events\TranslationCompleted;
use CodingPartners\TranslaGenius\Tests\Support\FakeConfigurableProvider;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use CodingPartners\TranslaGenius\TranslaGeniusServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Phase 4e, translagenius.usage_tracking left at its default (unset/false):
 * proves the feature is a genuine no-op, not just quiet - Listeners\
 * LogTranslationUsage is never registered against Events\TranslationCompleted
 * at all (checked directly on the event dispatcher, not inferred from "no
 * row was written"), and the real automatic translation flow completes
 * normally with no dependency on the `translation_usage_logs` table -
 * this test deliberately never runs that migration, proving the rest of
 * the suite doesn't need it just because the feature exists in the package.
 */
class UsageTrackingDisabledTest extends Orchestra
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
        // translagenius.usage_tracking.enabled is intentionally left at
        // its shipped default (false) here - not touched at all.
    }

    protected function setUp(): void
    {
        parent::setUp();

        FakeConfigurableProvider::reset();

        Config::set('translagenius.supported_languages', ['en', 'ar', 'es']);

        $modelsMigration = include __DIR__.'/../database/migrations/2024_01_01_000000_create_test_tables.php';
        $modelsMigration->up();

        // Deliberately NOT running create_translation_usage_logs_table -
        // proving that table's absence doesn't break anything when the
        // feature is off.
    }

    protected function tearDown(): void
    {
        FakeConfigurableProvider::reset();
        Schema::dropIfExists('test_translatable_models');
        parent::tearDown();
    }

    public function test_usage_tracking_config_defaults_to_disabled()
    {
        $this->assertFalse(config('translagenius.usage_tracking.enabled'));
    }

    public function test_listener_is_not_registered_when_usage_tracking_is_disabled()
    {
        $this->assertFalse($this->app['events']->hasListeners(TranslationCompleted::class));
    }

    public function test_automatic_translation_flow_completes_normally_without_the_usage_log_table()
    {
        $this->app->singleton(
            TranslationProviderInterface::class,
            fn () => new FakeConfigurableProvider(['id' => 'fake', 'prefix' => 'x:'])
        );

        // If LogTranslationUsage were (incorrectly) registered, this would
        // throw trying to insert into a table that doesn't exist in this
        // test's schema - completing without error is itself the proof.
        $model = TestTranslatableModel::create([
            'name' => ['en' => 'Hello'],
            'description' => ['en' => 'World'],
        ]);

        $model->refresh();

        $this->assertSame('x:Hello:ar', $model->getTranslation('name', 'ar', false));
        $this->assertSame('x:Hello:es', $model->getTranslation('name', 'es', false));
    }
}
