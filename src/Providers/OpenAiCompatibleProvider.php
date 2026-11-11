<?php

namespace CodingPartners\TranslaGenius\Providers;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Default TranslationProviderInterface implementation: any OpenAI-compatible
 * chat-completions endpoint (OpenRouter by default; a self-hosted gateway or
 * OpenAI itself would also work, since they speak the same request shape).
 *
 * Owns everything specific to *this* kind of backend - HTTP transport, the
 * chat-message prompt, and response parsing - so that a non-chat-completions
 * provider (DeepL, Google Translate) isn't forced to share any of it. See
 * TranslationProviderInterface for why the contract itself stays neutral.
 *
 * Configured entirely through the array passed to the constructor (see
 * translaGenius.providers.openrouter in config/translaGenius.php) rather
 * than reading the global config() facade directly - makes this class
 * constructible and testable with a plain array, no app config needed.
 */
class OpenAiCompatibleProvider implements TranslationProviderInterface
{
    /** @var string API key sent as a Bearer token; blank fails fast in translate(). */
    protected string $apiKey;

    /** @var string Full chat-completions endpoint URL to POST to. */
    protected string $apiUrl;

    /** @var string Model identifier passed through to the API (e.g. "openai/gpt-4o"). */
    protected string $model;

    /** @var float Sampling temperature passed through to the API. */
    protected float $temperature;

    /** @var int Maximum tokens the API may generate for the response. */
    protected int $maxTokens;

    /** @var int HTTP request timeout, in seconds. */
    protected int $timeout;

    /**
     * @var string|null The translaGenius.providers key this instance was
     *      resolved under (e.g. "gemini"), reported on every TranslationResult.
     */
    protected ?string $name;

    /**
     * @var string Header the API key is sent in. "Authorization" (the
     *      default) sends "Bearer <key>"; any other header (e.g. Azure
     *      OpenAI's "api-key") sends the raw key.
     */
    protected string $authHeader;

    /** @var array<string, string> Extra headers merged into every request. */
    protected array $headers;

    /**
     * @var string Request-body field $maxTokens is sent as: "max_tokens"
     *      (default) or "max_completion_tokens", which OpenAI's own API now
     *      prefers and its reasoning models require.
     */
    protected string $tokenLimitParam;

    /**
     * @var int Extra HTTP attempts after the first, for connection errors,
     *      429 and 5xx only. 4xx (other than 429) is never retried.
     */
    protected int $httpRetries;

    /**
     * @var int Longest Retry-After (seconds) worth sleeping through inside
     *      the request. A longer one is handed to the queue instead (see
     *      TranslationFailedException::getRetryAfter()).
     */
    protected int $retryAfterMax;

    /**
     * @param array{name?: string, key?: string, url?: string, model?: string, temperature?: float, max_tokens?: int, timeout?: int, auth_header?: string, headers?: array<string, string>, token_limit_param?: string, http_retries?: int, retry_after_max?: int} $config
     *        Provider configuration array - one translaGenius.providers.* entry
     *        from config/translaGenius.php. `name` is injected by the service
     *        provider (the entry's config key). Every key is optional; missing
     *        key/url are caught explicitly in translate() rather than left
     *        to fail as an opaque HTTP error.
     */
    public function __construct(array $config)
    {
        $this->apiKey = (string) ($config['key'] ?? '');
        $this->apiUrl = (string) ($config['url'] ?? '');
        $this->model = (string) ($config['model'] ?? '');
        $this->temperature = (float) ($config['temperature'] ?? 0.3);
        $this->maxTokens = (int) ($config['max_tokens'] ?? 200);
        $this->timeout = (int) ($config['timeout'] ?? 10);
        $this->name = isset($config['name']) ? (string) $config['name'] : null;
        $this->authHeader = (string) ($config['auth_header'] ?? 'Authorization') ?: 'Authorization';
        $this->httpRetries = max(0, (int) ($config['http_retries'] ?? 1));
        $this->retryAfterMax = max(0, (int) ($config['retry_after_max'] ?? 10));
        $this->tokenLimitParam = (string) ($config['token_limit_param'] ?? 'max_tokens') ?: 'max_tokens';
        $this->headers = array_filter(
            (array) ($config['headers'] ?? []),
            fn ($value) => $value !== null && $value !== ''
        );
    }

    /**
     * Translate the given request via an OpenAI-compatible chat-completions
     * endpoint. Connection errors, 429 and 5xx get up to $httpRetries quick
     * retries (a 429's Retry-After is honored if it's at most
     * $retryAfterMax seconds); anything else fails on the first response.
     *
     * @param TranslationRequest $request The text and locale pair to translate.
     * @return TranslationResult
     *
     * @throws TranslationFailedException If the API key/URL are missing, the
     *         connection fails, the API returns a failed response, or the
     *         response contains no translated content. `retryable` is set
     *         per failure mode - see isRetryableStatus().
     */
    public function translate(TranslationRequest $request): TranslationResult
    {
        if (empty($this->apiKey) || empty($this->apiUrl)) {
            Log::error('OpenAiCompatibleProvider: missing API key or URL configuration.');

            throw new TranslationFailedException(
                'Translation provider configuration missing: API key or API URL.',
                retryable: false
            );
        }

        $systemPrompt = "You are a translation engine. Translate the user's message from {$request->sourceLocale} to {$request->targetLocale}. "
            . 'Respond with only the translated text - no explanation, commentary, or additional formatting.';

        $body = $this->requestBody($request, $systemPrompt);
        $attempts = $this->httpRetries + 1;

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = Http::withHeaders($this->requestHeaders())
                    ->timeout($this->timeout)
                    ->post($this->apiUrl, $body);
            } catch (ConnectionException $e) {
                if ($attempt < $attempts) {
                    $this->pause($attempt);

                    continue;
                }

                Log::warning('OpenAiCompatibleProvider: connection to translation API failed', [
                    'exception_class' => get_class($e),
                    'message' => $e->getMessage(),
                    'attempts' => $attempt,
                ]);

                throw new TranslationFailedException(
                    'Unable to connect to translation API: ' . $e->getMessage(),
                    retryable: true,
                    previous: $e
                );
            }

            if (!$response->failed()) {
                break;
            }

            $status = $response->status();
            $retryable = $this->isRetryableStatus($status);
            $retryAfter = $status === 429 ? $this->parseRetryAfter($response) : null;

            if ($retryable && $attempt < $attempts) {
                if ($retryAfter === null) {
                    $this->pause($attempt);

                    continue;
                }

                if ($retryAfter <= $this->retryAfterMax) {
                    Sleep::for($retryAfter)->seconds();

                    continue;
                }
                // Asked to wait longer than is reasonable inside one request:
                // fail now and let the queue retry after $retryAfter.
            }

            $snippet = Str::limit((string) ($response->json('error.message') ?? $response->body()), 300);

            Log::warning('OpenAiCompatibleProvider: API request failed', [
                'status' => $status,
                'error' => $snippet,
                'attempts' => $attempt,
            ]);

            throw new TranslationFailedException(
                "Translation request failed with status {$status}",
                statusCode: $status,
                responseSnippet: $snippet,
                retryable: $retryable,
                retryAfter: $retryAfter,
            );
        }

        $content = $response->json('choices.0.message.content');

        if (empty($content)) {
            Log::warning('OpenAiCompatibleProvider: API returned empty or invalid content', [
                'status' => $response->status(),
            ]);

            throw new TranslationFailedException(
                'AI model returned an empty or invalid response',
                statusCode: $response->status(),
                retryable: true
            );
        }

        Log::info('OpenAiCompatibleProvider: translation successful');

        return new TranslationResult(
            text: $content,
            provider: $this->name,
            promptTokens: $response->json('usage.prompt_tokens'),
            completionTokens: $response->json('usage.completion_tokens'),
        );
    }

    /**
     * The chat-completions request body.
     *
     * @param TranslationRequest $request
     * @param string $systemPrompt
     * @return array<string, mixed>
     */
    protected function requestBody(TranslationRequest $request, string $systemPrompt): array
    {
        return [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $request->text],
            ],
            'temperature' => $this->temperature,
            $this->tokenLimitParam => $this->maxTokens,
        ];
    }

    /**
     * Short jittered backoff between HTTP attempts: 200ms, 400ms, ... capped
     * at 1s. Kept brief on purpose - anything slower is the queue's job.
     *
     * @param int $attempt The attempt that just failed (1-based).
     * @return void
     */
    protected function pause(int $attempt): void
    {
        Sleep::for(min(1000, 200 * (2 ** ($attempt - 1))) + random_int(0, 100))->milliseconds();
    }

    /**
     * Seconds from a Retry-After header, which may be delta-seconds or an
     * HTTP date. Null when absent or unparseable.
     *
     * @param Response $response
     * @return int|null
     */
    protected function parseRetryAfter(Response $response): ?int
    {
        $value = trim($response->header('Retry-After'));

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }

    /**
     * Headers for every request: JSON content type, the API key in
     * $authHeader, then any configured extra headers (which win on a clash,
     * so a service with unusual requirements can override the defaults).
     *
     * @return array<string, string>
     */
    protected function requestHeaders(): array
    {
        $auth = strcasecmp($this->authHeader, 'Authorization') === 0
            ? 'Bearer ' . $this->apiKey
            : $this->apiKey;

        return array_merge(
            ['Content-Type' => 'application/json', $this->authHeader => $auth],
            $this->headers,
        );
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
