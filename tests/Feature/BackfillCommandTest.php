<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Providers\OpenRouterProvider;
use CodingPartners\TranslaGenius\Tests\Support\MarkerTranslationJob;
use CodingPartners\TranslaGenius\Tests\TestModels\CustomJobTranslatableModel;
use CodingPartners\TranslaGenius\Tests\TestModels\EmptyTranslatableModel;
use CodingPartners\TranslaGenius\Tests\TestModels\NonTranslatableModel;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use CodingPartners\TranslaGenius\TranslaGeniusServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Phase 4d: proves `php artisan translagenius:backfill` validates its
 * {model} argument up front, gates dispatch behind --force or an
 * interactive confirmation (never dispatching on a bare, unconfirmed run),
 * honors --dry-run (report only, dispatch nothing), and dispatches the real
 * TranslateFields job only for records notFullyTranslated() actually
 * matches - via Queue::fake() job COUNTS, not just "the command exited 0".
 *
 * Every test that creates a TestTranslatableModel calls Queue::fake()
 * first (the model's own `created` listener auto-dispatches
 * TranslateFields), then calls Queue::fake() again immediately before
 * invoking the command - each Queue::fake() call installs a fresh fake
 * with no recorded jobs, so this discards the auto-dispatches from
 * creation and leaves only the command's own dispatches to assert on.
 */
class BackfillCommandTest extends Orchestra
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

        Config::set('translaGenius', [
            'supported_languages' => ['en', 'ar', 'es'],
            'default' => 'openrouter',
            'providers' => [
                'openrouter' => [
                    'driver' => OpenRouterProvider::class,
                    'key' => 'test-api-key',
                    'url' => 'https://test-api-url.com',
                    'model' => 'test-model',
                    'timeout' => 10,
                    'temperature' => 0.3,
                    'max_tokens' => 200,
                ],
            ],
        ]);

        $migration = include __DIR__ . '/../database/migrations/2024_01_01_000000_create_test_tables.php';
        $migration->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_translatable_models');
        parent::tearDown();
    }

    public function test_rejects_a_model_class_that_does_not_exist()
    {
        $this->artisan('translagenius:backfill', ['model' => 'App\\Models\\NoSuchModel'])
            ->assertExitCode(1)
            ->expectsOutputToContain('does not exist');
    }

    public function test_rejects_a_class_that_is_not_an_eloquent_model()
    {
        $this->artisan('translagenius:backfill', ['model' => TranslationRequest::class])
            ->assertExitCode(1)
            ->expectsOutputToContain('is not an Eloquent model');
    }

    public function test_rejects_a_model_that_does_not_use_the_translatable_trait()
    {
        $this->artisan('translagenius:backfill', ['model' => NonTranslatableModel::class])
            ->assertExitCode(1)
            ->expectsOutputToContain('does not use');
    }

    public function test_rejects_a_non_positive_chunk_option()
    {
        $this->artisan('translagenius:backfill', [
            'model' => TestTranslatableModel::class,
            '--chunk' => '0',
            '--force' => true,
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('--chunk must be a positive integer');
    }

    public function test_model_with_no_translatable_fields_is_a_no_op()
    {
        $this->artisan('translagenius:backfill', ['model' => EmptyTranslatableModel::class])
            ->assertExitCode(0)
            ->expectsOutputToContain('nothing to backfill');
    }

    public function test_dry_run_reports_count_and_dispatches_nothing()
    {
        Queue::fake();

        TestTranslatableModel::create(['name' => ['en' => 'A'], 'description' => ['en' => 'B']]);
        TestTranslatableModel::create([
            'name' => ['en' => 'A', 'ar' => 'ا', 'es' => 'a'],
            'description' => ['en' => 'B', 'ar' => 'ب', 'es' => 'b'],
        ]);

        Queue::fake();

        $this->artisan('translagenius:backfill', [
            'model' => TestTranslatableModel::class,
            '--dry-run' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Found 1')
            ->expectsOutputToContain('Dry run');

        Queue::assertNothingPushed();
    }

    public function test_bare_run_with_declined_confirmation_dispatches_nothing()
    {
        Queue::fake();

        TestTranslatableModel::create(['name' => ['en' => 'A'], 'description' => ['en' => 'B']]);

        Queue::fake();

        $this->artisan('translagenius:backfill', ['model' => TestTranslatableModel::class])
            ->expectsConfirmation('Dispatch TranslateFields for these 1 record(s)?', 'no')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_force_flag_dispatches_without_an_interactive_prompt()
    {
        Queue::fake();

        TestTranslatableModel::create(['name' => ['en' => 'A'], 'description' => ['en' => 'B']]);
        TestTranslatableModel::create(['name' => ['en' => 'C'], 'description' => ['en' => 'D']]);
        TestTranslatableModel::create([
            'name' => ['en' => 'A', 'ar' => 'ا', 'es' => 'a'],
            'description' => ['en' => 'B', 'ar' => 'ب', 'es' => 'b'],
        ]);

        Queue::fake();

        $this->artisan('translagenius:backfill', [
            'model' => TestTranslatableModel::class,
            '--force' => true,
        ])->assertExitCode(0);

        // 3 records created, 1 already fully translated - only the other
        // 2 should have been dispatched for.
        Queue::assertPushed(TranslateFields::class, 2);
    }

    public function test_interactive_confirmation_dispatches()
    {
        Queue::fake();

        TestTranslatableModel::create(['name' => ['en' => 'A'], 'description' => ['en' => 'B']]);

        Queue::fake();

        $this->artisan('translagenius:backfill', ['model' => TestTranslatableModel::class])
            ->expectsConfirmation('Dispatch TranslateFields for these 1 record(s)?', 'yes')
            ->assertExitCode(0);

        Queue::assertPushed(TranslateFields::class, 1);
    }

    public function test_only_not_fully_translated_records_are_dispatched_for()
    {
        Queue::fake();

        TestTranslatableModel::create(['name' => ['en' => 'A'], 'description' => ['en' => 'B']]);
        TestTranslatableModel::create([
            'name' => ['en' => 'A', 'ar' => 'ا', 'es' => 'a'],
            'description' => ['en' => 'B', 'ar' => 'ب', 'es' => 'b'],
        ]);
        TestTranslatableModel::create([
            'name' => ['en' => 'A', 'ar' => 'ا', 'es' => 'a'],
            'description' => ['en' => 'B', 'ar' => 'ب', 'es' => 'b'],
        ]);

        Queue::fake();

        $this->artisan('translagenius:backfill', [
            'model' => TestTranslatableModel::class,
            '--force' => true,
        ])->assertExitCode(0);

        // 3 records, 2 already fully translated - exactly 1 dispatch.
        Queue::assertPushed(TranslateFields::class, 1);
    }

    /**
     * Quick fix verification: BackfillCommand must respect a model's
     * overridden translationJobClass() (Phase 4a) exactly like the
     * automatic save-triggered flow does (see IsolationSwapTest's
     * equivalent for that flow) - not silently fall back to dispatching
     * the default TranslateFields.
     */
    public function test_backfill_dispatches_the_models_overridden_translation_job_class()
    {
        Queue::fake();

        CustomJobTranslatableModel::create([
            'name' => ['en' => 'A'],
            'description' => ['en' => 'B'],
        ]);

        Queue::fake();

        $this->artisan('translagenius:backfill', [
            'model' => CustomJobTranslatableModel::class,
            '--force' => true,
        ])->assertExitCode(0);

        Queue::assertPushed(MarkerTranslationJob::class, 1);
        Queue::assertNotPushed(TranslateFields::class);
    }
}
