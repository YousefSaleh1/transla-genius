<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Services\AutoTranslationService;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use CodingPartners\TranslaGenius\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Mockery;

/**
 * Feature tests for TranslateFields job
 *
 * Tests the behavior of the TranslateFields job which handles:
 * - Automatic translation of model fields
 * - Proper handling of existing translations
 * - Integration with AutoTranslationService
 * - Retry/failure semantics and concurrency safety (Phase 2c)
 *
 * Every test that creates a TestTranslatableModel calls Queue::fake() first.
 * Without it, the model's own `created` listener would synchronously
 * dispatch a *real* TranslateFields job (QUEUE_CONNECTION=sync in
 * phpunit.xml) using a real AutoTranslationService against the fake,
 * unreachable `test-api-url.com` - which now propagates its failure
 * instead of being silently swallowed. Queue::fake() prevents that
 * unrelated auto-dispatch from running at all, leaving each test free to
 * construct and run its own job against its own mock.
 *
 * Assertions read a specific locale's value via getTranslation($field,
 * $locale, false) rather than array-indexing the attribute directly
 * (e.g. $model->name['ar']) - Spatie's HasTranslations resolves plain
 * attribute access to a single string for the *current* app locale, so
 * $model->name is already a string, not the full per-locale array.
 */
class TranslateFieldsJobTest extends TestCase
{
    /**
     * Test that job handles translation correctly for all fields and languages
     *
     * Verifies that:
     * 1. Job correctly translates all specified fields
     * 2. Translations are saved to the model for all supported languages
     * 3. AutoTranslationService is called with correct parameters
     *
     * @return void
     */
    public function test_job_handles_translation_correctly()
    {
        Queue::fake();

        // Create test model with English-only content
        $model = TestTranslatableModel::create([
            'name' => ['en' => 'Test Name'],
            'description' => ['en' => 'Test Description']
        ]);

        // Mock the translation service with expected calls and responses
        $mockService = Mockery::mock(AutoTranslationService::class);
        $mockService->shouldReceive('translate')
            ->with('Test Name', 'en', 'ar')
            ->andReturn('اسم الاختبار');
        $mockService->shouldReceive('translate')
            ->with('Test Name', 'en', 'es')
            ->andReturn('Nombre de prueba');
        $mockService->shouldReceive('translate')
            ->with('Test Description', 'en', 'ar')
            ->andReturn('وصف الاختبار');
        $mockService->shouldReceive('translate')
            ->with('Test Description', 'en', 'es')
            ->andReturn('Descripción de la prueba');

        // Execute the job
        $job = new TranslateFields($model, ['name', 'description']);
        $job->handle($mockService);

        // Verify translations were saved correctly
        $model->refresh();

        $this->assertEquals('اسم الاختبار', $model->getTranslation('name', 'ar', false));
        $this->assertEquals('Nombre de prueba', $model->getTranslation('name', 'es', false));
        $this->assertEquals('وصف الاختبار', $model->getTranslation('description', 'ar', false));
        $this->assertEquals('Descripción de la prueba', $model->getTranslation('description', 'es', false));
    }

    /**
     * Test that job handles existing translations correctly
     *
     * Verifies that:
     * 1. Job skips translation for languages that already have translations
     * 2. Only missing translations are requested
     * 3. Existing translations remain unchanged
     *
     * @return void
     */
    public function test_job_handles_existing_translations()
    {
        Queue::fake();

        // Create test model with existing Arabic translation for name
        $model = TestTranslatableModel::create([
            'name' => ['en' => 'Test Name', 'ar' => 'Existing Arabic'],
            'description' => ['en' => 'Test Description']
        ]);

        // Mock the translation service with expected calls only for missing translations
        $mockService = Mockery::mock(AutoTranslationService::class);
        // Should only be called for Spanish (Arabic already exists) and for description
        $mockService->shouldReceive('translate')
            ->with('Test Name', 'en', 'es')
            ->andReturn('Nombre de prueba');
        $mockService->shouldReceive('translate')
            ->with('Test Description', 'en', 'ar')
            ->andReturn('وصف الاختبار');
        $mockService->shouldReceive('translate')
            ->with('Test Description', 'en', 'es')
            ->andReturn('Descripción de la prueba');

        // Execute the job
        $job = new TranslateFields($model, ['name', 'description']);
        $job->handle($mockService);

        // Verify translations
        $model->refresh();

        $this->assertEquals('Existing Arabic', $model->getTranslation('name', 'ar', false)); // Should remain unchanged
        $this->assertEquals('Nombre de prueba', $model->getTranslation('name', 'es', false));
    }

    /**
     * Fix 1: sensible, explicit retry configuration instead of no policy at all.
     *
     * @return void
     */
    public function test_job_has_sensible_retry_configuration()
    {
        Queue::fake();

        $model = TestTranslatableModel::create([
            'name' => ['en' => 'Test Name'],
            'description' => ['en' => 'Test Description'],
        ]);

        $job = new TranslateFields($model, ['name']);

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 30], $job->backoff());
    }

    /**
     * Fix 1/2: a non-retryable failure (e.g. bad API key) must call
     * $this->fail() immediately rather than being retried or swallowed.
     *
     * @return void
     */
    public function test_job_fails_immediately_for_non_retryable_translation_error()
    {
        Queue::fake();

        $model = TestTranslatableModel::create([
            'name' => ['en' => 'Test Name'],
            'description' => ['en' => 'Test Description'],
        ]);

        $mockService = Mockery::mock(AutoTranslationService::class);
        $mockService->shouldReceive('translate')
            ->andThrow(new TranslationFailedException('Invalid API key', statusCode: 401, retryable: false));

        $job = (new TranslateFields($model, ['name', 'description']))->withFakeQueueInteractions();
        $job->handle($mockService);

        $job->assertFailed();
    }

    /**
     * Fix 1/2: a retryable failure (e.g. a timeout) must propagate out of
     * handle() so the queue's native $tries/backoff() takes over, instead
     * of being swallowed.
     *
     * @return void
     */
    public function test_job_rethrows_retryable_translation_error_for_queue_retry()
    {
        Queue::fake();

        $model = TestTranslatableModel::create([
            'name' => ['en' => 'Test Name'],
            'description' => ['en' => 'Test Description'],
        ]);

        $mockService = Mockery::mock(AutoTranslationService::class);
        $mockService->shouldReceive('translate')
            ->andThrow(new TranslationFailedException('Timed out', retryable: true));

        $job = new TranslateFields($model, ['name', 'description']);

        $this->expectException(TranslationFailedException::class);
        $job->handle($mockService);
    }

    /**
     * Fix 3: a translation written by a concurrent job (a later update on
     * the same model, translated while this job's HTTP calls were still in
     * flight) must not be clobbered by this job's now-stale computed value
     * for the same field/locale.
     *
     * @return void
     */
    public function test_job_preserves_concurrently_written_translation()
    {
        Queue::fake();

        $model = TestTranslatableModel::create([
            'name' => ['en' => 'Test Name'],
            'description' => ['en' => 'Test Description'],
        ]);

        $mockService = Mockery::mock(AutoTranslationService::class);
        $mockService->shouldReceive('translate')
            ->with('Test Name', 'en', 'ar')
            ->andReturn('STALE ar (should not be saved)');
        $mockService->shouldReceive('translate')
            ->with('Test Name', 'en', 'es')
            ->andReturn('Nombre de prueba');
        $mockService->shouldReceive('translate')
            ->with('Test Description', 'en', 'ar')
            ->andReturn('وصف الاختبار');
        $mockService->shouldReceive('translate')
            ->with('Test Description', 'en', 'es')
            ->andReturn('Descripción de la prueba');

        $job = new TranslateFields($model, ['name', 'description']);

        // Simulate a concurrent job completing the 'ar' translation for
        // 'name' after this job read $model but before it saves - via a
        // separate model instance/query, not the one the job holds.
        $concurrent = TestTranslatableModel::find($model->id);
        $concurrent->setTranslation('name', 'ar', 'CONCURRENT ar (must survive)');
        $concurrent->save();

        $job->handle($mockService);

        $model->refresh();

        $this->assertEquals('CONCURRENT ar (must survive)', $model->getTranslation('name', 'ar', false));
        $this->assertEquals('Nombre de prueba', $model->getTranslation('name', 'es', false));
        $this->assertEquals('وصف الاختبار', $model->getTranslation('description', 'ar', false));
        $this->assertEquals('Descripción de la prueba', $model->getTranslation('description', 'es', false));
    }

    /**
     * Fix 5: a model may opt in to filtering which field content is sent
     * for translation by defining shouldTranslateField(). Absent fields
     * are left untouched rather than sent to the API.
     *
     * @return void
     */
    public function test_job_honors_should_translate_field_hook_on_model()
    {
        Queue::fake();

        $model = new class extends TestTranslatableModel {
            public function shouldTranslateField(string $field, string $value): bool
            {
                return $field !== 'description';
            }
        };
        $model->name = ['en' => 'Test Name'];
        $model->description = ['en' => 'Test Description'];
        $model->save();

        // No expectation is set for 'description' - Mockery raises if it's
        // called with args that don't match any declared expectation.
        $mockService = Mockery::mock(AutoTranslationService::class);
        $mockService->shouldReceive('translate')
            ->with('Test Name', 'en', 'ar')
            ->andReturn('اسم الاختبار');
        $mockService->shouldReceive('translate')
            ->with('Test Name', 'en', 'es')
            ->andReturn('Nombre de prueba');

        $job = new TranslateFields($model, ['name', 'description']);
        $job->handle($mockService);

        $model->refresh();

        $this->assertEquals('اسم الاختبار', $model->getTranslation('name', 'ar', false));
        $this->assertEquals('Nombre de prueba', $model->getTranslation('name', 'es', false));
        $this->assertArrayNotHasKey('ar', $model->getTranslations('description'));
        $this->assertArrayNotHasKey('es', $model->getTranslations('description'));
    }

    /**
     * Clean up mock objects after each test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
