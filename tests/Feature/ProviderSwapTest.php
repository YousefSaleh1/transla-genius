<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\Tests\Support\FakeTranslationProvider;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use CodingPartners\TranslaGenius\TranslaGeniusServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Direct proof of Task 1's whole point: a TranslationProviderInterface
 * implementation with nothing to do with OpenRouter/HTTP can be bound in
 * via the container, and the ENTIRE automatic flow - Translatable trait ->
 * TranslateFields job -> Translator -> interface -> provider -> DB write -
 * uses it, with zero changes to the job, the trait, or Translator.
 *
 * No Http::fake() and no Queue::fake() are used anywhere in this file:
 * FakeTranslationProvider never makes a network call, and the job is left
 * to actually run (QUEUE_CONNECTION=sync per phpunit.xml) so this proves
 * the real, wired-up path - not a mock standing in for it.
 */
class ProviderSwapTest extends Orchestra
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
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('translaGenius.supported_languages', ['en', 'ar', 'es']);

        // The only line in this file that knows FakeTranslationProvider
        // exists. Nothing else - job, trait, Translator - is touched or
        // aware that the provider was swapped.
        $this->app->singleton(
            TranslationProviderInterface::class,
            fn () => new FakeTranslationProvider('translated:')
        );

        $migration = include __DIR__ . '/../database/migrations/2024_01_01_000000_create_test_tables.php';
        $migration->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_translatable_models');
        parent::tearDown();
    }

    public function test_a_container_bound_fake_provider_drives_the_whole_automatic_flow()
    {
        $model = TestTranslatableModel::create([
            'name' => ['en' => 'Test Name'],
            'description' => ['en' => 'Test Description'],
        ]);

        // The job already ran synchronously as part of create() above -
        // via the real Translatable trait -> TranslateFields -> Translator
        // path, with the fake provider resolved from the container.
        $model->refresh();

        $this->assertEquals('translated:Test Name:ar', $model->getTranslation('name', 'ar', false));
        $this->assertEquals('translated:Test Name:es', $model->getTranslation('name', 'es', false));
        $this->assertEquals('translated:Test Description:ar', $model->getTranslation('description', 'ar', false));
        $this->assertEquals('translated:Test Description:es', $model->getTranslation('description', 'es', false));
    }

    public function test_swapped_provider_receives_the_correct_source_text_and_locales()
    {
        $fake = new FakeTranslationProvider('x:');
        $this->app->singleton(TranslationProviderInterface::class, fn () => $fake);

        TestTranslatableModel::create([
            'name' => ['en' => 'Only Field'],
            'description' => ['en' => 'Desc'],
        ]);

        $this->assertCount(4, $fake->requests); // 2 fields x 2 target languages (ar, es)

        $nameRequests = array_filter($fake->requests, fn ($r) => $r->text === 'Only Field');
        $this->assertCount(2, $nameRequests);
        $this->assertEqualsCanonicalizing(
            ['ar', 'es'],
            array_map(fn ($r) => $r->targetLocale, $nameRequests)
        );
    }
}
