<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

/**
 * The source locale is decided when the job is dispatched and carried in
 * its payload - never read from the queue worker's own locale.
 *
 * The async tests use the real `database` queue driver: the job is
 * serialized into the jobs table while the request locale is "ar", the app
 * locale is put back to "en" (as in a worker process), and `queue:work
 * --once` unserializes and runs it.
 */
class SourceLocaleTest extends TestCase
{
    /** @var array<int, string> "from->to text" for every request */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config(['translaGenius.supported_languages' => ['en', 'ar', 'es']]);

        Http::fake(function (Request $request) {
            preg_match('/from (\w+) to (\w+)\./', $request['messages'][0]['content'], $m);
            $text = $request['messages'][1]['content'];
            $this->requests[] = "{$m[1]}->{$m[2]} {$text}";

            return Http::response(['choices' => [['message' => ['content' => "[{$m[1]}->{$m[2]}] {$text}"], 'finish_reason' => 'stop']]]);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('jobs');

        parent::tearDown();
    }

    protected function useDatabaseQueue(): void
    {
        Schema::dropIfExists('jobs');
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        config([
            'queue.default' => 'database',
            'queue.connections.database' => [
                'driver' => 'database',
                'connection' => config('database.default'),
                'table' => 'jobs',
                'queue' => 'default',
                'retry_after' => 90,
                'after_commit' => false,
            ],
        ]);
    }

    /** Save in the "request" locale, then run the queued job as a worker would. */
    protected function createInRequestLocale(string $requestLocale, array $attributes): TestTranslatableModel
    {
        $this->useDatabaseQueue();

        app()->setLocale($requestLocale);
        $model = TestTranslatableModel::create($attributes);
        $this->assertSame(1, DB::table('jobs')->count(), 'The job must be queued, not run inline.');

        // The worker: its own default locale, unrelated to the request.
        app()->setLocale('en');
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1])->assertSuccessful();
        $this->assertSame(0, DB::table('jobs')->count());

        return TestTranslatableModel::find($model->id);
    }

    protected function sorted(array $translations): array
    {
        ksort($translations);

        return $translations;
    }

    public function test_a_job_queued_from_an_arabic_request_translates_from_arabic_in_an_english_worker()
    {
        $model = $this->createInRequestLocale('ar', [
            'name' => ['ar' => 'مرحبا'],
            'description' => ['ar' => 'وصف'],
        ]);

        sort($this->requests);
        $this->assertSame(['ar->en مرحبا', 'ar->en وصف', 'ar->es مرحبا', 'ar->es وصف'], $this->requests);
        $this->assertSame(['ar' => 'مرحبا', 'en' => '[ar->en] مرحبا', 'es' => '[ar->es] مرحبا'], $this->sorted($model->getTranslations('name')));
    }

    public function test_translation_source_locale_wins_over_the_request_locale()
    {
        config(['translaGenius.source_locale' => 'es']);

        // The request is in Arabic, but content is always authored in Spanish.
        $model = $this->createInRequestLocale('ar', [
            'name' => ['es' => 'Hola'],
            'description' => ['es' => 'Descripción'],
        ]);

        sort($this->requests);
        $this->assertSame(['es->ar Descripción', 'es->ar Hola', 'es->en Descripción', 'es->en Hola'], $this->requests);
        $this->assertSame('[es->ar] Hola', $model->getTranslation('name', 'ar', false));
    }

    public function test_the_source_locale_is_captured_at_dispatch_into_the_payload()
    {
        Queue::fake();
        app()->setLocale('ar');

        TestTranslatableModel::create(['name' => ['ar' => 'مرحبا'], 'description' => ['ar' => 'وصف']]);

        Queue::assertPushed(TranslateFields::class, function (TranslateFields $job) {
            $payload = (fn () => $this->sourceLocale)->call($job);

            return $payload === 'ar';
        });
    }

    public function test_backfill_uses_the_source_option_and_says_so()
    {
        Queue::fake();
        $model = TestTranslatableModel::withoutEvents(fn () => TestTranslatableModel::create([
            'name' => ['ar' => 'مرحبا'],
            'description' => ['ar' => 'وصف'],
        ]));

        $this->artisan('translagenius:backfill', ['model' => TestTranslatableModel::class, '--source' => 'ar'])
            ->expectsOutputToContain('Source locale: ar; target locale(s): en, es')
            ->expectsConfirmation('Dispatch TranslateFields for these 1 record(s), translating from [ar]?', 'yes')
            ->assertSuccessful();

        Queue::assertPushed(TranslateFields::class, fn (TranslateFields $job) => (fn () => $this->sourceLocale)->call($job) === 'ar');
    }

    public function test_backfill_defaults_to_translation_source_locale_then_app_locale()
    {
        Queue::fake();
        TestTranslatableModel::withoutEvents(fn () => TestTranslatableModel::create(['name' => ['es' => 'Hola'], 'description' => ['es' => 'x']]));

        config(['translaGenius.source_locale' => 'es']);
        $this->artisan('translagenius:backfill', ['model' => TestTranslatableModel::class, '--force' => true])
            ->expectsOutputToContain('Source locale: es;')
            ->assertSuccessful();

        config(['translaGenius.source_locale' => null]);
        $this->artisan('translagenius:backfill', ['model' => TestTranslatableModel::class, '--dry-run' => true])
            ->expectsOutputToContain('Source locale: en;')
            ->assertSuccessful();
    }

    public function test_an_empty_source_text_with_text_in_another_locale_is_skipped_with_a_warning()
    {
        Log::spy();
        $model = TestTranslatableModel::withoutEvents(fn () => TestTranslatableModel::create([
            'name' => ['ar' => 'مرحبا'],
            'description' => ['ar' => '   ', 'en' => 'Written in English by mistake'],
        ]));

        (new TranslateFields($model, ['name', 'description'], [], 'ar'))
            ->handle($this->app->make(TranslatorInterface::class), new TranslationWriter());

        sort($this->requests);
        $this->assertSame(['ar->en مرحبا', 'ar->es مرحبا'], $this->requests);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context = []) => str_contains($message, 'no source text')
            && $context['model_class'] === TestTranslatableModel::class
            && $context['model_id'] === $model->id
            && $context['field'] === 'description'
            && $context['source_locale'] === 'ar'
            && $context['locales_with_text'] === ['en']);
    }

    public function test_a_field_empty_in_every_locale_is_skipped_silently()
    {
        Log::spy();
        $model = TestTranslatableModel::withoutEvents(fn () => TestTranslatableModel::create([
            'name' => ['ar' => 'مرحبا'],
            'description' => ['ar' => ''],
        ]));

        (new TranslateFields($model, ['name', 'description'], [], 'ar'))
            ->handle($this->app->make(TranslatorInterface::class), new TranslationWriter());

        sort($this->requests);
        $this->assertSame(['ar->en مرحبا', 'ar->es مرحبا'], $this->requests);
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * A job queued by an older version carries no source locale. It uses
     * TRANSLATION_SOURCE_LOCALE, then app.locale - not the worker's current
     * locale, whatever that happens to be.
     */
    public function test_a_job_without_a_carried_source_locale_uses_the_configured_default()
    {
        $model = TestTranslatableModel::withoutEvents(fn () => TestTranslatableModel::create([
            'name' => ['en' => 'Hello', 'es' => 'Hola'],
            'description' => ['en' => '', 'es' => ''],
        ]));
        $legacyJob = new TranslateFields($model, ['name']); // no source locale, like an old payload

        app()->setLocale('ar'); // a worker in some unrelated locale
        $legacyJob->handle($this->app->make(TranslatorInterface::class), new TranslationWriter());
        $this->assertSame(['en->ar Hello'], $this->requests);

        $this->requests = [];
        config(['translaGenius.source_locale' => 'es']);
        $model->refresh()->forgetTranslation('name', 'ar');
        TestTranslatableModel::withoutEvents(fn () => $model->save());
        (new TranslateFields($model->fresh(), ['name']))->handle($this->app->make(TranslatorInterface::class), new TranslationWriter());
        $this->assertSame(['es->ar Hola'], $this->requests);
    }
}
