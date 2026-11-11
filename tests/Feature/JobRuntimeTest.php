<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\Events\TranslationRequestBilled;
use CodingPartners\TranslaGenius\Exceptions\RecordBusyException;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Tests\Support\RunsChatServer;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Http\Client\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

/**
 * How long a translation may take, and that two jobs never work on one
 * record at the same time.
 */
class JobRuntimeTest extends TestCase
{
    use RunsChatServer;

    protected function tearDown(): void
    {
        $this->stopChatServer();

        parent::tearDown();
    }

    /** The shipped config's openrouter entry, with no env vars set. */
    protected function shippedEntry(): array
    {
        return (require __DIR__.'/../../src/config/translagenius.php')['providers']['openrouter'];
    }

    public function test_a_12_second_reply_succeeds_with_the_default_timeout()
    {
        $entry = $this->shippedEntry();
        $provider = new OpenAiCompatibleProvider([
            'key' => 'k', 'url' => $this->startChatServer(), 'model' => 'm', 'name' => 'openrouter',
            'timeout' => $entry['timeout'], 'http_retries' => 0, 'headers' => ['X-Delay' => '12'],
        ]);

        $result = $provider->translate(new TranslationRequest('Slow', 'en', 'ar'));

        $this->assertSame('Slow!', $result->text);
    }

    /**
     * A request that timed out may still have been processed, and billed, by
     * the provider: it is reported as `failed`, with unknown (null) tokens.
     */
    public function test_a_timed_out_request_is_billed_as_failed_with_unknown_tokens()
    {
        $billed = [];
        Event::listen(TranslationRequestBilled::class, function (TranslationRequestBilled $e) use (&$billed) {
            $billed[] = $e;
        });

        $provider = new OpenAiCompatibleProvider([
            'key' => 'k', 'url' => $this->startChatServer(), 'model' => 'm', 'name' => 'openrouter',
            'timeout' => 1, 'http_retries' => 0, 'headers' => ['X-Delay' => '3'],
        ]);

        try {
            $provider->translate(new TranslationRequest('Slow', 'en', 'ar'));
            $this->fail('Expected a timeout.');
        } catch (TranslationFailedException $e) {
            $this->assertTrue($e->isRetryable());
        }

        $this->assertCount(1, $billed);
        $this->assertSame(TranslationRequestBilled::FAILED, $billed[0]->outcome);
        $this->assertNull($billed[0]->promptTokens);
        $this->assertNull($billed[0]->completionTokens);
        $this->assertSame('openrouter', $billed[0]->provider);
    }

    /**
     * Default: the worst case for the job's own pairs (HTTP timeout x HTTP
     * attempts per pair x pairs), at least 120 s and at most 900 s.
     * TRANSLATION_JOB_TIMEOUT overrides it.
     */
    public function test_the_job_timeout_is_derived_from_the_worst_case_and_can_be_set()
    {
        config([
            'translagenius.providers.openrouter.timeout' => 60,
            'translagenius.retries.http' => 1,
            'translagenius.supported_languages' => ['en', 'ar', 'es'],
        ]);
        $model = new TestTranslatableModel;

        // 2 fields x 2 targets = 4 pairs; 4 x 2 attempts x 60 s.
        $this->assertSame(480, (new TranslateFields(new TranslationJobPayload($model, ['name', 'description'], 'en')))->timeout);
        // 1 field x 1 target = 1 pair, 120 s: also the floor.
        config(['translagenius.supported_languages' => ['en', 'ar']]);
        $this->assertSame(120, (new TranslateFields(new TranslationJobPayload($model, ['name'], 'en')))->timeout);
        config(['translagenius.providers.openrouter.timeout' => 20]);
        $this->assertSame(120, (new TranslateFields(new TranslationJobPayload($model, ['name'], 'en')))->timeout, '1 x 2 x 20 = 40, raised to the floor');

        config(['translagenius.providers.openrouter.timeout' => 60, 'translagenius.supported_languages' => ['en', 'ar', 'es', 'fr', 'de', 'it', 'pt', 'ja', 'ko']]);
        // 16 pairs would be 1920 s: capped at 900.
        $this->assertSame(900, (new TranslateFields(new TranslationJobPayload($model, ['name', 'description'], 'en')))->timeout);

        config(['translagenius.queue.timeout' => '300']);
        $this->assertSame(300, (new TranslateFields(new TranslationJobPayload($model, ['name', 'description'], 'en')))->timeout);
    }

    /** Run a job the way the queue does: through its middleware. */
    protected function process(TranslateFields $job): void
    {
        (new Pipeline($this->app))
            ->send($job)
            ->through(method_exists($job, 'middleware') ? $job->middleware() : [])
            ->then(fn (TranslateFields $job) => $job->handle($this->app->make(TranslatorInterface::class), $this->app->make(TranslationWriter::class)));
    }

    /**
     * A second job for the same record arrives while the first is still
     * translating (a quick edit, or a redelivery). It must not translate the
     * same pairs in parallel: it waits (re-queued with a short delay, never
     * dropped) and runs once the first is done.
     */
    public function test_two_overlapping_jobs_on_one_record_make_one_set_of_paid_calls()
    {
        Sleep::fake();
        Queue::fake();
        config(['translagenius.supported_languages' => ['en', 'ar', 'es', 'fr', 'de']]); // 2 fields x 4 targets

        $model = TestTranslatableModel::withoutEvents(fn () => TestTranslatableModel::create(['name' => ['en' => 'Lamp'], 'description' => ['en' => 'Bright']]));
        $payload = new TranslationJobPayload($model, ['name', 'description'], 'en');
        $first = (new TranslateFields($payload))->withFakeQueueInteractions();
        $second = (new TranslateFields($payload))->withFakeQueueInteractions();

        $paid = 0;
        Http::fake(function (Request $request) use (&$paid, $second) {
            if ($paid++ === 0) {
                $this->process($second); // arrives while the first job is in flight
            }

            return Http::response(['choices' => [['message' => ['content' => $request['messages'][1]['content'].'*'], 'finish_reason' => 'stop']]]);
        });

        $this->process($first);

        $this->assertSame(8, $paid, 'Only the first job translated.');
        $second->assertDeleted();
        $second->assertNotFailed();
        Queue::assertPushed(TranslateFields::class, 1);

        // The second job, re-queued, runs later and completes: nothing left to pay for.
        // As the queue delivers it: serialized, so the record is read afresh.
        $pushed = Queue::pushed(TranslateFields::class)->first();
        $this->assertSame(TranslateFields::BUSY_RETRY_SECONDS, $pushed->delay);
        $requeued = unserialize(serialize($pushed))->withFakeQueueInteractions();
        $this->process($requeued);

        $requeued->assertNotFailed();
        $this->assertSame(8, $paid);
        $this->assertSame(1, TestTranslatableModel::fullyTranslated()->count());
    }

    /** A re-queued job that finds the record free does its own work. */
    public function test_a_requeued_job_runs_later_and_completes_its_work()
    {
        Sleep::fake();
        Queue::fake();
        Http::fake(fn (Request $request) => Http::response(['choices' => [['message' => ['content' => $request['messages'][1]['content'].'*'], 'finish_reason' => 'stop']]]));

        $model = TestTranslatableModel::withoutEvents(fn () => TestTranslatableModel::create(['name' => ['en' => 'Lamp'], 'description' => ['en' => '']]));
        $payload = new TranslationJobPayload($model, ['name'], 'en');

        // Someone else holds the record's lock: the job is re-queued.
        $lock = cache()->lock(TranslateFields::overlapKey($model), 60);
        $this->assertTrue($lock->get());
        $job = (new TranslateFields($payload))->withFakeQueueInteractions();
        $this->process($job);
        $job->assertDeleted();
        Http::assertNothingSent();
        $lock->release();

        $requeued = unserialize(serialize(Queue::pushed(TranslateFields::class)->first()))->withFakeQueueInteractions();
        $this->process($requeued);

        $requeued->assertNotFailed();
        $this->assertSame('Lamp*', TestTranslatableModel::find($model->id)->getTranslation('name', 'ar', false));
    }

    /**
     * A record whose lock stays held (a hung worker) can't keep its jobs
     * going round the queue forever: after the cap, the job fails, naming
     * the record.
     */
    public function test_a_job_that_keeps_finding_its_record_busy_fails_after_the_cap()
    {
        Queue::fake();
        Http::fake();
        config(['translagenius.queue.max_requeues' => 2]);

        $model = TestTranslatableModel::withoutEvents(fn () => TestTranslatableModel::create(['name' => ['en' => 'Lamp'], 'description' => ['en' => '']]));
        $lock = cache()->lock(TranslateFields::overlapKey($model), 600);
        $this->assertTrue($lock->get());

        $job = (new TranslateFields(new TranslationJobPayload($model, ['name'], 'en')))->withFakeQueueInteractions();
        for ($round = 1; $round <= 2; $round++) {
            $this->process($job);
            $job->assertDeleted();
            $job = unserialize(serialize(Queue::pushed(TranslateFields::class)->last()))->withFakeQueueInteractions();
        }

        $this->process($job);

        $job->assertFailed();
        $this->assertInstanceOf(RecordBusyException::class, $job->job->failedWith);
        $this->assertStringContainsString(TestTranslatableModel::class.' #'.$model->id, $job->job->failedWith->getMessage());
        Queue::assertPushed(TranslateFields::class, 2);
        Http::assertNothingSent();
        $lock->release();
    }
}
