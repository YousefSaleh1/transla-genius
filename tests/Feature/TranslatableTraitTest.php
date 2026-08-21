<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use CodingPartners\TranslaGenius\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

/**
 * Feature tests for TranslatableTrait functionality
 *
 * Tests the behavior of models using the TranslatableTrait including:
 * - Automatic job dispatching on model events
 * - Translation scope functionality
 */
class TranslatableTraitTest extends TestCase
{
    /**
     * Test that translation job is dispatched when model is created
     *
     * Verifies that:
     * 1. Creating a model dispatches a translation job
     * 2. The correct job class is dispatched
     *
     * @return void
     */
    public function test_translation_job_is_dispatched_on_create()
    {
        Queue::fake();

        $model = TestTranslatableModel::create([
            'name' => ['en' => 'Test Name'],
            'description' => ['en' => 'Test Description']
        ]);

        Queue::assertPushed(\CodingPartners\TranslaGenius\Jobs\TranslateFields::class);
    }

    /**
     * Test that translation job is dispatched when model is updated
     *
     * Verifies that:
     * 1. Updating a model dispatches a translation job
     * 2. The job count increments correctly
     * 3. Total jobs dispatched matches expected count (1 for create + 1 for update)
     *
     * @return void
     */
    public function test_translation_job_is_dispatched_on_update()
    {
        Queue::fake();

        $model = TestTranslatableModel::create([
            'name' => ['en' => 'Test Name'],
            'description' => ['en' => 'Test Description']
        ]);

        Queue::assertPushed(\CodingPartners\TranslaGenius\Jobs\TranslateFields::class, 1);

        $model->update(['name' => ['en' => 'Updated Name']]);

        Queue::assertPushed(\CodingPartners\TranslaGenius\Jobs\TranslateFields::class, 2);
    }

    /**
     * Test the fullyTranslated scope
     *
     * Verifies that:
     * 1. Scope correctly identifies fully translated models
     * 2. Scope excludes partially translated models
     * 3. Only models with all fields translated to all supported languages are returned
     *
     * @return void
     */
    public function test_fully_translated_scope()
    {
        // Without this, create() below would synchronously dispatch a real
        // TranslateFields job (QUEUE_CONNECTION=sync) against a real
        // provider hitting the fake, unreachable test API URL - see the
        // same fix applied in TranslateFieldsJobTest for why that matters
        // now that job failures propagate instead of being swallowed.
        Queue::fake();

        // Create a fully translated model
        $translatedModel = TestTranslatableModel::create([
            'name' => ['en' => 'Test', 'ar' => 'اختبار', 'es' => 'Prueba'],
            'description' => ['en' => 'Description', 'ar' => 'وصف', 'es' => 'Descripción']
        ]);

        // Create partially translated models
        $partialModel1 = TestTranslatableModel::create([
            'name' => ['en' => 'Test'],
            'description' => ['en' => 'Description']
        ]);

        $partialModel2 = TestTranslatableModel::create([
            'name' => ['en' => 'Test'],
            'description' => ['en' => 'Description']
        ]);

        $fullyTranslated = TestTranslatableModel::fullyTranslated()->get();

        $this->assertCount(1, $fullyTranslated);
        $this->assertEquals($translatedModel->id, $fullyTranslated->first()->id);

        $this->assertFalse($fullyTranslated->contains($partialModel1));
        $this->assertFalse($fullyTranslated->contains($partialModel2));
    }
}
