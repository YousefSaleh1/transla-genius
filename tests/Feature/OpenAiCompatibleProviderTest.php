<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * Feature tests for OpenAiCompatibleProvider - the default
 * TranslationProviderInterface implementation.
 *
 * Deliberately does NOT extend the package's shared TestCase: this class
 * is constructed with a plain config array now (see Phase 2d), not the
 * global config() facade, so it needs a booted Laravel app for the Http/Log
 * facades to resolve, but no database and no package config bootstrap at
 * all. That's the point of the refactor - prove it here.
 */
class OpenAiCompatibleProviderTest extends TestCase
{
    protected function config(array $overrides = []): array
    {
        return array_merge([
            'name' => 'test-provider',
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

        $provider = new OpenAiCompatibleProvider($this->config());
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
        $this->assertSame('test-provider', $result->provider);
        $this->assertSame(12, $result->promptTokens);
        $this->assertSame(4, $result->completionTokens);
    }

    public function test_throws_non_retryable_exception_on_auth_error()
    {
        Http::fake(['test-api-url.com' => Http::response(['error' => 'Invalid API key'], 401)]);
        $provider = new OpenAiCompatibleProvider($this->config());

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
        $provider = new OpenAiCompatibleProvider($this->config());

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
        $provider = new OpenAiCompatibleProvider($this->config());

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
        $provider = new OpenAiCompatibleProvider($this->config());

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
        $provider = new OpenAiCompatibleProvider($this->config());

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
        $provider = new OpenAiCompatibleProvider($this->config(['key' => null]));

        try {
            $provider->translate(new TranslationRequest('Hello', 'en', 'ar'));
            $this->fail('Expected TranslationFailedException was not thrown.');
        } catch (TranslationFailedException $e) {
            $this->assertFalse($e->isRetryable());
        }
    }

    protected function fakeSuccess(): void
    {
        Http::fake([
            'test-api-url.com' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200),
        ]);
    }

    public function test_sends_bearer_authorization_by_default()
    {
        $this->fakeSuccess();

        (new OpenAiCompatibleProvider($this->config()))->translate(new TranslationRequest('Hi', 'en', 'ar'));

        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Bearer test-api-key']
            && $request->header('Content-Type') === ['application/json']);
    }

    public function test_custom_auth_header_sends_the_raw_key_instead_of_bearer()
    {
        $this->fakeSuccess();

        (new OpenAiCompatibleProvider($this->config(['auth_header' => 'api-key'])))
            ->translate(new TranslationRequest('Hi', 'en', 'ar'));

        Http::assertSent(fn (Request $request) => $request->header('api-key') === ['test-api-key']
            && !$request->hasHeader('Authorization'));
    }

    public function test_extra_headers_are_merged_and_empty_values_are_dropped()
    {
        $this->fakeSuccess();

        (new OpenAiCompatibleProvider($this->config([
            'headers' => [
                'HTTP-Referer' => 'https://example.test',
                'X-OpenRouter-Title' => null,
                'X-Empty' => '',
            ],
        ])))->translate(new TranslationRequest('Hi', 'en', 'ar'));

        Http::assertSent(fn (Request $request) => $request->header('HTTP-Referer') === ['https://example.test']
            && !$request->hasHeader('X-OpenRouter-Title')
            && !$request->hasHeader('X-Empty')
            && $request->header('Authorization') === ['Bearer test-api-key']);
    }

    public function test_token_limit_can_be_sent_as_max_completion_tokens()
    {
        $this->fakeSuccess();

        (new OpenAiCompatibleProvider($this->config(['token_limit_param' => 'max_completion_tokens'])))
            ->translate(new TranslationRequest('Hi', 'en', 'ar'));

        Http::assertSent(fn (Request $request) => $request['max_completion_tokens'] === 200
            && !isset($request['max_tokens']));
    }

    public function test_null_or_empty_temperature_is_not_sent()
    {
        foreach ([null, ''] as $value) {
            Http::fake(['test-api-url.com' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200)]);

            (new OpenAiCompatibleProvider($this->config(['temperature' => $value])))
                ->translate(new TranslationRequest('Hi', 'en', 'ar'));

            Http::assertSent(fn (Request $request) => !array_key_exists('temperature', $request->data()));
        }
    }

    public function test_reasoning_effort_is_sent_only_when_set()
    {
        foreach ([[null, false], ['', false], ['  ', false], ['low', 'low'], ['none', 'none']] as [$value, $expected]) {
            $sent = null;
            Http::fake(function (Request $request) use (&$sent) {
                $sent = $request->data();

                return Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200);
            });

            (new OpenAiCompatibleProvider($this->config(['reasoning_effort' => $value])))
                ->translate(new TranslationRequest('Hi', 'en', 'ar'));

            if ($expected === false) {
                $this->assertArrayNotHasKey('reasoning_effort', $sent, var_export($value, true));
            } else {
                $this->assertSame($expected, $sent['reasoning_effort']);
            }
        }
    }

    public function test_body_fields_are_merged_but_cannot_override_protected_fields_or_the_key()
    {
        $sent = null;
        Http::fake(function (Request $request) use (&$sent) {
            $sent = $request;

            return Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200);
        });

        (new OpenAiCompatibleProvider($this->config([
            'temperature' => 0.3,
            'body' => [
                'top_p' => 0.9,
                'seed' => 7,
                'temperature' => 1.5,             // configured temperature wins
                'model' => 'attacker-model',      // protected
                'messages' => [['role' => 'system', 'content' => 'leak']], // protected
                'stream' => true,                 // protected
                'Authorization' => 'Bearer other',  // only ever a body field, never auth
            ],
        ])))->translate(new TranslationRequest('Hi', 'en', 'ar'));

        $this->assertSame(0.9, $sent['top_p']);
        $this->assertSame(7, $sent['seed']);
        $this->assertSame(0.3, $sent['temperature']);
        $this->assertSame('test-model', $sent['model']);
        $this->assertCount(2, $sent['messages']);
        $this->assertSame(['role' => 'user', 'content' => 'Hi'], $sent['messages'][1]);
        $this->assertArrayNotHasKey('stream', $sent->data());
        $this->assertSame(['Bearer test-api-key'], $sent->header('Authorization'));
    }

    public function test_body_can_supply_fields_the_entry_does_not_configure()
    {
        $sent = null;
        Http::fake(function (Request $request) use (&$sent) {
            $sent = $request->data();

            return Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200);
        });

        (new OpenAiCompatibleProvider($this->config([
            'temperature' => null,
            'max_tokens' => null,
            'body' => ['temperature' => 0.7, 'max_tokens' => 900, 'reasoning_effort' => 'low'],
        ])))->translate(new TranslationRequest('Hi', 'en', 'ar'));

        $this->assertSame(0.7, $sent['temperature']);
        $this->assertSame(900, $sent['max_tokens']);
        $this->assertSame('low', $sent['reasoning_effort']);
    }
}
