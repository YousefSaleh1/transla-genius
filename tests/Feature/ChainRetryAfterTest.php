<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\Exceptions\AllProvidersFailedException;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Providers\FallbackProvider;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Services\Translator;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

/**
 * A fallback chain whose members all ask to wait (429 + Retry-After) is
 * released for the shortest wait: the earliest moment any member is likely
 * to answer again.
 */
class ChainRetryAfterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Queue::fake();

        $member = fn (string $host) => ['driver' => OpenAiCompatibleProvider::class, 'key' => 'k', 'url' => "https://{$host}/v1/chat/completions", 'model' => 'm'];
        config([
            'translagenius.default' => 'chain',
            'translagenius.providers.first' => $member('first.test'),
            'translagenius.providers.second' => $member('second.test'),
            'translagenius.providers.chain' => ['driver' => FallbackProvider::class, 'chain' => ['first', 'second']],
        ]);
        $this->app->forgetInstance(TranslationProviderInterface::class);
        $this->app->forgetInstance(Translator::class);
        $this->app->forgetInstance(TranslatorInterface::class);
    }

    protected function job(): TranslateFields
    {
        $model = TestTranslatableModel::withoutEvents(fn () => TestTranslatableModel::create(['name' => ['en' => 'Hello'], 'description' => ['en' => '']]));

        return (new TranslateFields(new TranslationJobPayload($model, ['name'], 'en')))->withFakeQueueInteractions();
    }

    protected function handleJob(TranslateFields $job): void
    {
        $job->handle($this->app->make(TranslatorInterface::class), new TranslationWriter);
    }

    public function test_the_chain_exception_carries_the_shortest_retry_after()
    {
        Http::fake([
            'first.test/*' => Http::response([], 429, ['Retry-After' => '40']),
            'second.test/*' => Http::response([], 429, ['Retry-After' => '20']),
        ]);

        try {
            $this->app->make(TranslationProviderInterface::class)->translate(new TranslationRequest('Hello', 'en', 'ar'));
            $this->fail('Expected AllProvidersFailedException.');
        } catch (AllProvidersFailedException $e) {
            $this->assertSame(20, $e->getRetryAfter());
            $this->assertTrue($e->isRetryable());
        }
    }

    public function test_the_job_is_released_for_the_shortest_retry_after()
    {
        Http::fake([
            'first.test/*' => Http::response([], 429, ['Retry-After' => '40']),
            'second.test/*' => Http::response([], 429, ['Retry-After' => '20']),
        ]);

        $job = $this->job();
        $this->handleJob($job);

        $job->assertReleased(20);
        $job->assertNotFailed();
    }

    public function test_on_the_last_attempt_it_is_not_released()
    {
        config(['translagenius.retries.job_tries' => 1]);
        Http::fake([
            'first.test/*' => Http::response([], 429, ['Retry-After' => '40']),
            'second.test/*' => Http::response([], 429, ['Retry-After' => '20']),
        ]);

        $job = $this->job();

        try {
            $this->handleJob($job);
            $this->fail('Expected the exception to propagate.');
        } catch (TranslationFailedException $e) {
            $this->assertSame(20, $e->getRetryAfter());
        }

        $job->assertNotReleased();
    }

    /**
     * A member that failed without a stated wait may be back at any moment,
     * so there's no shortest wait to use: the job's own backoff applies.
     */
    public function test_a_member_without_retry_after_means_no_chain_retry_after()
    {
        Http::fake([
            'first.test/*' => Http::response([], 429, ['Retry-After' => '40']),
            'second.test/*' => Http::response([], 503),
        ]);

        $job = $this->job();

        try {
            $this->handleJob($job);
            $this->fail('Expected the exception to propagate.');
        } catch (TranslationFailedException $e) {
            $this->assertNull($e->getRetryAfter());
        }

        $job->assertNotReleased();
    }

    /** A member that failed for good (a bad key) won't come back; its lack of a wait doesn't count. */
    public function test_a_permanently_failed_member_is_ignored()
    {
        Http::fake([
            'first.test/*' => Http::response([], 401),
            'second.test/*' => Http::response([], 429, ['Retry-After' => '30']),
        ]);

        $job = $this->job();
        $this->handleJob($job);

        $job->assertReleased(30);
    }
}
