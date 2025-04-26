<?php

namespace CodingPartners\TranslaGenius\Services;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;

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
     * @throws \Illuminate\Http\Exceptions\HttpResponseException When:
     *         - Translation fails after maximum retry attempts
     *         - API returns 4xx/5xx error
     *         - AI model returns empty/invalid response
     * @throws \Illuminate\Http\Client\RequestException When API request fails unrecoverably
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
     * - Constructs specific prompt for translation API
     * - Sets appropriate headers and request parameters
     * - Retries up to 3 times for transient failures (with 200ms, 400ms, 800ms delays)
     * - Skips retry for 4xx errors (client errors)
     * - Validates model response structure
     */
    public function translate($text, $sourceLanguage, $targetLanguage)
    {
        if (empty($this->apiKey) || empty($this->apiUrl)) {
            throw new \RuntimeException('Translation service configuration missing: API key or API URL.');
        }

        $message = "Translate the following text from {$sourceLanguage} to {$targetLanguage}:\n\n{$text}";

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
            "model" => $this->model,
            'messages' => [["role" => "user", "content" => $message]],
            'temperature' => $this->temperature,
            "max_tokens" => $this->maxTokens
        ]);

        if ($response->failed()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Translation failed after 3 attempts',
                'error' => $response->json()['error'] ?? $response->body(),
            ], 500));
        }

        $data = $response->json();

        if (empty($data['choices'][0]['message']['content'])) {
            throw new HttpResponseException(response()->json([
                'message' => 'AI model returned an empty or invalid response',
                'error' => $data['error'] ?? 'No content generated',
            ], 500));
        }

        return $data['choices'][0]['message']['content'];
    }
}
