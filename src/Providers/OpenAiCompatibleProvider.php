<?php

namespace CodingPartners\TranslaGenius\Providers;

use CodingPartners\TranslaGenius\Contracts\BatchTranslationProviderInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationBatchRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationBatchResult;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;
use CodingPartners\TranslaGenius\Events\TranslationRequestBilled;
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
 * translagenius.providers.openrouter in config/translagenius.php) rather
 * than reading the global config() facade directly - makes this class
 * constructible and testable with a plain array, no app config needed.
 */
class OpenAiCompatibleProvider implements BatchTranslationProviderInterface
{
    /**
     * The built-in system prompt, used when no `prompt` is configured.
     * Placeholders are filled by renderPrompt().
     */
    public const DEFAULT_PROMPT = "You are a translation engine. Translate the user's message from {source_locale} to {target_locale}. "
        .'Respond with only the translated text - no explanation, commentary, or additional formatting.';

    /**
     * Request-body fields `body` can never set: the provider always owns the
     * model and the messages (the system/user split is a safety boundary),
     * and a streamed reply couldn't be parsed. The API key is a header, so
     * `body` can't reach it at all.
     */
    public const PROTECTED_BODY_FIELDS = ['model', 'messages', 'stream'];

    /**
     * The built-in system prompt for batch requests (one text, several target
     * locales, answered as a JSON object keyed by locale). Placeholders:
     * {source_locale}, {target_locales} (comma-separated), {field}, {model}.
     * Mentions "json" explicitly, which some JSON modes require.
     */
    public const DEFAULT_BATCH_PROMPT = "You are a translation engine. Translate the user's message from {source_locale} into each of these languages: {target_locales}. "
        .'Respond with a single json object and nothing else: one key per language code listed above, each holding the translated text as a string. '
        .'No explanation, commentary, code fences, or additional formatting. '
        .'For example, for "de, fr": {"de": "...", "fr": "..."}';

    /** @var bool Whether translateBatch() is used (config `batch`). */
    protected bool $batch;

    /** @var string|null Configured batch prompt template, or null for DEFAULT_BATCH_PROMPT. */
    protected ?string $batchPrompt;

    /**
     * @var string|null response_format for batch requests: "json_schema"
     *                  (Structured Outputs), "json_object" (JSON mode), or null to rely on
     *                  the prompt alone. Only set where the provider documents support.
     */
    protected ?string $batchResponseFormat;

    /**
     * @var string|null Configured system prompt template, or null for
     *                  DEFAULT_PROMPT. Only ever sent as the `system` message.
     */
    protected ?string $prompt;

    /**
     * @var string|null `reasoning_effort` to send (e.g. "none", "low"), or
     *                  null to omit the field and use the model's default.
     */
    protected ?string $reasoningEffort;

    /**
     * @var array<string, mixed> Extra provider-specific request-body fields,
     *                           minus PROTECTED_BODY_FIELDS.
     */
    protected array $extraBody;

    /** @var string API key sent as a Bearer token; blank fails fast in translate(). */
    protected string $apiKey;

    /** @var string Full chat-completions endpoint URL to POST to. */
    protected string $apiUrl;

    /** @var string Model identifier passed through to the API (e.g. "openai/gpt-4o"). */
    protected string $model;

    /**
     * @var float|null Sampling temperature, or null to omit the field and use
     *                 the model's default (some reasoning models ignore or reject it).
     */
    protected ?float $temperature;

    /**
     * @var int|null Output token limit, or null (the default) to send no
     *               limit and use the provider's own default. A finish_reason of
     *               "length" is treated as a failure either way - see translate().
     */
    protected ?int $maxTokens;

    /** @var int HTTP request timeout, in seconds. */
    protected int $timeout;

    /**
     * @var string|null The translagenius.providers key this instance was
     *                  resolved under (e.g. "gemini"), reported on every TranslationResult.
     */
    protected ?string $name;

    /**
     * @var string Header the API key is sent in. "Authorization" (the
     *             default) sends "Bearer <key>"; any other header (e.g. Azure
     *             OpenAI's "api-key") sends the raw key.
     */
    protected string $authHeader;

    /** @var array<string, string> Extra headers merged into every request. */
    protected array $headers;

    /**
     * @var string Request-body field is sent as: "max_tokens"
     *             (default) or "max_completion_tokens", which OpenAI's own API now
     *             prefers and its reasoning models require.
     */
    protected string $tokenLimitParam;

    /**
     * @var int Extra HTTP attempts after the first, for connection errors,
     *          429 and 5xx only. 4xx (other than 429) is never retried.
     */
    protected int $httpRetries;

    /**
     * @var int Longest Retry-After (seconds) worth sleeping through inside
     *          the request. A longer one is handed to the queue instead (see
     *          TranslationFailedException::getRetryAfter()).
     */
    protected int $retryAfterMax;

    /**
     * @param  array<string, mixed>  $config
     *                                        Provider configuration array - one translagenius.providers.* entry
     *                                        from config/translagenius.php. `name` is injected by the service
     *                                        provider (the entry's config key). Every key is optional; missing
     *                                        key/url are caught explicitly in translate() rather than left
     *                                        to fail as an opaque HTTP error.
     */
    public function __construct(array $config)
    {
        $this->apiKey = (string) ($config['key'] ?? '');
        $this->apiUrl = (string) ($config['url'] ?? '');
        $this->model = (string) ($config['model'] ?? '');
        $this->temperature = ($config['temperature'] ?? '') === '' ? null : (float) $config['temperature'];
        $this->maxTokens = ($config['max_tokens'] ?? '') === '' ? null : max(1, (int) $config['max_tokens']);
        $this->timeout = (int) ($config['timeout'] ?? 10);
        $this->name = isset($config['name']) ? (string) $config['name'] : null;
        $this->prompt = trim((string) ($config['prompt'] ?? '')) === '' ? null : (string) $config['prompt'];
        $this->batch = (bool) (filter_var($config['batch'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false);
        $this->batchPrompt = trim((string) ($config['batch_prompt'] ?? '')) === '' ? null : (string) $config['batch_prompt'];
        $format = strtolower(trim((string) ($config['batch_response_format'] ?? '')));
        $this->batchResponseFormat = in_array($format, ['json_schema', 'json_object'], true) ? $format : null;
        $this->reasoningEffort = trim((string) ($config['reasoning_effort'] ?? '')) === '' ? null : trim((string) $config['reasoning_effort']);
        $this->extraBody = (array) ($config['body'] ?? []);

        $blocked = array_intersect(array_keys($this->extraBody), self::PROTECTED_BODY_FIELDS);
        if ($blocked !== []) {
            Log::warning('OpenAiCompatibleProvider: ignoring protected fields in `body` config', [
                'provider' => $this->name,
                'fields' => array_values($blocked),
            ]);

            $this->extraBody = array_diff_key($this->extraBody, array_flip(self::PROTECTED_BODY_FIELDS));
        }
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
     * @param  TranslationRequest  $request  The text and locale pair to translate.
     *
     * @throws TranslationFailedException If the API key/URL are missing, the
     *                                    connection fails, the API returns a failed response, or the
     *                                    response contains no translated content. `retryable` is set
     *                                    per failure mode - see isRetryableStatus().
     */
    public function translate(TranslationRequest $request): TranslationResult
    {
        $this->assertConfigured();

        $requestId = (string) Str::uuid();
        $billing = [$requestId, $request->sourceLocale, [$request->targetLocale], $request->field];
        $response = $this->send($this->requestBody($this->renderPrompt($request), $request->text), $billing);

        if ($response->json('choices.0.finish_reason') === 'length') {
            $this->reportUnusedUsage($response, TranslationRequestBilled::TRUNCATED, ...$billing);
        }
        $this->assertNotTruncated($response);

        $content = $response->json('choices.0.message.content');

        if (empty($content)) {
            $this->reportUnusedUsage($response, TranslationRequestBilled::UNUSABLE, ...$billing);

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
            requestId: $requestId,
        );
    }

    /**
     * Whether batching is enabled for this entry (`batch`, or the package-wide
     * TRANSLATION_BATCH).
     */
    public function supportsBatching(): bool
    {
        return $this->batch;
    }

    /**
     * Translate one text into every requested locale with a single request,
     * asking for a JSON object keyed by locale code.
     *
     * Only requested locales whose value is a non-empty string are returned;
     * extra keys are ignored. A reply that isn't a JSON object (one
     * surrounding code fence is tolerated) returns no translations, so the
     * caller translates each locale on its own. A reply cut off at the token
     * limit returns no translations either, flagged `truncated`. HTTP failures
     * throw exactly as in translate().
     *
     *
     * @throws TranslationFailedException
     */
    public function translateBatch(TranslationBatchRequest $request): TranslationBatchResult
    {
        $this->assertConfigured();

        $requestId = (string) Str::uuid();
        $body = $this->requestBody($this->renderBatchPrompt($request), $request->text);

        if ($this->batchResponseFormat !== null) {
            $body['response_format'] = $this->responseFormat($request->targetLocales);
        }

        $billing = [$requestId, $request->sourceLocale, $request->targetLocales, $request->field];
        $response = $this->send($body, $billing);

        // Unlike a single translation, a truncated batch isn't a failure: the
        // same locales requested one at a time produce much smaller replies.
        // Its billed usage is logged here (TranslationRequestBilled), so the
        // result carries no token counts.
        if ($response->json('choices.0.finish_reason') === 'length') {
            Log::warning('OpenAiCompatibleProvider: batch reply truncated at the output token limit; locales will be requested one at a time', [
                'requested' => count($request->targetLocales),
                'max_tokens' => $this->maxTokens,
            ]);

            $this->reportUnusedUsage($response, TranslationRequestBilled::TRUNCATED, ...$billing);

            return new TranslationBatchResult(
                translations: [],
                provider: $this->name,
                requestId: $requestId,
                truncated: true,
            );
        }

        $decoded = $this->decodeJsonObject((string) $response->json('choices.0.message.content'));
        $translations = [];

        foreach ($request->targetLocales as $locale) {
            $value = $decoded[$locale] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $translations[$locale] = $value;
            }
        }

        if (count($translations) < count($request->targetLocales)) {
            Log::warning('OpenAiCompatibleProvider: batch reply incomplete; remaining locales will be requested one at a time', [
                'requested' => count($request->targetLocales),
                'valid' => count($translations),
                'parsed' => $decoded !== null,
            ]);
        }

        // Nothing usable: log the billed usage as such; the result then
        // carries no tokens, so nothing downstream counts them again.
        if ($translations === []) {
            $this->reportUnusedUsage($response, TranslationRequestBilled::UNUSABLE, ...$billing);

            return new TranslationBatchResult(translations: [], provider: $this->name, requestId: $requestId);
        }

        return new TranslationBatchResult(
            translations: $translations,
            provider: $this->name,
            promptTokens: $response->json('usage.prompt_tokens'),
            completionTokens: $response->json('usage.completion_tokens'),
            requestId: $requestId,
        );
    }

    /**
     * Report a billed response whose output won't become a saved translation,
     * so usage tracking logs it (see TranslationRequestBilled). Responses
     * without a `usage` object weren't billed in a way we can see, and are
     * not reported.
     *
     * @param  array<int, string>  $targetLocales
     */
    protected function reportUnusedUsage(
        Response $response,
        string $outcome,
        string $requestId,
        string $sourceLocale,
        array $targetLocales,
        ?string $field
    ): void {
        $prompt = $response->json('usage.prompt_tokens');
        $completion = $response->json('usage.completion_tokens');

        if ($prompt === null && $completion === null) {
            return;
        }

        TranslationRequestBilled::dispatch(
            $outcome,
            $this->name,
            $requestId,
            is_numeric($prompt) ? (int) $prompt : null,
            is_numeric($completion) ? (int) $completion : null,
            $sourceLocale,
            $targetLocales,
            $field,
        );
    }

    /**
     * @throws TranslationFailedException If the key or URL is missing.
     */
    protected function assertConfigured(): void
    {
        if (empty($this->apiKey) || empty($this->apiUrl)) {
            Log::error('OpenAiCompatibleProvider: missing API key or URL configuration.');

            throw new TranslationFailedException(
                'Translation provider configuration missing: API key or API URL.',
                retryable: false
            );
        }
    }

    /**
     * POST $body, retrying connection errors, 429 and 5xx up to $httpRetries
     * times (a 429's Retry-After is honored if it's at most $retryAfterMax
     * seconds). Returns the first successful response.
     *
     * @param  array<string, mixed>  $body
     * @param  array{0: string, 1: string, 2: array<int, string>, 3: string|null}  $billing
     *                                                                                       requestId, source locale, target locales, field - for reporting
     *                                                                                       an error response that was still billed.
     *
     * @throws TranslationFailedException On a connection failure or an
     *                                    unsuccessful response once retries are used up.
     */
    protected function send(array $body, array $billing): Response
    {
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
                    'Unable to connect to translation API: '.$e->getMessage(),
                    retryable: true,
                    previous: $e
                );
            }

            if (! $response->failed()) {
                return $response;
            }

            // An error response that still reports usage was billed.
            $this->reportUnusedUsage($response, TranslationRequestBilled::FAILED, ...$billing);

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
    }

    /**
     * A response that stopped at the output token limit is a failure, never a
     * translation - see TranslationFailedException's message for the fix.
     *
     *
     * @throws TranslationFailedException If finish_reason is "length".
     */
    protected function assertNotTruncated(Response $response): void
    {
        // Checked before reading content: a model that spends its whole
        // budget (reasoning tokens count too) returns little or no content
        // with "length", and that is the actionable cause. Retrying with the
        // same limit gives the same result, so this is not retryable - and as
        // a TranslationFailedException a fallback chain still moves on.
        if ($response->json('choices.0.finish_reason') === 'length') {
            Log::warning('OpenAiCompatibleProvider: translation truncated at the output token limit', [
                'max_tokens' => $this->maxTokens,
            ]);

            throw new TranslationFailedException(
                'Translation was truncated: the provider stopped at its output token limit (finish_reason "length"). '
                .'Raise TRANSLATION_MAX_TOKENS, or leave it unset to use the provider\'s own limit.',
                statusCode: $response->status(),
                retryable: false
            );
        }
    }

    /**
     * Fill the system prompt template for this request.
     *
     * Only these placeholders are substituted: {source_locale},
     * {target_locale}, {field} (the attribute name) and {model} (the model's
     * class basename) - the last two become '' when the caller gave no
     * context. Anything else in braces is left exactly as written. The text
     * being translated is never substituted: it is only ever sent as the
     * `user` message (see requestBody()), so no prompt can pull field content
     * into the system role.
     */
    protected function renderPrompt(TranslationRequest $request): string
    {
        return strtr($this->prompt ?? self::DEFAULT_PROMPT, [
            '{source_locale}' => $request->sourceLocale,
            '{target_locale}' => $request->targetLocale,
            '{field}' => (string) $request->field,
            '{model}' => (string) $request->model,
        ]);
    }

    /**
     * Fill the batch prompt template. Same rules as renderPrompt(), with
     * {target_locales} (comma-separated) in place of {target_locale}.
     */
    protected function renderBatchPrompt(TranslationBatchRequest $request): string
    {
        return strtr($this->batchPrompt ?? self::DEFAULT_BATCH_PROMPT, [
            '{source_locale}' => $request->sourceLocale,
            '{target_locales}' => implode(', ', $request->targetLocales),
            '{field}' => (string) $request->field,
            '{model}' => (string) $request->model,
        ]);
    }

    /**
     * response_format for a batch request: a strict JSON schema requiring
     * exactly the requested locales as strings, or plain JSON mode.
     *
     * @param  array<int, string>  $locales
     * @return array<string, mixed>
     */
    protected function responseFormat(array $locales): array
    {
        if ($this->batchResponseFormat === 'json_object') {
            return ['type' => 'json_object'];
        }

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'translations',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => array_fill_keys($locales, ['type' => 'string']),
                    'required' => array_values($locales),
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Parse a reply that should be one JSON object. Tolerates exactly one
     * surrounding Markdown code fence; anything else that isn't a JSON object
     * returns null.
     *
     * @return array<string, mixed>|null
     */
    protected function decodeJsonObject(string $content): ?array
    {
        $content = trim($content);

        if (preg_match('/^```(?:json)?\s*\n(.*)\n```$/si', $content, $m) === 1) {
            $content = trim($m[1]);
        }

        if ($content === '' || $content[0] !== '{') {
            return null;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    /**
     * The chat-completions request body. Instructions only ever go in the
     * `system` message and the text being translated only in the `user` one.
     *
     * @return array<string, mixed>
     */
    protected function requestBody(string $systemPrompt, string $text): array
    {
        // Extra `body` fields first, so everything below takes precedence;
        // protected fields were already stripped in the constructor.
        $body = array_merge($this->extraBody, [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $text],
            ],
        ]);

        if ($this->reasoningEffort !== null) {
            $body['reasoning_effort'] = $this->reasoningEffort;
        }

        if ($this->temperature !== null) {
            $body['temperature'] = $this->temperature;
        }

        if ($this->maxTokens !== null) {
            $body[$this->tokenLimitParam] = $this->maxTokens;
        }

        return $body;
    }

    /**
     * Short jittered backoff between HTTP attempts: 200ms, 400ms, ... capped
     * at 1s. Kept brief on purpose - anything slower is the queue's job.
     *
     * @param  int  $attempt  The attempt that just failed (1-based).
     */
    protected function pause(int $attempt): void
    {
        Sleep::for(min(1000, 200 * (2 ** ($attempt - 1))) + random_int(0, 100))->milliseconds();
    }

    /**
     * Seconds from a Retry-After header, which may be delta-seconds or an
     * HTTP date. Null when absent or unparseable.
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
            ? 'Bearer '.$this->apiKey
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
     */
    protected function isRetryableStatus(int $status): bool
    {
        if ($status === 429) {
            return true;
        }

        return $status < 400 || $status >= 500;
    }
}
