<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Tests\Support\RunsChatServer;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;

/**
 * A provider keeps one HTTP connection open across its requests: a job of
 * 2 fields x 8 target languages (16 requests), and a second job with the
 * same config, use one connection, not one each. Measured against a real
 * local keep-alive server that counts the connections it accepts.
 */
class ConnectionReuseTest extends TestCase
{
    use RunsChatServer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'translagenius.supported_languages' => ['en', 'ar', 'es', 'fr', 'de', 'it', 'pt', 'ja', 'ko'],
            'translagenius.providers.openrouter.url' => $this->startKeepAliveServer(),
        ]);
    }

    protected function tearDown(): void
    {
        $this->stopChatServer();

        parent::tearDown();
    }

    protected function runJob(string $text): TestTranslatableModel
    {
        $model = TestTranslatableModel::withoutEvents(fn () => TestTranslatableModel::create(['name' => ['en' => $text], 'description' => ['en' => "{$text} description"]]));

        (new TranslateFields(new TranslationJobPayload($model, ['name', 'description'], 'en')))
            ->handle($this->app->make(TranslatorInterface::class), $this->app->make(TranslationWriter::class));

        return TestTranslatableModel::find($model->id);
    }

    public function test_one_connection_serves_a_whole_job_and_the_next_one()
    {
        $model = $this->runJob('Lamp');

        $this->assertCount(9, $model->getTranslations('name'), '16 requests were made and saved.');
        $this->assertSame('Lamp description!', $model->getTranslation('description', 'ko', false));
        $this->assertSame(1, $this->serverConnections(), 'One connection for 16 requests.');

        $this->runJob('Desk');
        $this->assertSame(1, $this->serverConnections(), 'The next job with the same config reuses it.');
    }
}
