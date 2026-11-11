<?php

use CodingPartners\TranslaGenius\Providers\FallbackProvider;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Supported Languages
    |--------------------------------------------------------------------------
    |
    | Languages supported for automatic translation.
    | The first language listed will be considered the default.
    |
    */
    'supported_languages' => ['en', 'ar'],

    /*
    |--------------------------------------------------------------------------
    | Default Translation Provider
    |--------------------------------------------------------------------------
    |
    | Which entry in `providers` below performs translations. Every shipped
    | entry reads its own environment variables, so switching is a `.env`
    | change only, e.g.:
    |
    |     TRANSLATION_PROVIDER=gemini
    |     GEMINI_API_KEY=...
    |
    */
    'default' => env('TRANSLATION_PROVIDER', 'openrouter'),

    /*
    |--------------------------------------------------------------------------
    | Translation Providers
    |--------------------------------------------------------------------------
    |
    | Each entry is a `driver` (a class implementing
    | CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface,
    | constructed with this array) plus whatever that driver needs.
    |
    | The presets below all use OpenAiCompatibleProvider and differ only in
    | their defaults. Settings every OpenAI-compatible entry understands:
    |
    |   key, url, model          Credentials, full chat-completions URL, model ID.
    |   timeout                  HTTP timeout in seconds.
    |   temperature, max_tokens  Sampling temperature and output token limit.
    |                            max_tokens defaults to unset (no limit sent, so
    |                            the provider's own default applies); a truncated
    |                            response fails instead of being saved.
    |   token_limit_param        Body field for max_tokens: 'max_tokens' (default)
    |                            or 'max_completion_tokens'.
    |   auth_header              'Authorization' (default, sends "Bearer <key>"),
    |                            or another header name to send the raw key in.
    |   headers                  Extra request headers; null/empty values are dropped.
    |   cost_per_1k_*_tokens     Rates for usage tracking; null means "unknown".
    |
    | Timeout, temperature and max_tokens are shared across presets through
    | TRANSLATION_API_TIMEOUT, TRANSLATION_TEMPERATURE and TRANSLATION_MAX_TOKENS.
    |
    | For an API that doesn't speak the OpenAI chat-completions format
    | (DeepL, AWS Translate, Azure Translator), implement the interface and
    | add an entry with your class as `driver` - see the README's "Custom
    | Translation Providers" section.
    |
    */
    'providers' => [

        // https://openrouter.ai - the default. The legacy TRANSLATION_API_*
        // and TRANSLATION_MODEL variables still apply here so existing setups
        // keep working; the OPENROUTER_* names take precedence when set.
        'openrouter' => [
            'driver' => OpenAiCompatibleProvider::class,
            'key' => env('OPENROUTER_API_KEY', env('TRANSLATION_API_KEY')),
            'url' => env('OPENROUTER_API_URL', env('TRANSLATION_API_URL', 'https://openrouter.ai/api/v1/chat/completions')),
            'model' => env('OPENROUTER_MODEL', env('TRANSLATION_MODEL', 'openai/gpt-4o')),
            'timeout' => env('TRANSLATION_API_TIMEOUT', 10),
            'temperature' => env('TRANSLATION_TEMPERATURE', 0.3),
            'max_tokens' => env('TRANSLATION_MAX_TOKENS'),
            // Optional attribution headers documented by OpenRouter.
            'headers' => [
                'HTTP-Referer' => env('OPENROUTER_SITE_URL'),
                'X-OpenRouter-Title' => env('OPENROUTER_APP_NAME'),
            ],
            'cost_per_1k_prompt_tokens' => env('OPENROUTER_COST_PER_1K_PROMPT_TOKENS'),
            'cost_per_1k_completion_tokens' => env('OPENROUTER_COST_PER_1K_COMPLETION_TOKENS'),
        ],

        // https://platform.openai.com
        'openai' => [
            'driver' => OpenAiCompatibleProvider::class,
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_API_URL', 'https://api.openai.com/v1/chat/completions'),
            'model' => env('OPENAI_MODEL', 'gpt-6-luna'),
            'timeout' => env('TRANSLATION_API_TIMEOUT', 10),
            'temperature' => env('TRANSLATION_TEMPERATURE', 0.3),
            'max_tokens' => env('TRANSLATION_MAX_TOKENS'),
            // OpenAI deprecates max_tokens in favor of max_completion_tokens.
            'token_limit_param' => 'max_completion_tokens',
            'cost_per_1k_prompt_tokens' => env('OPENAI_COST_PER_1K_PROMPT_TOKENS'),
            'cost_per_1k_completion_tokens' => env('OPENAI_COST_PER_1K_COMPLETION_TOKENS'),
        ],

        // Google Gemini through its OpenAI-compatible endpoint.
        // https://ai.google.dev/gemini-api/docs/openai
        'gemini' => [
            'driver' => OpenAiCompatibleProvider::class,
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'),
            'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
            'timeout' => env('TRANSLATION_API_TIMEOUT', 10),
            'temperature' => env('TRANSLATION_TEMPERATURE', 0.3),
            'max_tokens' => env('TRANSLATION_MAX_TOKENS'),
            'cost_per_1k_prompt_tokens' => env('GEMINI_COST_PER_1K_PROMPT_TOKENS'),
            'cost_per_1k_completion_tokens' => env('GEMINI_COST_PER_1K_COMPLETION_TOKENS'),
        ],

        // https://api-docs.deepseek.com
        'deepseek' => [
            'driver' => OpenAiCompatibleProvider::class,
            'key' => env('DEEPSEEK_API_KEY'),
            'url' => env('DEEPSEEK_API_URL', 'https://api.deepseek.com/chat/completions'),
            'model' => env('DEEPSEEK_MODEL', 'deepseek-flash'),
            'timeout' => env('TRANSLATION_API_TIMEOUT', 10),
            'temperature' => env('TRANSLATION_TEMPERATURE', 0.3),
            'max_tokens' => env('TRANSLATION_MAX_TOKENS'),
            'cost_per_1k_prompt_tokens' => env('DEEPSEEK_COST_PER_1K_PROMPT_TOKENS'),
            'cost_per_1k_completion_tokens' => env('DEEPSEEK_COST_PER_1K_COMPLETION_TOKENS'),
        ],

        // https://docs.mistral.ai. "-latest" aliases can change model and
        // pricing silently; pin a dated model ID if you need stability.
        'mistral' => [
            'driver' => OpenAiCompatibleProvider::class,
            'key' => env('MISTRAL_API_KEY'),
            'url' => env('MISTRAL_API_URL', 'https://api.mistral.ai/v1/chat/completions'),
            'model' => env('MISTRAL_MODEL', 'mistral-small-latest'),
            'timeout' => env('TRANSLATION_API_TIMEOUT', 10),
            'temperature' => env('TRANSLATION_TEMPERATURE', 0.3),
            'max_tokens' => env('TRANSLATION_MAX_TOKENS'),
            'cost_per_1k_prompt_tokens' => env('MISTRAL_COST_PER_1K_PROMPT_TOKENS'),
            'cost_per_1k_completion_tokens' => env('MISTRAL_COST_PER_1K_COMPLETION_TOKENS'),
        ],

        // Any other OpenAI-compatible endpoint: a self-hosted gateway
        // (Ollama, vLLM, LiteLLM) or a service without a preset. No URL
        // default - set TRANSLATION_API_URL to the full chat-completions URL.
        'custom' => [
            'driver' => OpenAiCompatibleProvider::class,
            'key' => env('TRANSLATION_API_KEY'),
            'url' => env('TRANSLATION_API_URL'),
            'model' => env('TRANSLATION_MODEL'),
            'timeout' => env('TRANSLATION_API_TIMEOUT', 10),
            'temperature' => env('TRANSLATION_TEMPERATURE', 0.3),
            'max_tokens' => env('TRANSLATION_MAX_TOKENS'),
            'token_limit_param' => env('TRANSLATION_TOKEN_LIMIT_PARAM', 'max_tokens'),
            'auth_header' => env('TRANSLATION_AUTH_HEADER', 'Authorization'),
            'cost_per_1k_prompt_tokens' => env('TRANSLATION_COST_PER_1K_PROMPT_TOKENS'),
            'cost_per_1k_completion_tokens' => env('TRANSLATION_COST_PER_1K_COMPLETION_TOKENS'),
        ],

        // Failover across other entries, in order: the first success wins.
        // Enable with TRANSLATION_PROVIDER=fallback and a comma-separated
        // TRANSLATION_FALLBACK_CHAIN, e.g. "openai,gemini" (at least 2).
        'fallback' => [
            'driver' => FallbackProvider::class,
            'chain' => env('TRANSLATION_FALLBACK_CHAIN'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | Two layers, split deliberately:
    |
    |   http                  Quick in-request retries for connection errors,
    |                         429 and 5xx. 4xx (other than 429) is never
    |                         retried at any layer.
    |   http_retry_after_max  A 429 whose Retry-After is at most this many
    |                         seconds is waited out in-request; a longer one is
    |                         handed to the queue as the release delay.
    |   fallback_http         HTTP retries for members of a fallback chain.
    |                         Default 0: the next provider is the retry.
    |   job_tries/job_backoff Queue-level attempts for TranslateFields, and the
    |                         seconds to wait between them (comma-separated).
    |
    | Worst case for one field/locale: (http + 1) x job_tries requests -
    | 6 with the defaults. A 401/400/404 is 1 request.
    |
    */
    'retries' => [
        'http' => env('TRANSLATION_HTTP_RETRIES', 1),
        'http_retry_after_max' => env('TRANSLATION_HTTP_RETRY_AFTER_MAX', 10),
        'fallback_http' => env('TRANSLATION_FALLBACK_HTTP_RETRIES', 0),
        'job_tries' => env('TRANSLATION_JOB_TRIES', 3),
        'job_backoff' => env('TRANSLATION_JOB_BACKOFF', '10,30'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage & Cost Tracking
    |--------------------------------------------------------------------------
    |
    | Opt-in: disabled by default, so a consuming app that doesn't want an
    | extra database table doesn't get one silently. When enabled, one row
    | is written to `translation_usage_logs` (see the publishable migration -
    | `php artisan vendor:publish --tag=translagenius-migrations`) per
    | successfully completed translation - see Events\TranslationCompleted
    | and Listeners\LogTranslationUsage.
    |
    | Cost rates live on each provider's own entry above
    | (`cost_per_1k_prompt_tokens` / `cost_per_1k_completion_tokens`). Both
    | are nullable and default to null - a provider with no rate configured
    | gets a null estimated_cost on its log rows, not a misleading 0.
    |
    */
    'usage_tracking' => [
        'enabled' => env('TRANSLATION_USAGE_TRACKING_ENABLED', false),
    ],
];
