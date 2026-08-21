<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use CodingPartners\TranslaGenius\Providers\OpenRouterProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * Feature tests for OpenRouterProvider - the default
 * TranslationProviderInterface implementation.
 *
 * Deliberately does NOT extend the package's shared TestCase: this class
 * is constructed with a plain config array now (see Phase 2d), not the
 * global config() facade, so it needs a booted Laravel app for the Http/Log
 * facades to resolve, but no database and no package config bootstrap at
 * all. That's the point of the refactor - prove it here.
 */
class OpenRouterProviderTest extends TestCase
{
    protected function config(array $overrides = []): array
    {
        return array_merge([
            'key' => 'test-api-key',
            'url' => 'https://test-api-url.com',
            'model' => 'test-model',
            'timeout' => 10,
            'temperature' => 0.3,
            'max_tokens' => 200,
        ], $overrides);
    }

    /**
     * Test that translate() makes a correctly-shaped API request and
     * returns a TranslationResult built from the response.
     *
     * @return void
     */
    public function test_translate_makes_correct_api_request()
    {
        Http::fake([
            'test-api-url.com' => Http::response([
                'choices' => [['message' => ['content' => 'Translated Text']]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4],
            ], 200)
        ]);

        $provider = new OpenRouterProvider($this->config());
        $result = $provider->translate(new TranslationRequest('Hello', 'en', 'ar'));

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://test-api-url.com' &&
                $request['model'] === 'test-model' &&
                $request['temperature'] === 0.3 &&
                $request['max_tokens'] === 200 &&
                $request['messages'][0]['role'] === 'system' &&
                str_contains($request['messages'][0]['content'], 'from en to ar') &&
                $request['messages'][1] === ['role' => 'user', 'content' => 'Hello'];
        });

        $this->assertEquals('Translated Text', $result->text);
        $this->assertSame('openrouter', $result->provider);
        $this->assertSame(12, $result->promptTokens);
        $this->assertSame(4, $result->completionTokens);
    }

    public function test_throws_non_retryable_exception_on_auth_error()
    {
        Http::fake(['test-api-url.com' => Http::response(['error' => 'Invalid API key'], 401)]);
        $provider = new OpenRouterProvider($this->config());

        try {
            $provider->translate(new TranslationRequest('Hello', 'en', 'ar'));
            $this->fail('Expected TranslationFailedException was not thrown.');
        } catch (TranslationFailedException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertFalse($e->isRetryable());
        }
    }

    public function test_throws_retryable_exception_on_rate_limit()
    {
        Http::fake(['test-api-url.com' => Http::response(['error' => 'Rate limited'], 429)]);
        $provider = new OpenRouterProvider($this->config());

        try {
            $provider->translate(new TranslationRequest('Hello', 'en', 'ar'));
            $this->fail('Expected TranslationFailedException was not thrown.');
        } catch (TranslationFailedException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertTrue($e->isRetryable());
        }
    }

    public function test_throws_retryable_exception_on_server_error()
    {
        Http::fake(['test-api-url.com' => Http::response(['error' => 'Internal error'], 500)]);
        $provider = new OpenRouterProvider($this->config());

        try {
            $provider->translate(new TranslationRequest('Hello', 'en', 'ar'));
            $this->fail('Expected TranslationFailedException was not thrown.');
        } catch (TranslationFailedException $e) {
            $this->assertSame(500, $e->getStatusCode());
            $this->assertTrue($e->isRetryable());
        }
    }

    public function test_throws_retryable_exception_on_empty_content()
    {
        Http::fake(['test-api-url.com' => Http::response(['choices' => []], 200)]);
        $provider = new OpenRouterProvider($this->config());

        try {
            $provider->translate(new TranslationRequest('Hello', 'en', 'ar'));
            $this->fail('Expected TranslationFailedException was not thrown.');
        } catch (TranslationFailedException $e) {
            $this->assertTrue($e->isRetryable());
        }
    }

    public function test_throws_retryable_exception_on_connection_failure()
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });
        $provider = new OpenRouterProvider($this->config());

        try {
            $provider->translate(new TranslationRequest('Hello', 'en', 'ar'));
            $this->fail('Expected TranslationFailedException was not thrown.');
        } catch (TranslationFailedException $e) {
            $this->assertTrue($e->isRetryable());
            $this->assertInstanceOf(ConnectionException::class, $e->getPrevious());
        }
    }

    public function test_throws_non_retryable_exception_on_missing_config()
    {
        $provider = new OpenRouterProvider($this->config(['key' => null]));

        try {
            $provider->translate(new TranslationRequest('Hello', 'en', 'ar'));
            $this->fail('Expected TranslationFailedException was not thrown.');
        } catch (TranslationFailedException $e) {
            $this->assertFalse($e->isRetryable());
        }
    }
}
