<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A provider keeps one HTTP connection open across its requests: a job of
 * 2 fields x 8 target languages (16 requests), and a second job with the
 * same config, use one connection, not one each. Measured against a real
 * local keep-alive server that counts the connections it accepts.
 */
class ConnectionReuseTest extends TestCase
{
    private ?Process $server = null;

    private int $port;

    protected function setUp(): void
    {
        parent::setUp();

        $python = (new ExecutableFinder)->find('python3');
        if ($python === null) {
            $this->markTestSkipped('python3 is needed for the local connection-counting server.');
        }

        // TLS when a certificate is given (TRANSLAGENIUS_TEST_TLS_CERT/_KEY,
        // with PHP told to trust it: -d curl.cainfo=...); plain HTTP otherwise.
        $cert = getenv('TRANSLAGENIUS_TEST_TLS_CERT') ?: null;
        $key = getenv('TRANSLAGENIUS_TEST_TLS_KEY') ?: null;
        $tls = $cert !== null && $key !== null;

        $this->server = new Process(array_merge([$python, __DIR__.'/../fixtures/connection_counting_server.py'], $tls ? [$cert, $key] : []));
        $this->server->start();
        $this->server->waitUntil(fn ($type, $output) => preg_match('/^\d+/', $output) === 1);
        $this->port = (int) trim($this->server->getOutput());

        config([
            'translagenius.supported_languages' => ['en', 'ar', 'es', 'fr', 'de', 'it', 'pt', 'ja', 'ko'],
            'translagenius.providers.openrouter.url' => ($tls ? 'https://localhost' : 'http://127.0.0.1').":{$this->port}/v1/chat/completions",
        ]);
    }

    protected function tearDown(): void
    {
        $this->server?->stop(0);

        parent::tearDown();
    }

    /** Connections the provider opened (not counting this query's own). */
    protected function connections(): int
    {
        $url = str_replace('/v1/chat/completions', '/__connections', (string) config('translagenius.providers.openrouter.url'));
        $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $total = (int) json_decode((string) file_get_contents($url, false, $context), true)['connections'];

        return $total - ++$this->queries;
    }

    private int $queries = 0;

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
        $this->assertSame(1, $this->connections(), 'One connection for 16 requests.');

        $this->runJob('Desk');
        $this->assertSame(1, $this->connections(), 'The next job with the same config reuses it.');
    }
}
