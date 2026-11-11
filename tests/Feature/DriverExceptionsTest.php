<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;
use CodingPartners\TranslaGenius\Exceptions\AllProvidersFailedException;
use CodingPartners\TranslaGenius\Providers\FallbackProvider;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use CodingPartners\TranslaGenius\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * A custom driver may throw its SDK's own exceptions. In a chain, that is a
 * failure of that member like any other: the next member serves the
 * request. A PHP Error (a TypeError) is a bug and keeps propagating.
 */
class DriverExceptionsTest extends TestCase
{
    protected function chainWith(TranslationProviderInterface $first): FallbackProvider
    {
        $second = new class implements TranslationProviderInterface
        {
            public function translate(TranslationRequest $request): TranslationResult
            {
                return new TranslationResult('from the secondary', 'secondary');
            }
        };

        return new FallbackProvider(['sdk' => $first, 'secondary' => $second]);
    }

    public function test_a_custom_drivers_plain_exception_moves_the_chain_on()
    {
        $chain = $this->chainWith(new class implements TranslationProviderInterface
        {
            public function translate(TranslationRequest $request): TranslationResult
            {
                throw new \RuntimeException('SDK: connection reset');
            }
        });

        $this->assertSame('from the secondary', $chain->translate(new TranslationRequest('Hello', 'en', 'ar'))->text);
    }

    public function test_when_every_member_throws_the_wrapped_failure_names_the_member_and_keeps_the_original()
    {
        $throwing = new class implements TranslationProviderInterface
        {
            public function translate(TranslationRequest $request): TranslationResult
            {
                throw new \RuntimeException('SDK: connection reset');
            }
        };
        $chain = new FallbackProvider(['sdk' => $throwing, 'other' => clone $throwing]);

        try {
            $chain->translate(new TranslationRequest('Hello', 'en', 'ar'));
            $this->fail('Expected AllProvidersFailedException.');
        } catch (AllProvidersFailedException $e) {
            $this->assertTrue($e->isRetryable());
            $this->assertSame(['sdk', 'other'], array_column($e->getFailures(), 'provider'));
            $this->assertStringContainsString('sdk: [sdk] RuntimeException: SDK: connection reset', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious()?->getPrevious());
        }
    }

    public function test_a_php_error_is_a_bug_and_keeps_propagating()
    {
        $chain = $this->chainWith(new class implements TranslationProviderInterface
        {
            public function translate(TranslationRequest $request): TranslationResult
            {
                throw new \TypeError('a bug');
            }
        });

        $this->expectException(\TypeError::class);
        $chain->translate(new TranslationRequest('Hello', 'en', 'ar'));
    }

    public function test_a_malformed_usage_value_is_unknown_not_a_crash()
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => 'مرحبا'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 'twelve', 'completion_tokens' => 3.5],
        ])]);

        $result = (new OpenAiCompatibleProvider(['key' => 'k', 'url' => 'https://test-api-url.com', 'model' => 'm']))
            ->translate(new TranslationRequest('Hello', 'en', 'ar'));

        $this->assertSame('مرحبا', $result->text);
        $this->assertNull($result->promptTokens);
        $this->assertNull($result->completionTokens);
    }
}
