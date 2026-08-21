<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

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

        $this->assertEquals('اسم الاختبار', $model->name['ar']);
        $this->assertEquals('Nombre de prueba', $model->name['es']);
        $this->assertEquals('وصف الاختبار', $model->description['ar']);
        $this->assertEquals('Descripción de la prueba', $model->description['es']);
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

        $this->assertEquals('Existing Arabic', $model->name['ar']); // Should remain unchanged
        $this->assertEquals('Nombre de prueba', $model->name['es']);
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
