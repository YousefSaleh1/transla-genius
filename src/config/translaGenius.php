<?php

use CodingPartners\TranslaGenius\Providers\OpenRouterProvider;

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
            'driver' => OpenRouterProvider::class,

            // No fallback default for the key! Force the developer to set it.
            'key' => env('TRANSLATION_API_KEY'),
            'url' => env('TRANSLATION_API_URL', 'https://openrouter.ai/api/v1/chat/completions'),
            'model' => env('TRANSLATION_MODEL', 'openai/gpt-4o'),
            'timeout' => env('TRANSLATION_API_TIMEOUT', 10), // seconds
            'temperature' => env('TRANSLATION_TEMPERATURE', 0.3),
            'max_tokens' => env('TRANSLATION_MAX_TOKENS', 200),
        ],
    ],
];
