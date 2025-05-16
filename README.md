# TranslaGenius Package

The TranslaGenius package is a Laravel package designed to automatically translate fields in your Eloquent models using an external translation API. It supports multiple languages and provides flexible translation management.

## Features

- Multi-language support (configurable)
- Automatic field translation
- Queue-based translation processing
- Flexible API integration
- Comprehensive scope filters

## Installation

To install the package, you can use Composer:

```bash
composer require coding-partners/transla-genius
```

## Configuration

After installing the package, you need to publish the configuration file:

```bash
php artisan vendor:publish --provider="CodingPartners\TranslaGenius\TranslaGeniusServiceProvider" --tag="translagenius-config"
```

This will create a `translaGenius.php` file in your `config` directory. You can modify this file to set your Supported Languages, and other translation settings:

```php

return [
    /*
    |--------------------------------------------------------------------------
    | Supported Languages
    |--------------------------------------------------------------------------
    | List of supported language codes (ISO 639-1).
    | The first language will be considered as default.
    */
    'supported_languages' => ['en', 'ar', 'es', 'fr'],

    // ... other settings
];
```


### Environment Variables

Make sure to set the following environment variables in your `.env` file:

```env
TRANSLATION_API_KEY=your_openai_api_key
TRANSLATION_API_URL=https://openrouter.ai/api/v1/chat/completions
TRANSLATION_MODEL=openai/gpt-4o
TRANSLATION_TEMPERATURE=0.3
TRANSLATION_MAX_TOKENS=200
```

## Usage

### Step 1: Include the `Translatable` Trait in Your Model

To enable automatic translation for your model, include the `Translatable` trait in your Eloquent model:

```php
use CodingPartners\TranslaGenius\Traits\Translatable;

class Post extends Model
{
    use Translatable;

    // Define the fields that should be translated
    public $translatable = [
        'title',
        'content',
    ];
}
```

### Step 2: Update Your Migration

Ensure that the fields you want to translate are of type `json` in your migration:

```php
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->json('title');
    $table->json('content');
    $table->timestamps();
});
```

### Step 3: Middleware Setup

To automatically set the locale based on the request, you can include the `SetLocale` middleware in your `bootstrap/app.php`:

```php
use CodingPartners\TranslaGenius\Middleware\SetLocale;

->withMiddleware(function (Middleware $middleware) {

    $middleware->api(prepend: [

        SetLocale::class,

    ]);

})
```

### Step 4: Making Requests

When making requests to your application, you can set the `Accept-Language` header to specify the desired language:

```bash
curl -X GET http://yourapp.com/api/posts -H "Accept-Language: en"
```

This will set the locale to English (`en`), which means the default language of the system is now English. When you add a new record, you should send the fields in English, and the package will automatically translate them into Arabic.

### Step 5: Running the Queue

The translation process is handled by a job that is dispatched when a model is created or updated. To ensure that the translations are processed, you need to run the queue worker:

```bash
php artisan queue:work
```

This will process the `TranslateFields` job and perform the translations in the background.

### Example of Using the `fullyTranslated` Scope

The `Translatable` trait provides a `fullyTranslated` scope that allows you to filter models that are fully translated in the Supported Languages.

```php
$posts = Post::fullyTranslated()->get();
```

This scope checks if all translatable fields have a translation in the Supported Languages. It ensures that only records with complete translations are returned, which is useful for ensuring data consistency and completeness.

## How It Works

1. **Model Events**: When a model is created or updated, the `Translatable` trait automatically dispatches a `TranslateFields` job.
2. **Translation Job**: The `TranslateFields` job uses the `AutoTranslationService` to translate the specified fields into the target language.
3. **Translation Service**: The `AutoTranslationService` communicates with the external API to perform the translation and updates the model with the translated content.

## Example Workflow

Here’s a complete example of how to use the package:

### Model

```php
use CodingPartners\TranslaGenius\Traits\Translatable;

class Product extends Model
{
    use Translatable;

    protected $fillable = [
        'price',
        'name',
        'description'
    ];

    public $translatable = [
        'name',
        'description'
    ];
}
```

### Migration

```php
Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->decimal('price');
    $table->json('name');
    $table->json('description');
    $table->timestamps();
});
```

### Controller

```php
public function store(Request $request)
{
    $product = Product::create([
        'price' => $request->price,
        'name' => $request->name,
        'description' => $request->description
    ]);

    return response()->json([
        "data" => $product,
        "message" => "Done!!"
    ], 201);
}
```

### Request

```bash
curl -X POST http://yourapp.com/api/posts -H "Accept-Language: en" -d '{"name": "name in English", "description": "description in English", "price": 100}'
```

After the request, the `name` and `description` fields will be automatically translated into Supported Languages.


## Troubleshooting

### Common Issues

#### Missing translations:

- Verify queue worker is running
- Check API credentials
- Confirm language codes match config

#### Performance:

- Add indexes to JSON columns
- Consider caching frequent translations



## Conclusion

The TranslaGenius package simplifies the process of adding multi-language support to your Laravel application. By following the steps outlined above, you can easily configure and use the package to automatically translate your model fields, ensuring that your application is accessible to a global audience.
