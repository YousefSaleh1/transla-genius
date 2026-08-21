<?php

namespace CodingPartners\TranslaGenius\Services;

use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Class AutoTranslationService
 *
 * This class provides functionality to automatically translate text using an external API.
 * It handles the configuration and communication with the translation API, and returns the translated text.
 *
 * @package CodingPartners\TranslaGenius\Services
 */
class AutoTranslationService
{
    /**
     * @var string $apiKey The API key for authenticating with the translation service.
     */
    protected $apiKey;

    /**
     * @var string $apiUrl The URL of the translation API endpoint.
     */
    protected $apiUrl;

    /**
     * @var string $model The model to be used for translation.
     */
    protected $model;

    /**
     * @var float $temperature The temperature parameter for the translation model.
     */
    protected $temperature;

    /**
     * @var int $maxTokens The maximum number of tokens to generate in the translation.
     */
    protected $maxTokens;

    /**
     * AutoTranslationService constructor.
     *
     * Initializes the service by loading configuration values from the application's configuration.
     */
    public function __construct()
    {
        $this->apiKey = config('translaGenius.api.key');
        $this->apiUrl = config('translaGenius.api.url');
        $this->model = config('translaGenius.api.model');
        $this->temperature = config('translaGenius.settings.temperature');
        $this->maxTokens = config('translaGenius.settings.max_tokens');
    }

    /**
     * Translates text from source language to target language using external API
     *
     * @param string $text The text content to be translated
     * @param string $sourceLanguage Source language code (ISO 639-1)
     * @param string $targetLanguage Target language code (ISO 639-1)
     *
     * @return string The translated text content
     *
     * @throws \CodingPartners\TranslaGenius\Exceptions\TranslationFailedException When:
     *         - The service is missing its API key/URL configuration (not retryable)
     *         - The API request fails after retries, or returns a 4xx/5xx status
     *         - The AI model returns an empty or invalid response
     *
     * @example
     * $translated = $service->translate('Hello', 'en', 'ar');
     * // Returns: "مرحبا"
     *
     * @uses
     * - Requires valid API key and configuration
     * - Uses HTTP client with automatic retry mechanism
     * - Implements exponential backoff with jitter for retries
     * - Processes JSON response to extract translated content
     *
     * @internal
     * - Sends the translation instruction as a `system` message and the source text as a
     *   separate `user` message, rather than concatenating them into one string — this
     *   keeps the instruction and untrusted field content in distinct roles instead of a
     *   single free-form blob.
     * - Sets appropriate headers and request parameters
     * - Retries up to 3 times for transient failures (with 200ms, 400ms, 800ms delays)
     * - Skips retry for 4xx errors (client errors)
     * - Validates model response structure
     * - Never logs the API key, full request/response bodies, or raw stack traces —
     *   see TranslationFailedException for the context carried instead.
     */
    public function translate($text, $sourceLanguage, $targetLanguage)
    {
        if (empty($this->apiKey) || empty($this->apiUrl)) {
            Log::error('AutoTranslationService: missing API key or URL configuration.');

            throw new TranslationFailedException(
                'Translation service configuration missing: API key or API URL.',
                retryable: false
            );
        }

        $systemPrompt = "You are a translation engine. Translate the user's message from {$sourceLanguage} to {$targetLanguage}. "
            . 'Respond with only the translated text - no explanation, commentary, or additional formatting.';

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->retry(
                3,
                function (int $attempt, \Exception $e) {
                    if ($e instanceof \Illuminate\Http\Client\RequestException && $e->response->status() >= 400) {
                        return false;
                    }

                    return min(1000, 200 * (2 ** ($attempt - 1))) + rand(0, 100);
                },
                throw: false
            )->post($this->apiUrl, [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $text],
                ],
                'temperature' => $this->temperature,
                'max_tokens' => $this->maxTokens,
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('AutoTranslationService: connection to translation API failed', [
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            throw new TranslationFailedException(
                'Unable to connect to translation API: ' . $e->getMessage(),
                retryable: true,
                previous: $e
            );
        }

        if ($response->failed()) {
            $status = $response->status();
            $snippet = Str::limit((string) ($response->json('error.message') ?? $response->body()), 300);

            Log::warning('AutoTranslationService: API request failed', [
                'status' => $status,
                'error' => $snippet,
            ]);

            throw new TranslationFailedException(
                "Translation request failed with status {$status}",
                statusCode: $status,
                responseSnippet: $snippet,
                retryable: $this->isRetryableStatus($status)
            );
        }

        $content = $response->json('choices.0.message.content');

        if (empty($content)) {
            Log::warning('AutoTranslationService: API returned empty or invalid content', [
                'status' => $response->status(),
            ]);

            throw new TranslationFailedException(
                'AI model returned an empty or invalid response',
                statusCode: $response->status(),
                retryable: true
            );
        }

        Log::info('AutoTranslationService: translation successful');

        return $content;
    }

    /**
     * Whether a failed response with this HTTP status is worth retrying.
     *
     * 4xx client errors (bad request, invalid/expired key, not found) won't
     * self-resolve on retry - the same request will fail the same way. The
     * one exception is 429 (rate limited), which is inherently transient.
     * Everything else (5xx, or no status at all) is treated as retryable.
     *
     * @param int $status
     * @return bool
     */
    protected function isRetryableStatus(int $status): bool
    {
        if ($status === 429) {
            return true;
        }

        return $status < 400 || $status >= 500;
    }
}
