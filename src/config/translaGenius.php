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
    |                            Unset temperature is not sent (model default);
    |                            only openrouter defaults it, to 0.3.
    |                            max_tokens defaults to unset (no limit sent, so
    |                            the provider's own default applies); a truncated
    |                            response fails instead of being saved.
    |   token_limit_param        Body field for max_tokens: 'max_tokens' (default)
    |                            or 'max_completion_tokens'.
    |   auth_header              'Authorization' (default, sends "Bearer <key>"),
    |                            or another header name to send the raw key in.
    |   headers                  Extra request headers; null/empty values are dropped.
    |   reasoning_effort         Sent only when set (e.g. 'none', 'low'); empty = omit.
    |   batch                    Override TRANSLATION_BATCH for this entry (true/false);
    |                            unset = the package-wide setting.
    |   batch_prompt             Batch prompt for this entry; unset = package-wide.
    |   batch_response_format    'json_schema', 'json_object' or unset (prompt only):
    |                            how a batch reply is constrained to JSON. Shipped
    |                            only where the provider documents support.
    |   body                     Extra request-body fields (config only). Can't set
    |                            model, messages or stream; configured temperature,
    |                            token limit and reasoning_effort take precedence.
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
            'reasoning_effort' => env('OPENROUTER_REASONING_EFFORT'),
            'batch' => env('OPENROUTER_BATCH'),
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
            'temperature' => env('TRANSLATION_TEMPERATURE'),
            'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'none'),
            'batch' => env('OPENAI_BATCH'),
            'batch_response_format' => 'json_schema',
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
            'temperature' => env('TRANSLATION_TEMPERATURE'),
            'reasoning_effort' => env('GEMINI_REASONING_EFFORT'),
            'batch' => env('GEMINI_BATCH'),
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
            'temperature' => env('TRANSLATION_TEMPERATURE'),
            'reasoning_effort' => env('DEEPSEEK_REASONING_EFFORT', 'none'),
            'batch' => env('DEEPSEEK_BATCH'),
            'batch_response_format' => 'json_object',
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
            'temperature' => env('TRANSLATION_TEMPERATURE'),
            'reasoning_effort' => env('MISTRAL_REASONING_EFFORT'),
            'batch' => env('MISTRAL_BATCH'),
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
            'temperature' => env('TRANSLATION_TEMPERATURE'),
            'reasoning_effort' => env('TRANSLATION_REASONING_EFFORT'),
            'batch' => env('TRANSLATION_CUSTOM_BATCH'),
            'batch_response_format' => env('TRANSLATION_BATCH_RESPONSE_FORMAT'),
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
    | System Prompt
    |--------------------------------------------------------------------------
    |
    | Instructions sent as the `system` message for OpenAI-compatible providers.
    | Null/empty uses the built-in prompt. Any entry under `providers` can set
    | its own `prompt`, which wins over this one.
    |
    | Placeholders: {source_locale}, {target_locale}, {field} (the attribute,
    | e.g. "name") and {model} (the model's class basename, e.g. "Product").
    | Anything else in braces is left as written. The text being translated is
    | never substituted into the prompt: it is always sent separately, as the
    | `user` message, so a prompt can't move content into the system role.
    |
    | Short prompts can come from TRANSLATION_SYSTEM_PROMPT; put longer ones
    | here in the published config.
    |
    */
    'prompt' => env('TRANSLATION_SYSTEM_PROMPT'),

    /*
    |--------------------------------------------------------------------------
    | Batching
    |--------------------------------------------------------------------------
    |
    | When enabled, all missing target locales of one field are translated in
    | a single request that asks for a JSON object keyed by locale code,
    | instead of one request per locale. Fewer requests and less repeated
    | prompt overhead, against bigger replies and depending on the model
    | following the JSON format. Locales a reply misses or garbles are
    | translated one at a time; a reply that isn't JSON at all sends the whole
    | field one at a time. Off by default for now (opt in: TRANSLATION_BATCH).
    |
    | The batch prompt follows the same rules as `prompt`, with
    | {target_locales} (comma-separated) instead of {target_locale}.
    |
    */
    'batch' => [
        'enabled' => env('TRANSLATION_BATCH', false),
        'prompt' => env('TRANSLATION_BATCH_PROMPT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Translation Cache
    |--------------------------------------------------------------------------
    |
    | When enabled, identical source text translated with an identical setup
    | is requested once and then served from Laravel's cache, across every
    | record and model. Wraps whichever provider is configured, including a
    | fallback chain - nothing else to set up. Off by default.
    |
    | The key covers the text, both locales, the provider and everything in
    | its config that can change the output (model, URL, prompt templates,
    | temperature, reasoning_effort, body, response format). Failures,
    | truncated replies and invalid batch values are never cached, and in a
    | fallback chain only results from the primary are.
    |
    |   store  Cache store name; empty = the app's default store.
    |   ttl    Seconds to keep an entry. Default 2592000 (30 days); 0 = forever.
    |
    | Cached values are translated field content, stored in that cache store.
    |
    */
    'cache' => [
        'enabled' => env('TRANSLATION_CACHE', false),
        'store' => env('TRANSLATION_CACHE_STORE'),
        'ttl' => env('TRANSLATION_CACHE_TTL', 2592000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Where and when translation jobs are dispatched, for every dispatch point
    | (model create/update and `translagenius:backfill`).
    |
    |   connection    Queue connection; empty = the app's default.
    |   queue         Queue name; empty = the connection's default.
    |   after_commit  Dispatch only after the surrounding DB transaction
    |                 commits (nothing at all on rollback). Default true. When
    |                 false, the connection's own `after_commit` setting applies.
    |
    */
    'queue' => [
        'connection' => env('TRANSLATION_QUEUE_CONNECTION'),
        'queue' => env('TRANSLATION_QUEUE'),
        'after_commit' => env('TRANSLATION_DISPATCH_AFTER_COMMIT', true),
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
