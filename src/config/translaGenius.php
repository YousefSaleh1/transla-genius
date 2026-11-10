<?php

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
    | Which entry in `providers` below is used to actually perform
    | translations. Switch providers by changing this value (and adding a
    | corresponding entry under `providers`) - no code changes needed.
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
    | constructed with this array) plus whatever configuration that driver
    | needs. To add a custom provider, implement the interface and add an
    | entry here - see the README's "Custom Translation Providers" section.
    |
    */
    'providers' => [
        'openrouter' => [
            'driver' => OpenAiCompatibleProvider::class,

            // No fallback default for the key! Force the developer to set it.
            'key' => env('TRANSLATION_API_KEY'),
            'url' => env('TRANSLATION_API_URL', 'https://openrouter.ai/api/v1/chat/completions'),
            'model' => env('TRANSLATION_MODEL', 'openai/gpt-4o'),
            'timeout' => env('TRANSLATION_API_TIMEOUT', 10), // seconds
            'temperature' => env('TRANSLATION_TEMPERATURE', 0.3),
            'max_tokens' => env('TRANSLATION_MAX_TOKENS', 200),

            // Optional, used by usage tracking. Leave null if unknown: the
            // log row then records a null cost, never a misleading 0.
            'cost_per_1k_prompt_tokens' => env('TRANSLATION_OPENROUTER_COST_PER_1K_PROMPT_TOKENS'),
            'cost_per_1k_completion_tokens' => env('TRANSLATION_OPENROUTER_COST_PER_1K_COMPLETION_TOKENS'),
        ],
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
