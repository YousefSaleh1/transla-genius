<?php

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
    | Translation API Configuration
    |--------------------------------------------------------------------------
    |
    | API credentials and endpoints for connecting to the external
    | translation service (such as OpenAI, OpenRouter, etc).
    |
    */
    'api' => [
        'key' => env('TRANSLATION_API_KEY'), // No fallback default! Force developer to set it
        'url' => env('TRANSLATION_API_URL', 'https://openrouter.ai/api/v1/chat/completions'),
        'model' => env('TRANSLATION_MODEL', 'openai/gpt-4o'),
        'timeout' => env('TRANSLATION_API_TIMEOUT', 10), // default 10 seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Translation Behavior Settings
    |--------------------------------------------------------------------------
    |
    | Control how the translation model behaves such as creativity (temperature)
    | and the maximum allowed output length (tokens).
    |
    */
    'settings' => [
        'temperature' => env('TRANSLATION_TEMPERATURE', 0.3),
        'max_tokens' => env('TRANSLATION_MAX_TOKENS', 200),
    ],
];
