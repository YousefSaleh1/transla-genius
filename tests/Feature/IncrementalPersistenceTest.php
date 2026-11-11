<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\Events\TranslationCompleted;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

/**
 * TranslateFields saves each field/locale pair as soon as it is translated,
 * so a retry after a partial failure only requests what is still missing.
 *
 * Fixture: supported languages en (source), ar, es; fields name and
 * description - 4 pairs. The provider is the TestCase's OpenAI-compatible
 * fixture (1 HTTP retry by default), faked per pair.
 */
class IncrementalPersistenceTest extends TestCase
{
    /** @var array<string, int> "field:locale" => requests received */
    private array $requests = [];

    /** @var int|null HTTP status description:ar fails with, or null to succeed. */
    private ?int $descriptionArStatus = 503;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Queue::fake();
        Event::fake([TranslationCompleted::class]);

        Http::fake(function (Request $request) {
            $field = $request['messages'][1]['content'] === 'Source name' ? 'name' : 'description';
            preg_match('/ to (\w+)\./', $request['messages'][0]['content'], $m);
            $pair = "{$field}:{$m[1]}";
            $this->requests[$pair] = ($this->requests[$pair] ?? 0) + 1;

            if ($pair === 'description:ar' && $this->descriptionArStatus !== null) {
                return Http::response(['error' => ['message' => 'unavailable']], $this->descriptionArStatus);
            }

            return Http::response(['choices' => [['message' => ['content' => "{$pair} text"], 'finish_reason' => 'stop']]]);
        });
    }

    protected function runJobOn(TestTranslatableModel $model): void
    {
        (new TranslateFields($model, ['name', 'description']))
            ->handle($this->app->make(TranslatorInterface::class), $this->app->make(TranslationWriter::class));
    }

    public function test_retry_after_a_partial_failure_only_requests_the_missing_pairs()
    {
        $model = TestTranslatableModel::create([
            'name' => ['en' => 'Source name'],
            'description' => ['en' => 'Source description'],
        ]);

        // Attempt 1: name:ar and name:es succeed; description:ar keeps
        // returning 503 (sent twice: first try + 1 HTTP retry), so the job
        // throws a retryable failure before reaching description:es.
        try {
            $this->runJobOn($model);
            $this->fail('Expected the first attempt to fail.');
        } catch (TranslationFailedException $e) {
            $this->assertTrue($e->isRetryable());
        }

        $this->assertSame(['name:ar' => 1, 'name:es' => 1, 'description:ar' => 2], $this->requests);

        // The two successful pairs are already in the database.
        $stored = TestTranslatableModel::find($model->id);
        $this->assertSame('name:ar text', $stored->getTranslation('name', 'ar', false));
        $this->assertSame('name:es text', $stored->getTranslation('name', 'es', false));
        $this->assertSame('', $stored->getTranslation('description', 'ar', false));

        // ...and the scopes see the record as partially translated.
        $this->assertTrue(TestTranslatableModel::notFullyTranslated()->whereKey($model->id)->exists());
        $this->assertFalse(TestTranslatableModel::fullyTranslated()->whereKey($model->id)->exists());

        // Attempt 2: the queue re-reads the model, as SerializesModels does.
        $this->descriptionArStatus = null;
        $this->requests = [];
        $this->runJobOn(TestTranslatableModel::find($model->id));

        // 0 requests for the pairs saved in attempt 1.
        $this->assertSame(['description:ar' => 1, 'description:es' => 1], $this->requests);

        $this->assertTrue(TestTranslatableModel::fullyTranslated()->whereKey($model->id)->exists());
        $this->assertFalse(TestTranslatableModel::notFullyTranslated()->whereKey($model->id)->exists());

        // TranslationCompleted fired exactly once per successful pair, 4 in all.
        Event::assertDispatchedTimes(TranslationCompleted::class, 4);
        foreach (['name:ar', 'name:es', 'description:ar', 'description:es'] as $pair) {
            [$field, $locale] = explode(':', $pair);
            $this->assertCount(1, Event::dispatched(
                TranslationCompleted::class,
                fn (TranslationCompleted $e) => $e->field === $field && $e->targetLocale === $locale
            ), "TranslationCompleted for {$pair}");
        }

        // The incremental saves ran without model events: the only job ever
        // pushed is the one from the original create().
        Queue::assertPushed(TranslateFields::class, 1);
    }

    public function test_a_permanently_failed_job_leaves_its_successful_pairs_saved()
    {
        // description:ar now fails with a non-retryable 400.
        $this->descriptionArStatus = 400;

        $model = TestTranslatableModel::create([
            'name' => ['en' => 'Source name'],
            'description' => ['en' => 'Source description'],
        ]);

        $job = (new TranslateFields($model, ['name', 'description']))->withFakeQueueInteractions();
        $job->handle($this->app->make(TranslatorInterface::class), $this->app->make(TranslationWriter::class));

        $job->assertFailed();
        $this->assertSame(['name:ar' => 1, 'name:es' => 1, 'description:ar' => 1], $this->requests);

        $stored = TestTranslatableModel::find($model->id);
        $this->assertSame(['en', 'ar', 'es'], array_keys($stored->getTranslations('name')));
        $this->assertTrue(TestTranslatableModel::notFullyTranslated()->whereKey($model->id)->exists());
        $this->assertFalse(TestTranslatableModel::fullyTranslated()->whereKey($model->id)->exists());
    }
}
