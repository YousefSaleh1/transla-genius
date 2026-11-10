<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\LocaleResolverInterface;
use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\Middleware\SetLocale;
use CodingPartners\TranslaGenius\Tests\Support\FakeLocaleResolver;
use CodingPartners\TranslaGenius\Tests\Support\FakeTranslator;
use CodingPartners\TranslaGenius\Tests\Support\MarkerTranslationJob;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use CodingPartners\TranslaGenius\TranslaGeniusServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 4a verification: direct proof that the three interfaces/override
 * points this phase extracted are real seams, not just "the code compiles
 * with an interface name in it." Each test binds/overrides with a
 * hand-written implementation that has nothing to do with the default one,
 * and exercises the real automatic flow (or the real middleware) through
 * it - the same evidentiary standard Phase 2d's ProviderSwapTest set for
 * the provider layer.
 *
 * Extends Orchestra directly (SQLite, in-memory) rather than the package's
 * shared MySQL-only TestCase, for the same reason every other cross-driver
 * test in this suite does: it needs to actually run in this sandbox.
 */
class IsolationSwapTest extends Orchestra
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

        $migration = include __DIR__ . '/../database/migrations/2024_01_01_000000_create_test_tables.php';
        $migration->up();

        MarkerTranslationJob::$handledWith = [];
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_translatable_models');
        MarkerTranslationJob::$handledWith = [];
        parent::tearDown();
    }

    /**
     * Task 1: TranslatorInterface. A hand-written implementation - not the
     * concrete Translator class - bound via the container drives the real
     * create() -> job -> translate -> save flow.
     */
    public function test_translator_interface_is_swappable()
    {
        $fake = new FakeTranslator('SWAPPED:');
        $this->app->singleton(TranslatorInterface::class, fn () => $fake);

        $model = TestTranslatableModel::create([
            'name' => ['en' => 'Hello'],
            'description' => ['en' => 'World'],
        ]);

        $model->refresh();

        $this->assertEquals('SWAPPED:Hello:ar', $model->getTranslation('name', 'ar', false));
        $this->assertEquals('SWAPPED:Hello:es', $model->getTranslation('name', 'es', false));
        $this->assertNotEmpty($fake->calls);
    }

    /**
     * Task 2: Translatable::translationJobClass(). A model overriding it
     * gets MarkerTranslationJob dispatched instead of TranslateFields - no
     * fork of the trait required.
     */
    public function test_translation_job_class_is_overridable()
    {
        // Deliberately no Queue::fake() here - QUEUE_CONNECTION=sync means
        // the dispatched job runs for real, which is what this test needs
        // to observe (MarkerTranslationJob::$handledWith).
        $model = new class extends TestTranslatableModel {
            protected static function translationJobClass(): string
            {
                return MarkerTranslationJob::class;
            }
        };
        $model->name = ['en' => 'Hi'];
        $model->description = ['en' => 'There'];
        $model->save();

        $this->assertCount(1, MarkerTranslationJob::$handledWith);
        [$modelClass, $modelId, $fields] = MarkerTranslationJob::$handledWith[0];
        $this->assertSame(get_class($model), $modelClass);
        $this->assertSame($model->id, $modelId);
        $this->assertSame(['name', 'description'], $fields);

        // And prove the default job did NOT also run: the model's
        // translations were never touched by any real translator.
        $model->refresh();
        $this->assertArrayNotHasKey('ar', $model->getTranslations('name'));
    }

    /**
     * Task 3: LocaleResolverInterface, exercised through SetLocale - the
     * middleware no longer reads config()/app()->getLocale() itself, it
     * delegates entirely to whatever's bound.
     */
    public function test_locale_resolver_interface_is_swappable_via_set_locale_middleware()
    {
        $this->app->singleton(
            LocaleResolverInterface::class,
            fn () => new FakeLocaleResolver('fr', ['fr', 'de'])
        );

        $middleware = $this->app->make(SetLocale::class);
        $request = Request::create('/', 'GET');
        $request->headers->set('Accept-Language', 'de');

        $middleware->handle($request, fn ($req) => new Response());

        // 'de' is in the fake resolver's supported list (it would NOT be
        // in the real DefaultLocaleResolver's, which only knows en/ar/es
        // per this test's own config) - if this passes, SetLocale is
        // genuinely using the swapped resolver, not falling back to config().
        $this->assertEquals('de', app()->getLocale());
    }

    /**
     * Same swap, exercised through the get_current_locale()/
     * get_supported_languages() helper functions directly.
     */
    public function test_locale_resolver_interface_is_swappable_via_helpers()
    {
        $this->app->singleton(
            LocaleResolverInterface::class,
            fn () => new FakeLocaleResolver('fr', ['fr', 'de', 'it'])
        );

        $this->assertSame('fr', get_current_locale());
        $this->assertSame(['de', 'it'], get_supported_languages());
    }
}
