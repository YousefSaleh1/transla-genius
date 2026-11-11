<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;
use CodingPartners\TranslaGenius\Events\TranslationFailed;
use CodingPartners\TranslaGenius\Exceptions\PairsFailedException;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * One pair that fails doesn't stop the others: each success is saved, and
 * the job then fails once with every failed pair listed. A retry requests
 * only what's still missing.
 */
class PairFailuresTest extends TestCase
{
    /** @var array<int, TranslationFailed> */
    private array $failedEvents = [];

    /** @var array<int, string> "field:locale" per request */
    private array $requests = [];

    /** @var array<string, callable(Request): mixed> "field:locale" or "*:locale" => reply */
    private array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config(['translagenius.supported_languages' => ['en', 'ar', 'es', 'fr', 'de']]);
        Event::listen(TranslationFailed::class, function (TranslationFailed $e) {
            $this->failedEvents[] = $e;
        });

        Http::fake(function (Request $request) {
            preg_match('/ to (\w+)\./', $request['messages'][0]['content'], $m);
            $locale = $m[1];
            $text = $request['messages'][1]['content'];
            $field = str_starts_with($text, 'D:') ? 'description' : 'name';
            $this->requests[] = "{$field}:{$locale}";

            foreach (["{$field}:{$locale}", "*:{$locale}", "{$field}:*"] as $key) {
                if (isset($this->replies[$key])) {
                    return ($this->replies[$key])($request);
                }
            }

            return Http::response(['choices' => [['message' => ['content' => "{$text}|{$locale}"], 'finish_reason' => 'stop']]]);
        });
    }

    protected function job(TestTranslatableModel $model): TranslateFields
    {
        return (new TranslateFields(new TranslationJobPayload($model, ['name', 'description'], 'en')))->withFakeQueueInteractions();
    }

    protected function handle(TranslateFields $job): ?\Throwable
    {
        try {
            $job->handle($this->app->make(TranslatorInterface::class), $this->app->make(TranslationWriter::class));
        } catch (\Throwable $e) {
            return $e;
        }

        // A failed job: the queue then calls its failed() (FakeJob doesn't).
        if ($job->job->failedWith !== null) {
            $job->failed($job->job->failedWith);
        }

        return null;
    }

    protected function record(): TestTranslatableModel
    {
        return TestTranslatableModel::withoutEvents(fn () => TestTranslatableModel::create(['name' => ['en' => 'N:Lamp'], 'description' => ['en' => 'D:Bright']]));
    }

    public function test_a_400_on_one_locale_saves_the_others_and_fails_once_listing_it()
    {
        $this->replies['name:ar'] = fn () => Http::response(['error' => ['message' => 'bad request']], 400);
        $model = $this->record();

        $job = $this->job($model);
        $this->assertNull($this->handle($job), 'A non-retryable failure fails the job; it is not thrown to the queue.');

        $name = TestTranslatableModel::find($model->id)->getTranslations('name');
        ksort($name);
        $this->assertSame(['de' => 'N:Lamp|de', 'en' => 'N:Lamp', 'es' => 'N:Lamp|es', 'fr' => 'N:Lamp|fr'], $name);
        $this->assertCount(5, TestTranslatableModel::find($model->id)->getTranslations('description'), 'The next field was translated too.');

        $job->assertFailed();
        $this->assertCount(1, $this->failedEvents);
        $exception = $this->failedEvents[0]->exception;
        $this->assertInstanceOf(PairsFailedException::class, $exception);
        $this->assertSame(['name:ar'], array_map(fn ($f) => "{$f['field']}:{$f['locale']}", $exception->getFailures()));
        $this->assertFalse($exception->isRetryable());
        $this->assertStringContainsString('name/ar', $exception->getMessage());
    }

    public function test_a_retryable_failure_is_retried_and_the_retry_requests_only_what_is_missing()
    {
        $this->replies['description:fr'] = fn () => Http::response([], 503);
        $model = $this->record();

        $exception = $this->handle($this->job($model));
        $this->assertInstanceOf(PairsFailedException::class, $exception);
        $this->assertTrue($exception->isRetryable());
        $this->assertSame(['description:fr'], array_map(fn ($f) => "{$f['field']}:{$f['locale']}", $exception->getFailures()));

        unset($this->replies['description:fr']);
        $this->requests = [];
        // As the queue runs a retry: the record read afresh.
        $this->assertNull($this->handle($this->job(TestTranslatableModel::find($model->id))));

        $this->assertSame(['description:fr'], $this->requests);
        $this->assertSame('D:Bright|fr', TestTranslatableModel::find($model->id)->getTranslation('description', 'fr', false));
    }

    public function test_a_truncated_field_does_not_stop_the_next_field()
    {
        $this->replies['name:*'] = fn () => Http::response(['choices' => [['message' => ['content' => 'cut off'], 'finish_reason' => 'length']]]);
        $model = $this->record();

        $job = $this->job($model);
        $this->handle($job);

        $this->assertCount(5, TestTranslatableModel::find($model->id)->getTranslations('description'));
        $this->assertSame(['en' => 'N:Lamp'], TestTranslatableModel::find($model->id)->getTranslations('name'));
        $job->assertFailed();
        $this->assertCount(4, $this->failedEvents[0]->exception->getFailures());
    }

    /** A bad key fails every pair the same way: the job stops instead of sending the rest. */
    public function test_an_authentication_failure_stops_the_job()
    {
        $this->replies['*:ar'] = fn () => Http::response([], 401);
        $model = $this->record();

        $this->handle($this->job($model));

        $this->assertSame(['name:ar'], $this->requests);
        $this->assertCount(1, $this->failedEvents);
    }
}
