<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslationJob;
use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;
use CodingPartners\TranslaGenius\Exceptions\InvalidTranslationJobException;
use CodingPartners\TranslaGenius\Tests\Support\MarkerTranslationJob;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\CustomJobTranslatableModel;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A job from translationJobClass() is constructed with one
 * TranslationJobPayload; one that doesn't implement the TranslationJob
 * contract is rejected at dispatch. The async tests go through the real
 * `database` queue: serialized into the jobs table, then run by queue:work.
 */
class JobPayloadContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MarkerTranslationJob::$handledWith = [];
        MarkerTranslationJob::$payloads = [];
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
                'driver' => 'database', 'connection' => config('database.default'), 'table' => 'jobs',
                'queue' => 'default', 'retry_after' => 90, 'after_commit' => false,
            ],
        ]);
    }

    protected function work(): void
    {
        app()->setLocale('en'); // the worker's own locale
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1])->assertSuccessful();
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_custom_job_gets_the_source_locale_through_the_payload_on_a_real_queue()
    {
        $this->useDatabaseQueue();

        app()->setLocale('ar');
        $model = CustomJobTranslatableModel::create(['name' => ['ar' => 'مرحبا'], 'description' => ['ar' => 'وصف']]);
        $this->assertSame(1, DB::table('jobs')->count());

        // Changed after dispatch: the job must see the database row, not a
        // copy frozen into the payload.
        DB::table('test_translatable_models')->where('id', $model->id)->update(['name' => json_encode(['ar' => 'محدث'])]);

        $this->work();

        $this->assertCount(1, MarkerTranslationJob::$payloads);
        $payload = MarkerTranslationJob::$payloads[0];
        $this->assertSame('ar', $payload->sourceLocale);
        $this->assertSame(['name', 'description'], $payload->fields);
        $this->assertSame([], $payload->options);
        $this->assertSame('محدث', $payload->model->getTranslation('name', 'ar', false));
    }

    public function test_backfill_options_reach_a_custom_job_through_the_payload_on_a_real_queue()
    {
        $this->useDatabaseQueue();
        CustomJobTranslatableModel::withoutEvents(fn () => CustomJobTranslatableModel::create(['name' => ['ar' => 'مرحبا'], 'description' => ['ar' => 'وصف']]));

        $this->artisan('translagenius:backfill', ['model' => CustomJobTranslatableModel::class, '--source' => 'ar', '--force' => true])->assertSuccessful();
        $this->work();

        $payload = MarkerTranslationJob::$payloads[0];
        $this->assertSame('ar', $payload->sourceLocale);
        $this->assertSame(['include_rejected' => true], $payload->options);
    }

    public function test_a_class_that_does_not_implement_the_contract_is_rejected_at_dispatch()
    {
        $legacy = new class (new TestTranslatableModel(), []) implements ShouldQueue {
            use Dispatchable;

            public function __construct(public Model $model, public array $fields)
            {
            }

            public function handle(): void
            {
            }
        };
        $legacyClass = get_class($legacy);

        $model = new class extends TestTranslatableModel {
            public static string $job;

            protected static function translationJobClass(): string
            {
                return static::$job;
            }
        };
        $model::$job = $legacyClass;

        try {
            $model->fill(['name' => ['en' => 'Hello'], 'description' => ['en' => 'World']])->save();
            $this->fail('Expected InvalidTranslationJobException.');
        } catch (InvalidTranslationJobException $e) {
            $this->assertSame($legacyClass, $e->jobClass);
            $this->assertStringContainsString($legacyClass, $e->getMessage());
            $this->assertStringContainsString(TranslationJob::class, $e->getMessage());
        }
    }

    public function test_the_payload_serializes_its_model_as_an_identifier()
    {
        $model = TestTranslatableModel::withoutEvents(fn () => TestTranslatableModel::create(['name' => ['en' => 'Secret source'], 'description' => ['en' => '']]));
        $payload = new TranslationJobPayload($model, ['name'], 'en', [], ['include_rejected' => true]);

        $serialized = serialize($payload);
        $this->assertStringNotContainsString('Secret source', $serialized);
        $this->assertStringContainsString(ModelIdentifier::class, $serialized);

        $restored = unserialize($serialized);
        $this->assertTrue($restored->model->is($model));
        $this->assertSame(['name'], $restored->fields);
        $this->assertSame('en', $restored->sourceLocale);
        $this->assertSame(['include_rejected' => true], $restored->options);
    }
}
