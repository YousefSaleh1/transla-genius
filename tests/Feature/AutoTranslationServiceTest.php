<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use CodingPartners\TranslaGenius\Services\AutoTranslationService;
use CodingPartners\TranslaGenius\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * Feature tests for AutoTranslationService
 *
 * This class contains tests that verify the API interaction behavior
 * of the AutoTranslationService class.
 */
class AutoTranslationServiceTest extends TestCase
{
    /**
     * Test that translate method makes correct API request
     *
     * Verifies that:
     * 1. The service makes a properly formatted request to the API endpoint
     * 2. The request contains all required parameters
     * 3. The instruction (system) and source content (user) are sent as
     *    separate chat messages rather than one concatenated string
     * 4. The response is correctly processed
     *
     * @return void
     */
    public function test_translate_makes_correct_api_request()
    {
        // Mock the API response
        Http::fake([
            'test-api-url.com' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Translated Text']]
                ]
            ], 200)
        ]);

        $service = new AutoTranslationService();
        $result = $service->translate('Hello', 'en', 'ar');

        // Assert the request was properly formed
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://test-api-url.com' &&
                $request['model'] === 'test-model' &&
                $request['temperature'] === 0.3 &&
                $request['max_tokens'] === 200 &&
                $request['messages'][0]['role'] === 'system' &&
                str_contains($request['messages'][0]['content'], 'from en to ar') &&
                $request['messages'][1] === ['role' => 'user', 'content' => 'Hello'];
        });

        // Assert the response was properly handled
        $this->assertEquals('Translated Text', $result);
    }

    /**
     * A 401 (bad/invalid API key) is a client error that will fail the same
     * way on retry - it must map to a non-retryable TranslationFailedException.
     *
     * @return void
     */
    public function test_translate_throws_non_retryable_exception_on_auth_error()
    {
        Http::fake([
            'test-api-url.com' => Http::response([
                'error' => 'Invalid API key'
            ], 401)
        ]);

        $service = new AutoTranslationService();

        try {
            $service->translate('Hello', 'en', 'ar');
            $this->fail('Expected TranslationFailedException was not thrown.');
        } catch (TranslationFailedException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertFalse($e->isRetryable());
        }
    }

    /**
     * A 429 (rate limited) is transient by definition and must be retryable,
     * even though it's a 4xx status.
     *
     * @return void
     */
    public function test_translate_throws_retryable_exception_on_rate_limit()
    {
        Http::fake([
            'test-api-url.com' => Http::response(['error' => 'Rate limited'], 429)
        ]);

        $service = new AutoTranslationService();

        try {
            $service->translate('Hello', 'en', 'ar');
            $this->fail('Expected TranslationFailedException was not thrown.');
        } catch (TranslationFailedException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertTrue($e->isRetryable());
        }
    }

    /**
     * A 500 from the provider is transient/provider-side and must be retryable.
     *
     * @return void
     */
    public function test_translate_throws_retryable_exception_on_server_error()
    {
        Http::fake([
            'test-api-url.com' => Http::response(['error' => 'Internal error'], 500)
        ]);

        $service = new AutoTranslationService();

        try {
            $service->translate('Hello', 'en', 'ar');
            $this->fail('Expected TranslationFailedException was not thrown.');
        } catch (TranslationFailedException $e) {
            $this->assertSame(500, $e->getStatusCode());
            $this->assertTrue($e->isRetryable());
        }
    }

    /**
     * A malformed/empty response body (200 status, no choices) must still be
     * treated as a translation failure, and as retryable (could be transient
     * model flakiness rather than a permanent problem).
     *
     * @return void
     */
    public function test_translate_throws_retryable_exception_on_empty_content()
    {
        Http::fake([
            'test-api-url.com' => Http::response(['choices' => []], 200)
        ]);

        $service = new AutoTranslationService();

        try {
            $service->translate('Hello', 'en', 'ar');
            $this->fail('Expected TranslationFailedException was not thrown.');
        } catch (TranslationFailedException $e) {
            $this->assertTrue($e->isRetryable());
        }
    }

    /**
     * A connection-level failure (DNS, timeout, refused) must map to a
     * retryable TranslationFailedException rather than propagating the raw
     * client exception, so the job's retry logic has one exception type to
     * check regardless of failure mode.
     *
     * @return void
     */
    public function test_translate_throws_retryable_exception_on_connection_failure()
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $service = new AutoTranslationService();

        try {
            $service->translate('Hello', 'en', 'ar');
            $this->fail('Expected TranslationFailedException was not thrown.');
        } catch (TranslationFailedException $e) {
            $this->assertTrue($e->isRetryable());
            $this->assertInstanceOf(ConnectionException::class, $e->getPrevious());
        }
    }

    /**
     * Missing configuration (no API key/URL) must fail fast with a
     * non-retryable exception - retrying can't fix a missing credential.
     *
     * @return void
     */
    public function test_translate_throws_non_retryable_exception_on_missing_config()
    {
        Config::set('translaGenius.api.key', null);

        $service = new AutoTranslationService();

        try {
            $service->translate('Hello', 'en', 'ar');
            $this->fail('Expected TranslationFailedException was not thrown.');
        } catch (TranslationFailedException $e) {
            $this->assertFalse($e->isRetryable());
        }
    }
}
