# TranslaGenius Package

The TranslaGenius package is a Laravel package designed to automatically translate fields in your Eloquent models using an external translation API. It supports multiple languages and provides flexible translation management.

## Features

- Multi-language support (configurable)
- Automatic field translation
- Queue-based translation processing
- Flexible API integration
- Comprehensive scope filters
- Lifecycle events for translation start/completion/failure
- Provider fallback/chaining for resilience against a single backend's outage
- Cost-safe Artisan command to backfill translations on existing records
- Opt-in usage/cost tracking, queryable via a plain Eloquent model

## Installation

Requires PHP 8.3+ and Laravel 12.x or 13.x. These are the versions CI tests against.

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

    /*
    |--------------------------------------------------------------------------
    | Default Translation Provider
    |--------------------------------------------------------------------------
    | Which entry in `providers` below actually performs translations.
    */
    'default' => env('TRANSLATION_PROVIDER', 'openrouter'),

    /*
    |--------------------------------------------------------------------------
    | Translation Providers
    |--------------------------------------------------------------------------
    | Each entry is a `driver` class plus whatever config that driver needs.
    | See "Custom Translation Providers" below to add your own.
    */
    'providers' => [
        'openrouter' => [
            'driver' => \CodingPartners\TranslaGenius\Providers\OpenRouterProvider::class,
            'key' => env('TRANSLATION_API_KEY'),
            'url' => env('TRANSLATION_API_URL', 'https://openrouter.ai/api/v1/chat/completions'),
            'model' => env('TRANSLATION_MODEL', 'openai/gpt-4o'),
            'timeout' => env('TRANSLATION_API_TIMEOUT', 10),
            'temperature' => env('TRANSLATION_TEMPERATURE', 0.3),
            'max_tokens' => env('TRANSLATION_MAX_TOKENS', 200),
        ],
    ],
];
```

> **Upgrading from 1.x?** The config shape changed - see [CHANGELOG.md](CHANGELOG.md) for the v2.0.0 migration notes.

### Environment Variables

Make sure to set the following environment variables in your `.env` file:

```env
TRANSLATION_API_KEY=your_openai_api_key
TRANSLATION_API_URL=https://openrouter.ai/api/v1/chat/completions
TRANSLATION_MODEL=openai/gpt-4o
TRANSLATION_API_TIMEOUT=10
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

### Database Requirements

TranslaGenius targets Laravel's five first-party-supported relational databases: MariaDB 10.3+, MySQL 5.7+, PostgreSQL 10.0+, SQLite 3.26.0+, and SQL Server 2017+. The `fullyTranslated` scope and the translatable columns rely on each database's JSON path functions, which are unconditionally available on MySQL, MariaDB, PostgreSQL, and SQL Server at those floors - but **not** on SQLite:

- **SQLite's actual requirement is 3.38.0+, or an earlier 3.26.0+ build with JSON1 explicitly compiled in** - Laravel's own bare 3.26.0+ floor is not sufficient on its own for this package. JSON functions were an optional SQLite extension before 3.38.0 (2022), and several real-world PHP distributions (older Debian/Ubuntu base images, some bundled Windows builds) shipped without it, producing a cryptic `no such function: json_extract` error the first time a model saves. Run `php artisan translagenius:check` after installing to verify your connection supports what this package needs - it checks all four SQL-based drivers, but SQLite is the one where the answer genuinely isn't guaranteed by version number alone.
- **MySQL/MariaDB**: the row-level locking `TranslateFields` uses to protect against concurrent-update races requires the InnoDB storage engine. On a table using the legacy MyISAM engine, the lock is silently a no-op (MyISAM has no row-level locking at all). This is the practical default for any MySQL/MariaDB app - not something you need to configure - but worth knowing if you're running against an unusually old or custom-configured install.
- **PostgreSQL**: `$table->json()` migrations create a plain `json` column, not `jsonb` - correctness of `fullyTranslated` and translation storage is unaffected either way, but if you need indexed/containment JSON queries at scale, consider migrating your translatable columns to `jsonb` yourself (not a default this package imposes).
- **SQLite locking note**: `lockForUpdate()` compiles to nothing on SQLite (`SQLiteGrammar::compileLock()` is unconditionally empty) - the concurrent-write protection still holds there, but because SQLite serializes all writers at the database-file/WAL level regardless of query-level lock hints, not because of an explicit row lock.

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

The exact inverse, `notFullyTranslated`, is what powers the backfill command below - `Post::notFullyTranslated()->get()` returns every record missing at least one field/locale.

### Translating Arbitrary Text

To translate text outside the automatic model-save flow, use the `TranslaGenius` facade. Laravel's package discovery registers it under the global alias `TranslaGenius`, or you can import it directly:

```php
use CodingPartners\TranslaGenius\Facades\TranslaGenius;

$result = TranslaGenius::translate('Hello', 'en', 'ar');
$result->text; // the translated string
```

The facade resolves `Contracts\TranslatorInterface` from the container, so it uses whichever provider (or fallback chain) is configured. It throws `Exceptions\TranslationFailedException` on failure.

## Backfilling Existing Records

`php artisan translagenius:backfill {model}` finds existing records that aren't fully translated and dispatches the same `TranslateFields` job for them that a normal create/update would - useful after adding a new translatable field or a new supported language, or after a period where the queue worker wasn't running.

```bash
# Preview only - reports the count, dispatches nothing.
php artisan translagenius:backfill "App\Models\Post" --dry-run

# Interactive: shows the count and estimated API calls, then prompts to confirm.
php artisan translagenius:backfill "App\Models\Post"

# Non-interactive/CI use - skips the prompt.
php artisan translagenius:backfill "App\Models\Post" --force

# Tune how many records are loaded per chunk (default 100).
php artisan translagenius:backfill "App\Models\Post" --force --chunk=500
```

It always requires either `--force` or an interactive "yes" before dispatching anything - a bare run with no confirmation mechanism satisfied reports the count and stops, dispatching nothing. `{model}` must be a fully-qualified class using the `Translatable` trait; an invalid class, a non-Eloquent class, or a model missing the trait is rejected up front with a clear message rather than failing partway through. Iteration uses `chunkById()` (not `chunk()`) so it stays correct even while jobs it just dispatched are being processed concurrently.

There's no `--locales=` filter: `TranslateFields` always translates into every locale from `translaGenius.supported_languages` (already-translated field/locale pairs are skipped automatically, at no API cost), and that target set isn't parameterized per-dispatch - narrowing it correctly would mean changing `TranslateFields` itself, which this command deliberately doesn't touch.

## How It Works

1. **Model Events**: When a model is created or updated, the `Translatable` trait automatically dispatches a `TranslateFields` job.
2. **Translation Job**: The `TranslateFields` job asks `Translator` to translate each missing field/locale, then hands the results to `TranslationWriter` to persist.
3. **Translator**: `Translator` resolves whichever `TranslationProviderInterface` implementation is configured (`translaGenius.default`) and delegates to it - the job never talks to a specific provider directly.
4. **Provider**: The bound provider (`OpenRouterProvider` by default) communicates with the external API and returns a `TranslationResult`.

## Custom Translation Providers

TranslaGenius doesn't hard-code OpenRouter/GPT-4o into the translation flow - it's the default of a swappable `TranslationProviderInterface`. To use a different backend (DeepL, Google Translate, another LLM, an internal translation service), implement the interface:

```php
use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;

class DeepLProvider implements TranslationProviderInterface
{
    public function __construct(protected array $config)
    {
        // $config is whatever you put under providers.deepl below.
    }

    public function translate(TranslationRequest $request): TranslationResult
    {
        // Call DeepL, map its response into a TranslationResult.
        // On failure, throw CodingPartners\TranslaGenius\Exceptions\TranslationFailedException,
        // setting `retryable: false` for permanent failures (bad key) and
        // `retryable: true` for transient ones (timeouts, rate limits) -
        // that flag drives the job's retry/fail behavior.
        return new TranslationResult(text: $translatedText, provider: 'deepl');
    }
}
```

Then register it in `config/translaGenius.php` and point `default` at it - no changes to the `Translatable` trait, `TranslateFields`, or anything else in the package:

```php
'default' => 'deepl',

'providers' => [
    'deepl' => [
        'driver' => \App\Translation\DeepLProvider::class,
        'key' => env('DEEPL_API_KEY'),
    ],
    // 'openrouter' => [ ... ] can stay defined alongside it
],
```

## Provider Fallback / Chaining

`Providers\FallbackProvider` wraps an ordered list of other providers: it tries each in turn and returns the first success, so a primary outage doesn't take translation down with it. It's just another `driver`, configured with a `chain` of other provider names instead of API credentials:

```php
'default' => 'resilient',

'providers' => [
    'openrouter' => [
        'driver' => \CodingPartners\TranslaGenius\Providers\OpenRouterProvider::class,
        'key' => env('TRANSLATION_API_KEY'),
        'url' => env('TRANSLATION_API_URL', 'https://openrouter.ai/api/v1/chat/completions'),
        'model' => env('TRANSLATION_MODEL', 'openai/gpt-4o'),
    ],
    'deepl' => [
        'driver' => \App\Translation\DeepLProvider::class,
        'key' => env('DEEPL_API_KEY'),
    ],
    'resilient' => [
        'driver' => \CodingPartners\TranslaGenius\Providers\FallbackProvider::class,
        'chain' => ['openrouter', 'deepl'], // tried in this order
    ],
],
```

Each name in `chain` is resolved the normal way (through its own `driver`/config entry), so a chain can mix any providers - built-in or your own - with no special-casing required from them. Falls through to the next provider on *any* translation failure from the current one, not only a retryable one - an unusable primary (bad key, exhausted quota) is exactly the case a fallback exists for, and that's typically a non-retryable failure. If every provider in the chain fails, `Exceptions\AllProvidersFailedException` (a `TranslationFailedException` subclass, so it's handled by `TranslateFields` exactly like any other translation failure) is thrown with each provider's individual failure attached via `getFailures()`, for diagnosing which one(s) actually broke.

## Lifecycle Events

`TranslateFields` dispatches three events a consuming app can listen for without touching the package: `Events\TranslationStarted` and `Events\TranslationCompleted` fire around each individual field/locale translation (so `TranslationCompleted` is the hook for usage/cost tracking - it carries the full `TranslationResult`, including its token-count fields), and `Events\TranslationFailed` fires once, from the job's `failed()` method, only when the job gives up permanently on a real `TranslationFailedException` (not on a transient, retried failure, and not on an unrelated job error).

```php
use CodingPartners\TranslaGenius\Events\TranslationCompleted;
use CodingPartners\TranslaGenius\Events\TranslationFailed;

// e.g. in your EventServiceProvider, or Event::listen() anywhere in boot():

Event::listen(function (TranslationCompleted $event) {
    UsageLog::create([
        'model_type' => $event->modelClass,
        'model_id' => $event->modelId,
        'field' => $event->field,
        'target_locale' => $event->targetLocale,
        'prompt_tokens' => $event->result->promptTokens,
        'completion_tokens' => $event->result->completionTokens,
    ]);
});

Event::listen(function (TranslationFailed $event) {
    Notification::route('slack', config('services.slack.alerts'))
        ->notify(new TranslationFailedNotification($event->modelClass, $event->modelId, $event->exception));
});
```

The `TranslationCompleted` example above is what "Usage & Cost Tracking" below does out of the box (with cost estimation and a queryable model) - write your own listener like this only if you need something it doesn't cover, e.g. your own table shape or per-tenant billing logic.

These are only dispatched from the automatic model-save lifecycle (the `Translatable` trait's job), not from direct `TranslatorInterface`/facade use - that context has no model or field to report.

## Usage & Cost Tracking

Opt-in, off by default - enabling it adds a `Listeners\LogTranslationUsage` listener on `Events\TranslationCompleted` that writes one row per completed translation to `translation_usage_logs`, and does nothing at all (no listener registered, no table required) when left disabled.

**1. Publish and run the migration:**

```bash
php artisan vendor:publish --tag=translagenius-migrations
php artisan migrate
```

**2. Enable it and (optionally) set per-provider cost rates**, in `config/translaGenius.php`:

```php
'usage_tracking' => [
    'enabled' => env('TRANSLATION_USAGE_TRACKING_ENABLED', true),

    'providers' => [
        // Keyed by the provider identifier a TranslationResult reports
        // (its `provider` property, e.g. 'openrouter'), not necessarily
        // the same as a key under the top-level `providers` above.
        'openrouter' => [
            'cost_per_1k_prompt_tokens' => 0.0025,
            'cost_per_1k_completion_tokens' => 0.01,
        ],
    ],
],
```

A provider with no rate configured (or one whose result doesn't report token counts) gets a `null` `estimated_cost` on its rows - never a `0`, which would falsely claim the translation was free.

**3. Query `Models\TranslationUsageLog`** like any other Eloquent model - it's public API surface meant for exactly this:

```php
use CodingPartners\TranslaGenius\Models\TranslationUsageLog;

// Total estimated spend so far (rows with no known rate are correctly excluded).
TranslationUsageLog::whereNotNull('estimated_cost')->sum('estimated_cost');

// Usage for one model.
TranslationUsageLog::where('model_class', Post::class)
    ->where('model_id', $post->id)
    ->get();

// Per-provider token usage this month.
TranslationUsageLog::selectRaw('provider, sum(prompt_tokens) as prompt, sum(completion_tokens) as completion')
    ->whereMonth('created_at', now()->month)
    ->groupBy('provider')
    ->get();
```

Only successful translations (`TranslationCompleted`) are logged - not `TranslationFailed`. This feature is scoped to usage/cost, and a failed attempt has no token counts or cost to report; if you also want failure-rate visibility, listen to `TranslationFailed` yourself (see "Lifecycle Events" above) rather than expecting it in this table.

## Other Extension Points

Beyond the provider, two more seams are swappable without forking anything:

- **The dispatched job** - override `translationJobClass()` on your model to dispatch a custom job (e.g. one with additional side effects) instead of the default `TranslateFields`:
  ```php
  class Post extends Model
  {
      use Translatable;

      protected static function translationJobClass(): string
      {
          return \App\Jobs\CustomTranslateFields::class;
      }
  }
  ```
- **Locale resolution** - `SetLocale`, the `Translatable` trait, and the `get_current_locale()`/`get_supported_languages()` helpers all resolve locale through the bound `Contracts\LocaleResolverInterface` (default: `LocaleResolvers\DefaultLocaleResolver`, which reads the request's `Accept-Language` header via `SetLocale` and `translaGenius.supported_languages`). Rebind it in your own service provider for a different strategy (subdomain-based, per-user preference, ...):
  ```php
  $this->app->singleton(
      \CodingPartners\TranslaGenius\Contracts\LocaleResolverInterface::class,
      \App\Support\SubdomainLocaleResolver::class
  );
  ```

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
