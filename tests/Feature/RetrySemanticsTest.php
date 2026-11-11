<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Exact request counts for OpenAiCompatibleProvider's HTTP-level retries.
 * Sleep is faked throughout, so nothing here actually waits.
 */
class RetrySemanticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    protected function provider(array $overrides = []): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider(array_merge([
            'name' => 'test',
            'key' => 'k',
            'url' => 'https://api.test/v1/chat/completions',
            'model' => 'm',
        ], $overrides));
    }

    protected function translateExpectingFailure(OpenAiCompatibleProvider $provider): TranslationFailedException
    {
        try {
            $provider->translate(new TranslationRequest('Hello', 'en', 'ar'));
        } catch (TranslationFailedException $e) {
            return $e;
        }

        $this->fail('Expected a TranslationFailedException.');
    }

    public function test_401_is_sent_once_and_is_not_retryable()
    {
        Http::fake(['api.test/*' => Http::response(['error' => ['message' => 'bad key']], 401)]);

        $e = $this->translateExpectingFailure($this->provider());

        Http::assertSentCount(1);
        $this->assertFalse($e->isRetryable());
        $this->assertSame(401, $e->getStatusCode());
        Sleep::assertNeverSlept();
    }

    public static function nonRetryableStatuses(): array
    {
        return ['400' => [400], '403' => [403], '404' => [404], '422' => [422]];
    }

    #[DataProvider('nonRetryableStatuses')]
    public function test_other_4xx_are_sent_once_and_are_not_retryable(int $status)
    {
        Http::fake(['api.test/*' => Http::response([], $status)]);

        $e = $this->translateExpectingFailure($this->provider());

        Http::assertSentCount(1);
        $this->assertFalse($e->isRetryable());
        Sleep::assertNeverSlept();
    }

    public function test_429_with_retry_after_waits_the_stated_delay_then_retries()
    {
        Http::fake(['api.test/*' => Http::sequence()
            ->push(['error' => ['message' => 'slow down']], 429, ['Retry-After' => '3'])
            ->push(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']]], 200),
        ]);

        $result = $this->provider()->translate(new TranslationRequest('Hello', 'en', 'ar'));

        $this->assertSame('ok', $result->text);
        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(3)->seconds()]);
    }

    public function test_429_with_a_retry_after_too_long_to_wait_hands_the_delay_to_the_queue()
    {
        Http::fake(['api.test/*' => Http::response([], 429, ['Retry-After' => '120'])]);

        $e = $this->translateExpectingFailure($this->provider());

        Http::assertSentCount(1);
        $this->assertTrue($e->isRetryable());
        $this->assertSame(120, $e->getRetryAfter());
        Sleep::assertNeverSlept();
    }

    public function test_429_without_retry_after_gets_one_quick_retry()
    {
        Http::fake(['api.test/*' => Http::response([], 429)]);

        $e = $this->translateExpectingFailure($this->provider());

        Http::assertSentCount(2);
        $this->assertTrue($e->isRetryable());
        $this->assertNull($e->getRetryAfter());
        Sleep::assertSleptTimes(1);
    }

    public function test_persistent_5xx_is_sent_twice_by_default_then_retryable()
    {
        Http::fake(['api.test/*' => Http::response([], 503)]);

        $e = $this->translateExpectingFailure($this->provider());

        Http::assertSentCount(2);
        $this->assertTrue($e->isRetryable());
        $this->assertSame(503, $e->getStatusCode());
        Sleep::assertSleptTimes(1);
    }

    public function test_5xx_then_success_recovers_within_the_request()
    {
        Http::fake(['api.test/*' => Http::sequence()->push([], 500)->push(
            ['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']]],
            200
        )]);

        $this->assertSame('ok', $this->provider()->translate(new TranslationRequest('Hello', 'en', 'ar'))->text);
        Http::assertSentCount(2);
    }

    public function test_http_retries_zero_sends_once()
    {
        Http::fake(['api.test/*' => Http::response([], 502)]);

        $this->translateExpectingFailure($this->provider(['http_retries' => 0]));

        Http::assertSentCount(1);
    }

    public function test_http_retries_three_sends_four_times()
    {
        Http::fake(['api.test/*' => Http::response([], 502)]);

        $this->translateExpectingFailure($this->provider(['http_retries' => 3]));

        Http::assertSentCount(4);
    }

    public function test_connection_errors_get_one_quick_retry()
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('connection refused');
        });

        $e = $this->translateExpectingFailure($this->provider());

        $this->assertSame(2, $calls);
        $this->assertTrue($e->isRetryable());
    }
}
