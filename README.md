# TranslaGenius Package

The TranslaGenius package is a Laravel package designed to automatically translate fields in your Eloquent models using an external translation API. It supports multiple languages and provides flexible translation management.

## Features

- Multi-language support (configurable)
- Automatic field translation
- Queue-based translation processing
- Switch between AI providers (OpenAI, Gemini, OpenRouter, DeepSeek, Mistral, or any OpenAI-compatible endpoint) with `.env` only
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

You don't need to write code or edit config to choose a translation provider: everything below is driven by `.env`. Publishing the config file is optional, for when you want to change supported languages or a preset's defaults:

```bash
php artisan vendor:publish --provider="CodingPartners\TranslaGenius\TranslaGeniusServiceProvider" --tag="translagenius-config"
```

The languages to translate between live in `supported_languages` (the first is the default):

```php
'supported_languages' => ['en', 'ar', 'es', 'fr'],
```

> **Upgrading from 3.x?** The config shape changed - see [CHANGELOG.md](CHANGELOG.md) for the migration notes.

### Choosing a Provider

The package ships ready-made presets for common OpenAI-compatible services. Each one reads its own environment variables and defaults to the service's official endpoint.

| Preset | Required | Optional | Default model |
|---|---|---|---|
| `openrouter` (default) | `OPENROUTER_API_KEY`, or the legacy `TRANSLATION_API_KEY` | `OPENROUTER_MODEL`, `OPENROUTER_API_URL`, `OPENROUTER_SITE_URL`, `OPENROUTER_APP_NAME` | `openai/gpt-4o` |
| `openai` | `OPENAI_API_KEY` | `OPENAI_MODEL`, `OPENAI_API_URL` | `gpt-6-luna` |
| `gemini` | `GEMINI_API_KEY` | `GEMINI_MODEL`, `GEMINI_API_URL` | `gemini-3.5-flash-lite` |
| `deepseek` | `DEEPSEEK_API_KEY` | `DEEPSEEK_MODEL`, `DEEPSEEK_API_URL` | `deepseek-flash` |
| `mistral` | `MISTRAL_API_KEY` | `MISTRAL_MODEL`, `MISTRAL_API_URL` | `mistral-small-latest` |
| `custom` | `TRANSLATION_API_URL`, `TRANSLATION_MODEL` | `TRANSLATION_API_KEY`, `TRANSLATION_AUTH_HEADER`, `TRANSLATION_TOKEN_LIMIT_PARAM` | none |

Settings shared by every preset: `TRANSLATION_API_TIMEOUT` (seconds, default 10), `TRANSLATION_TEMPERATURE` (default 0.3 on `openrouter`; unset, and so not sent, on every other preset), `TRANSLATION_MAX_TOKENS` (default unset; see below). Usage-tracking cost rates are per preset: `OPENAI_COST_PER_1K_PROMPT_TOKENS` / `OPENAI_COST_PER_1K_COMPLETION_TOKENS`, and the same pattern for `OPENROUTER_`, `GEMINI_`, `DEEPSEEK_` and `MISTRAL_`; `custom` uses `TRANSLATION_COST_PER_1K_*`.

**Output token limit.** `TRANSLATION_MAX_TOKENS` is unset by default, so no limit is sent and each provider's own default applies. A translation's length follows its input, so cost stays bounded by what you send, and a fixed limit is easy to hit: several default models reason before answering, and reasoning tokens count toward the limit (OpenAI and Gemini both document this). If a response does stop at the limit (`finish_reason` `length`), the translation fails instead of saving half a sentence. It isn't retried, since the same limit gives the same result, and a fallback chain moves on to its next provider. Set `TRANSLATION_MAX_TOKENS` to cap output explicitly.

**Temperature.** Only `openrouter` sends a temperature by default (0.3, as before). The other presets' default models reason before answering, and their docs either don't document `temperature` for them or say it has no effect while thinking (DeepSeek), so leaving it out lets each model use its own default. Setting `TRANSLATION_TEMPERATURE` sends it to whichever preset is active; an empty value sends nothing.

**`openai` default model.** `gpt-6-luna` is the cheapest text model on OpenAI's [models page](https://developers.openai.com/api/docs/models) ($0.10 / $0.50 per 1M input/output tokens), and every model listed there is a reasoning model. Its [model page](https://developers.openai.com/api/docs/models/gpt-6-luna) gives `medium` as the default reasoning effort, and per the [Chat Completions reference](https://developers.openai.com/api/reference/resources/chat/subresources/completions/methods/create) reasoning tokens count toward `max_completion_tokens` and are billed as output. That's why the preset sends neither a temperature nor a token limit by default.

Default models are a starting point, checked against each provider's documentation when they were chosen. Providers retire models over time; set the `*_MODEL` variable to pin the one you want. `mistral-small-latest` is an alias that Mistral may move to a newer model (and price) without notice.

### Switching Providers with `.env` Only

Set the preset name and its key. Nothing else changes:

```env
TRANSLATION_PROVIDER=gemini
GEMINI_API_KEY=your-gemini-key
```

Existing setups keep working: `TRANSLATION_PROVIDER` still defaults to `openrouter`, and that preset still reads `TRANSLATION_API_KEY`, `TRANSLATION_API_URL` and `TRANSLATION_MODEL` when its own `OPENROUTER_*` variables aren't set. Those legacy names also drive `custom`, so if you use both presets together (for example in a fallback chain), set `OPENROUTER_API_KEY` and friends explicitly for OpenRouter.

### Fallback with `.env` Only

To fail over from one provider to another, point `TRANSLATION_PROVIDER` at the shipped `fallback` entry and list the presets to try, in order:

```env
TRANSLATION_PROVIDER=fallback
TRANSLATION_FALLBACK_CHAIN=openai,gemini
OPENAI_API_KEY=your-openai-key
GEMINI_API_KEY=your-gemini-key
```

The first provider to succeed wins; later ones aren't called. Names are trimmed and empty items ignored, and the chain needs at least 2 entries. A missing chain, a chain that references itself, or an unknown name fails at resolution time with an error naming the problem. See [Provider Fallback / Chaining](#provider-fallback--chaining) for how failures are handled.

### Retries and Rate Limits

Failed calls are retried in two layers, each configurable from `.env`:

| Variable | Default | What it does |
|---|---|---|
| `TRANSLATION_HTTP_RETRIES` | `1` | Quick in-request retries (200ms-1s apart) for connection errors, 429 and 5xx. |
| `TRANSLATION_HTTP_RETRY_AFTER_MAX` | `10` | A 429 whose `Retry-After` is at most this many seconds is waited out in-request. A longer one ends the attempt, and the queued job is released for exactly that delay. |
| `TRANSLATION_FALLBACK_HTTP_RETRIES` | `0` | HTTP retries for providers inside a fallback chain. The next provider is the retry, so failover isn't delayed. |
| `TRANSLATION_JOB_TRIES` | `3` | Queue attempts for the translation job. |
| `TRANSLATION_JOB_BACKOFF` | `10,30` | Seconds between queue attempts (comma-separated). |

4xx responses other than 429 (bad key, bad request, unknown model) are never retried at any layer: the job fails on the first response. So does a truncated translation (see `TRANSLATION_MAX_TOKENS`).

**Worst case** for one field and locale is `(TRANSLATION_HTTP_RETRIES + 1) x TRANSLATION_JOB_TRIES` requests: **6** with the defaults, for a provider that keeps returning 5xx. In a fallback chain, each job attempt sends one request per chain member, so a two-provider chain is at most `2 x TRANSLATION_JOB_TRIES` = 6 as well. Because a job translates all of a model's missing field/locale pairs before saving any of them, a retried job attempt repeats the pairs that had already succeeded in that attempt.

### Any Other OpenAI-Compatible Endpoint

The `custom` preset covers any service or self-hosted gateway that speaks the OpenAI chat-completions format (Ollama, vLLM, LiteLLM, a corporate proxy, ...). Set the **full** chat-completions URL:

```env
TRANSLATION_PROVIDER=custom
TRANSLATION_API_URL=http://localhost:11434/v1/chat/completions
TRANSLATION_MODEL=llama3.1
TRANSLATION_API_KEY=anything-or-empty-if-your-gateway-ignores-it
```

By default the key is sent as `Authorization: Bearer <key>`. For a service that takes the key in a different header, name it with `TRANSLATION_AUTH_HEADER` and the raw key is sent there instead. Azure OpenAI's key-based auth, for example, documents an `api-key` header (`TRANSLATION_AUTH_HEADER=api-key`); this has not been tested against a live Azure resource. If a service rejects `max_tokens`, set `TRANSLATION_TOKEN_LIMIT_PARAM=max_completion_tokens`.

In a published config, any OpenAI-compatible entry also accepts a `headers` array of extra request headers (null or empty values are dropped). The `openrouter` preset uses it for OpenRouter's optional `HTTP-Referer` / `X-OpenRouter-Title` attribution headers, set via `OPENROUTER_SITE_URL` / `OPENROUTER_APP_NAME`.

One provider entry, with every setting it understands:

```php
'gemini' => [
    'driver' => \CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider::class,
    'key' => env('GEMINI_API_KEY'),
    'url' => env('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'),
    'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
    'timeout' => env('TRANSLATION_API_TIMEOUT', 10),
    'temperature' => env('TRANSLATION_TEMPERATURE', 0.3),
    'max_tokens' => env('TRANSLATION_MAX_TOKENS'),   // unset: provider default
    'token_limit_param' => 'max_tokens',   // or 'max_completion_tokens'
    'auth_header' => 'Authorization',      // or e.g. 'api-key'
    'headers' => [],                       // extra request headers
    'cost_per_1k_prompt_tokens' => env('GEMINI_COST_PER_1K_PROMPT_TOKENS'),
    'cost_per_1k_completion_tokens' => env('GEMINI_COST_PER_1K_COMPLETION_TOKENS'),
],
```

Each translation's `TranslationResult->provider` (and its usage-log row) records the entry's key - `gemini` here.

### APIs That Aren't OpenAI-Compatible

`.env` alone can't reach an API with a different request shape, such as DeepL, AWS Translate or Azure Translator. Those need a small class implementing `TranslationProviderInterface` - see [Custom Translation Providers](#custom-translation-providers).

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
4. **Provider**: The bound provider (`OpenAiCompatibleProvider` by default) communicates with the external API and returns a `TranslationResult`.

## Custom Translation Providers

For a translation API that doesn't speak the OpenAI chat-completions format, write a class implementing `TranslationProviderInterface` and register it as a provider entry. The `Translatable` trait, the job, fallback chains and usage tracking all work with it unchanged.

A DeepL provider, as a worked example (endpoint and fields per DeepL's [Translate text](https://developers.deepl.com/api-reference/translate/request-translation) reference):

```php
namespace App\Translation;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use Illuminate\Support\Facades\Http;

class DeepLProvider implements TranslationProviderInterface
{
    // $config is this provider's entry from config/translaGenius.php, plus
    // `name` (the entry's key), which the package adds for you.
    public function __construct(protected array $config)
    {
    }

    public function translate(TranslationRequest $request): TranslationResult
    {
        $response = Http::withHeaders([
            'Authorization' => 'DeepL-Auth-Key ' . $this->config['key'],
        ])->timeout(10)->post($this->config['url'], [
            'text' => [$request->text],
            'source_lang' => strtoupper($request->sourceLocale),
            'target_lang' => strtoupper($request->targetLocale),
        ]);

        if ($response->failed()) {
            throw new TranslationFailedException(
                "DeepL request failed with status {$response->status()}",
                statusCode: $response->status(),
                // Permanent failures (bad key) shouldn't be retried; rate
                // limits and server errors should.
                retryable: $response->status() === 429 || $response->serverError(),
            );
        }

        $text = $response->json('translations.0.text');

        if (empty($text)) {
            throw new TranslationFailedException('DeepL returned no translation', retryable: true);
        }

        return new TranslationResult(text: $text, provider: $this->config['name'] ?? 'deepl');
    }
}
```

Register it next to the shipped presets in a published `config/translaGenius.php`:

```php
'providers' => [
    // ... shipped presets ...
    'deepl' => [
        'driver' => \App\Translation\DeepLProvider::class,
        'key' => env('DEEPL_API_KEY'),
        'url' => env('DEEPL_API_URL', 'https://api.deepl.com/v2/translate'), // api-free.deepl.com on the Free plan
    ],
],
```

From then on it's selectable like any preset: `TRANSLATION_PROVIDER=deepl`, or as a member of `TRANSLATION_FALLBACK_CHAIN`. If `driver` names a class that doesn't exist, or one that doesn't implement `TranslationProviderInterface`, resolution fails immediately with an error naming the provider key and the class. Locale codes are passed through as configured in `supported_languages`; map them if your API expects a different format (DeepL distinguishes `EN-GB`/`EN-US` targets, for example).

## Provider Fallback / Chaining

`Providers\FallbackProvider` wraps an ordered list of other providers: it tries each in turn and returns the first success, so a primary outage doesn't take translation down with it. The shipped `fallback` entry is driven by `TRANSLATION_FALLBACK_CHAIN` (see [Fallback with `.env` Only](#fallback-with-env-only)); in a published config you can also define your own, with `chain` as an array:

```php
'resilient' => [
    'driver' => \CodingPartners\TranslaGenius\Providers\FallbackProvider::class,
    'chain' => ['openai', 'deepl'], // any provider names, tried in this order
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

**2. Enable it and (optionally) set cost rates.** With the shipped presets this is `.env` only:

```env
TRANSLATION_USAGE_TRACKING_ENABLED=true
OPENAI_COST_PER_1K_PROMPT_TOKENS=0.0001
OPENAI_COST_PER_1K_COMPLETION_TOKENS=0.0005
```

Rates belong to each provider's own entry under `providers`, so in a published config they sit with the rest of that provider's settings:

```php
'providers' => [
    'openrouter' => [
        // ... driver, key, url, model ...
        'cost_per_1k_prompt_tokens' => 0.0025,
        'cost_per_1k_completion_tokens' => 0.01,
    ],
],

'usage_tracking' => [
    'enabled' => env('TRANSLATION_USAGE_TRACKING_ENABLED', false),
],
```

Each log row's `provider` is the config key that served the translation (with a fallback chain, the member that succeeded), and its cost uses that entry's rates.

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



## Contributing & Testing

The test suite needs no external services by default: it runs on SQLite in memory, and every provider call is faked.

```bash
composer install
vendor/bin/phpunit
```

To run the same suite against a real database, set `TRANSLAGENIUS_TEST_DB_CONNECTION` (`mysql`, `mariadb`, `pgsql` or `sqlsrv`) and the usual `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` variables.

### Live provider smoke tests

A separate `live` group sends one short translation through each shipped preset to the real API, to check that the preset works end to end: the call succeeds, the result isn't empty or truncated, and it's attributed to the right preset. It is excluded from the default run and from CI, and each preset is skipped unless its key is in the environment. Pass only the keys you have:

```bash
OPENAI_API_KEY=sk-... GEMINI_API_KEY=... vendor/bin/phpunit --group live
```

Keys per preset: `OPENROUTER_API_KEY` (or `TRANSLATION_API_KEY`), `OPENAI_API_KEY`, `GEMINI_API_KEY`, `DEEPSEEK_API_KEY`, `MISTRAL_API_KEY`; the `custom` preset needs `TRANSLATION_API_URL` and `TRANSLATION_MODEL`. These are real, billed calls (a few tokens each). Never commit keys: pass them on the command line or export them in your shell.

## Conclusion

The TranslaGenius package simplifies the process of adding multi-language support to your Laravel application. By following the steps outlined above, you can easily configure and use the package to automatically translate your model fields, ensuring that your application is accessible to a global audience.
