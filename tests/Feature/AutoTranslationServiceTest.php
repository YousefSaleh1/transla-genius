<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Services\AutoTranslationService;
use CodingPartners\TranslaGenius\Tests\TestCase;
use Illuminate\Http\Client\Request;
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
     * 3. The response is correctly processed
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
                str_contains($request['messages'][0]['content'], 'Translate the following text from en to ar');
        });

        // Assert the response was properly handled
        $this->assertEquals('Translated Text', $result);
    }

    /**
     * Test that translate throws exception on API error
     *
     * Verifies that:
     * 1. The service properly handles API error responses
     * 2. An exception is thrown for non-200 responses
     *
     * @return void
     */
    public function test_translate_throws_exception_on_api_error()
    {
        // Mock an error response
        Http::fake([
            'test-api-url.com' => Http::response([
                'error' => 'Invalid API key'
            ], 401)
        ]);

        $this->expectException(\Illuminate\Http\Exceptions\HttpResponseException::class);

        $service = new AutoTranslationService();
        $service->translate('Hello', 'en', 'ar');
    }
}
