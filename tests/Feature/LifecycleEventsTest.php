<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;
use CodingPartners\TranslaGenius\Events\TranslationCompleted;
use CodingPartners\TranslaGenius\Events\TranslationFailed;
use CodingPartners\TranslaGenius\Events\TranslationStarted;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use CodingPartners\TranslaGenius\Tests\Support\FakeTranslationProvider;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use CodingPartners\TranslaGenius\TranslaGeniusServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Phase 4b: proves the three lifecycle events fire at the right point, with
 * the right data, through the REAL automatic flow - Translatable trait ->
 * TranslateFields job (run synchronously, QUEUE_CONNECTION=sync per
 * phpunit.xml) -> Translator -> a hand-written TranslationProviderInterface
 * implementation. Same standard as ProviderSwapTest: no Translator/job
 * mocking, and real Event::listen() closures (not Event::fake()) so what's
 * asserted is the data a genuine consuming-app listener would actually
 * receive, not just that dispatching compiles.
 *
 * Extends Orchestra directly rather than the package's own TestCase for the
 * same reason ProviderSwapTest does - a lightweight, self-contained
 * Testbench app is enough here and keeps this test independent of
 * TestCase's own DB-driver configuration.
 */
class LifecycleEventsTest extends Orchestra
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

        Config::set('translagenius.supported_languages', ['en', 'ar', 'es']);

        $migration = include __DIR__.'/../database/migrations/2024_01_01_000000_create_test_tables.php';
        $migration->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_translatable_models');
        parent::tearDown();
    }

    public function test_successful_translation_dispatches_started_and_completed_events_with_correct_data()
    {
        $this->app->singleton(
            TranslationProviderInterface::class,
            fn () => new FakeTranslationProvider('x:')
        );

        $started = [];
        $completed = [];

        Event::listen(TranslationStarted::class, function (TranslationStarted $event) use (&$started) {
            $started[] = $event;
        });
        Event::listen(TranslationCompleted::class, function (TranslationCompleted $event) use (&$completed) {
            $completed[] = $event;
        });

        // Real end-to-end flow: create() -> Translatable::bootTranslatable()
        // -> TranslateFields::dispatch() -> runs synchronously -> handle()
        // -> translateMissingPairs() dispatches these events around each of
        // its translator->translate() calls.
        $model = TestTranslatableModel::create([
            'name' => ['en' => 'Hello'],
            'description' => ['en' => 'World'],
        ]);

        // 2 fields x 2 target languages (ar, es) = 4 translate() calls.
        $this->assertCount(4, $started);
        $this->assertCount(4, $completed);

        foreach ($started as $event) {
            $this->assertSame(TestTranslatableModel::class, $event->modelClass);
            $this->assertSame($model->id, $event->modelId);
            $this->assertContains($event->field, ['name', 'description']);
            $this->assertSame('en', $event->sourceLocale);
            $this->assertContains($event->targetLocale, ['ar', 'es']);
            $this->assertSame($event->field === 'name' ? 'Hello' : 'World', $event->text);
        }

        $nameEsCompleted = array_values(array_filter(
            $completed,
            fn (TranslationCompleted $e) => $e->field === 'name' && $e->targetLocale === 'es'
        ));

        $this->assertCount(1, $nameEsCompleted);
        $completedEvent = $nameEsCompleted[0];

        $this->assertSame(TestTranslatableModel::class, $completedEvent->modelClass);
        $this->assertSame($model->id, $completedEvent->modelId);
        $this->assertSame('name', $completedEvent->field);
        $this->assertSame('en', $completedEvent->sourceLocale);
        $this->assertSame('es', $completedEvent->targetLocale);
        $this->assertInstanceOf(TranslationResult::class, $completedEvent->result);
        $this->assertSame('x:Hello:es', $completedEvent->result->text);
        $this->assertSame('fake', $completedEvent->result->provider);
    }

    public function test_permanently_failed_translation_dispatches_translation_failed_event_with_exception()
    {
        $failingProvider = new class implements TranslationProviderInterface
        {
            public function translate(TranslationRequest $request): TranslationResult
            {
                throw new TranslationFailedException(
                    'Invalid API key',
                    statusCode: 401,
                    retryable: false
                );
            }
        };

        $this->app->singleton(TranslationProviderInterface::class, fn () => $failingProvider);

        $failed = [];

        Event::listen(TranslationFailed::class, function (TranslationFailed $event) use (&$failed) {
            $failed[] = $event;
        });

        // The job runs synchronously as part of create(); it hits the
        // non-retryable failure on its very first translate() call, calls
        // $this->fail($e), and the real queue machinery (even on the sync
        // connection) invokes TranslateFields::failed() from there - which
        // is where TranslationFailed is dispatched.
        $model = TestTranslatableModel::create([
            'name' => ['en' => 'Hello'],
            'description' => ['en' => 'World'],
        ]);

        $this->assertCount(1, $failed);

        $event = $failed[0];

        $this->assertSame(TestTranslatableModel::class, $event->modelClass);
        $this->assertSame($model->id, $event->modelId);
        $this->assertInstanceOf(TranslationFailedException::class, $event->exception);
        $this->assertSame('Invalid API key', $event->exception->getMessage());
        $this->assertSame(401, $event->exception->getStatusCode());
        $this->assertFalse($event->exception->isRetryable());
    }
}
