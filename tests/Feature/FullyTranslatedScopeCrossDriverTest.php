<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Providers\OpenRouterProvider;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use CodingPartners\TranslaGenius\TranslaGeniusServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Fix 6 verification: scopeFullyTranslated() must produce equivalent
 * results on a non-MySQL driver, not just "compile" there.
 *
 * This intentionally does NOT extend the package's own TestCase - that
 * class hardcodes a MySQL connection (see tests/TestCase.php), which is
 * exactly what this test exists to prove isn't required anymore. It boots
 * its own lightweight Orchestra Testbench app against an in-memory SQLite
 * database instead, reusing the same migration and TestTranslatableModel
 * fixture the MySQL-based suite uses.
 */
class FullyTranslatedScopeCrossDriverTest extends Orchestra
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

    public function test_fully_translated_scope_on_sqlite()
    {
        Queue::fake();

        $translatedModel = TestTranslatableModel::create([
            'name' => ['en' => 'Test', 'ar' => 'اختبار', 'es' => 'Prueba'],
            'description' => ['en' => 'Description', 'ar' => 'وصف', 'es' => 'Descripción'],
        ]);

        $partialModel1 = TestTranslatableModel::create([
            'name' => ['en' => 'Test'],
            'description' => ['en' => 'Description'],
        ]);

        $partialModel2 = TestTranslatableModel::create([
            'name' => ['en' => 'Test', 'ar' => 'اختبار'], // missing 'es'
            'description' => ['en' => 'Description', 'ar' => 'وصف', 'es' => 'Descripción'],
        ]);

        $fullyTranslated = TestTranslatableModel::fullyTranslated()->get();

        $this->assertCount(1, $fullyTranslated);
        $this->assertEquals($translatedModel->id, $fullyTranslated->first()->id);
        $this->assertFalse($fullyTranslated->contains($partialModel1));
        $this->assertFalse($fullyTranslated->contains($partialModel2));
    }

    public function test_fully_translated_scope_returns_empty_when_nothing_qualifies()
    {
        Queue::fake();

        TestTranslatableModel::create([
            'name' => ['en' => 'Test'],
            'description' => ['en' => 'Description'],
        ]);

        $this->assertCount(0, TestTranslatableModel::fullyTranslated()->get());
    }
}
