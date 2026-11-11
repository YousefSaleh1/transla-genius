<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Tests\Support\MarkerTranslationJob;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\CustomJobTranslatableModel;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * translaGenius.queue (TRANSLATION_QUEUE_CONNECTION, TRANSLATION_QUEUE,
 * TRANSLATION_DISPATCH_AFTER_COMMIT) applied at every dispatch point.
 *
 * Placement is checked with Queue::fake() on the pushed job object. The
 * after-commit tests use the real sync queue instead: QueueFake records a
 * push immediately and ignores after-commit, while SyncQueue defers an
 * after-commit job until the transaction commits (and drops it on rollback).
 */
class QueueDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MarkerTranslationJob::$handledWith = [];
    }

    /** A Translatable model whose create/update dispatches MarkerTranslationJob. */
    protected function markerModel(): TestTranslatableModel
    {
        return new class extends TestTranslatableModel {
            protected static function translationJobClass(): string
            {
                return MarkerTranslationJob::class;
            }
        };
    }

    public function test_create_and_update_dispatch_onto_the_configured_connection_and_queue()
    {
        Queue::fake();
        config(['translaGenius.queue.connection' => 'redis', 'translaGenius.queue.queue' => 'translations']);

        $model = TestTranslatableModel::create(['name' => ['en' => 'Hello'], 'description' => ['en' => 'World']]);
        $model->setTranslation('name', 'en', 'Hello again')->save();

        Queue::assertPushed(TranslateFields::class, 2);
        Queue::assertPushed(TranslateFields::class, fn (TranslateFields $job) => $job->connection === 'redis' && $job->queue === 'translations');
        Queue::assertNotPushed(TranslateFields::class, fn (TranslateFields $job) => $job->connection !== 'redis' || $job->queue !== 'translations');
    }

    public function test_empty_queue_settings_leave_the_app_defaults()
    {
        Queue::fake();
        config(['translaGenius.queue.connection' => '', 'translaGenius.queue.queue' => null]);

        TestTranslatableModel::create(['name' => ['en' => 'Hello'], 'description' => ['en' => 'World']]);

        Queue::assertPushed(TranslateFields::class, fn (TranslateFields $job) => $job->connection === null && $job->queue === null);
    }

    public function test_the_trait_respects_the_job_class_override_with_queue_settings()
    {
        Queue::fake();
        config(['translaGenius.queue.connection' => 'redis', 'translaGenius.queue.queue' => 'translations']);

        $model = $this->markerModel();
        $model->fill(['name' => ['en' => 'Hello'], 'description' => ['en' => 'World']])->save();

        Queue::assertNotPushed(TranslateFields::class);
        Queue::assertPushed(MarkerTranslationJob::class, fn ($job) => $job->connection === 'redis' && $job->queue === 'translations');
    }

    public function test_backfill_dispatches_the_override_onto_the_configured_connection_and_queue()
    {
        Queue::fake();
        config(['translaGenius.queue.connection' => 'redis', 'translaGenius.queue.queue' => 'translations']);

        CustomJobTranslatableModel::withoutEvents(fn () => CustomJobTranslatableModel::create([
            'name' => ['en' => 'Hello'],
            'description' => ['en' => 'World'],
        ]));

        $this->artisan('translagenius:backfill', ['model' => CustomJobTranslatableModel::class, '--force' => true])
            ->assertSuccessful();

        Queue::assertPushed(MarkerTranslationJob::class, 1);
        Queue::assertPushed(MarkerTranslationJob::class, fn ($job) => $job->connection === 'redis' && $job->queue === 'translations');
    }

    public function test_a_custom_job_without_queueable_is_still_dispatched()
    {
        Queue::fake();
        config(['translaGenius.queue.connection' => 'redis', 'translaGenius.queue.queue' => 'translations']);

        $plainJob = new class (new TestTranslatableModel(), []) implements ShouldQueue {
            use Dispatchable;

            public function __construct(public Model $model, public array $fields)
            {
            }

            public function handle(): void
            {
            }
        };
        $plainJobClass = get_class($plainJob);

        $model = new class extends TestTranslatableModel {
            public static string $job;

            protected static function translationJobClass(): string
            {
                return static::$job;
            }
        };
        $model::$job = $plainJobClass;
        $model->fill(['name' => ['en' => 'Hello'], 'description' => ['en' => 'World']])->save();

        Queue::assertPushed($plainJobClass, 1);
    }

    public function test_a_job_without_queueable_logs_one_warning_per_class_while_settings_are_configured()
    {
        Queue::fake();
        \CodingPartners\TranslaGenius\Support\TranslationJobDispatcher::resetWarnings();
        $plainJobClass = get_class(new class (new TestTranslatableModel(), []) implements ShouldQueue {
            use Dispatchable;

            public function __construct(public Model $model, public array $fields)
            {
            }

            public function handle(): void
            {
            }
        });

        \Illuminate\Support\Facades\Log::spy();

        // Not configured: no warning.
        config(['translaGenius.queue.connection' => null, 'translaGenius.queue.queue' => null]);
        \CodingPartners\TranslaGenius\Support\TranslationJobDispatcher::dispatch($plainJobClass, new TestTranslatableModel(), []);
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');

        // Configured: three dispatches, one warning naming the class; all three still dispatched.
        config(['translaGenius.queue.queue' => 'translations']);
        foreach (range(1, 3) as $i) {
            \CodingPartners\TranslaGenius\Support\TranslationJobDispatcher::dispatch($plainJobClass, new TestTranslatableModel(), []);
        }

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, $plainJobClass)
                && ($context['job_class'] ?? null) === $plainJobClass);
        Queue::assertPushed($plainJobClass, 4);

        // A Queueable job class never warns.
        \CodingPartners\TranslaGenius\Support\TranslationJobDispatcher::dispatch(TranslateFields::class, new TestTranslatableModel(), []);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once();
    }

    public function test_with_after_commit_a_rolled_back_transaction_dispatches_nothing()
    {
        try {
            DB::transaction(function () {
                $this->markerModel()->fill(['name' => ['en' => 'Hello'], 'description' => ['en' => 'World']])->save();

                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], MarkerTranslationJob::$handledWith);
        $this->assertSame(0, TestTranslatableModel::count());
    }

    public function test_with_after_commit_a_committed_transaction_dispatches_once_after_commit()
    {
        $ranInsideTransaction = null;

        DB::transaction(function () use (&$ranInsideTransaction) {
            $this->markerModel()->fill(['name' => ['en' => 'Hello'], 'description' => ['en' => 'World']])->save();
            $ranInsideTransaction = count(MarkerTranslationJob::$handledWith);
        });

        $this->assertSame(0, $ranInsideTransaction, 'The job must not run before the commit.');
        $this->assertCount(1, MarkerTranslationJob::$handledWith);
    }

    /**
     * Control: with the setting off, the same sync dispatch runs inside the
     * transaction - so the two tests above really are measuring the setting.
     */
    public function test_with_after_commit_off_the_job_runs_inside_the_transaction()
    {
        config(['translaGenius.queue.after_commit' => false]);
        $ranInsideTransaction = null;

        DB::transaction(function () use (&$ranInsideTransaction) {
            $this->markerModel()->fill(['name' => ['en' => 'Hello'], 'description' => ['en' => 'World']])->save();
            $ranInsideTransaction = count(MarkerTranslationJob::$handledWith);
        });

        $this->assertSame(1, $ranInsideTransaction);
        $this->assertCount(1, MarkerTranslationJob::$handledWith);
    }
}
