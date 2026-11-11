# Upgrade guide

## From 3.x (3.1.0) to 4.0

4.0 is a rewrite of the package internals. The model-facing API is unchanged: models still `use Translatable` and declare `$translatable`, and saves still dispatch the translation job. Most of the work is the config file and the platform floors. Go through the steps in order. The [CHANGELOG](CHANGELOG.md) has the full detail for each change.

### 1. Check the platform floors

| | 3.1.0 | 4.0 |
|---|---|---|
| PHP | `>=8.2` | `^8.3` |
| Laravel (`illuminate/*`) | `^10.0 \|\| ^11.0 \|\| ^12.0` | `^12.0 \|\| ^13.0` |
| `spatie/laravel-translatable` | `^6.0` | `^6.14` |

- [ ] Your app runs PHP 8.3 or later.
- [ ] Your app runs Laravel 12 or 13. Upgrade Laravel first if you are on 10 or 11.

### 2. Drain the queue (recommended)

A job queued by 3.1.0 still runs under 4.0. It carries no source locale, so it translates from `TRANSLATION_SOURCE_LOCALE`, or from `app.locale` when that is unset (see step 8). If your content is not authored in that locale, let the workers finish the 3.x jobs before you deploy 4.0.

- [ ] No 3.x translation jobs are left on the queue, or your content is authored in `app.locale`.

### 3. Update the package

```bash
composer require coding-partners/transla-genius:^4.0
```

### 4. Rename the published config file

The config file and key are renamed from `translaGenius` to `translagenius`, all lowercase. There is no fallback to the old name: a file left at `config/translaGenius.php` is ignored, and the package runs on its shipped defaults.

- [ ] If you published the config, rename `config/translaGenius.php` to `config/translagenius.php`. On a case-insensitive file system (macOS, Windows), use a two-step rename, for example `git mv config/translaGenius.php config/tmp.php && git mv config/tmp.php config/translagenius.php`.
- [ ] Replace every `config('translaGenius.…')` call in your code with `config('translagenius.…')`.
- [ ] Run `php artisan translagenius:check`. It fails and says so while `config/translaGenius.php` is still there.

The publish tag is unchanged: `translagenius-config`.

### 5. Move your settings to the new config shape

The flat `api.*` and `settings.*` keys are gone. Settings now live per provider under `providers`, and `default` names the one in use:

```php
// 3.x
'api' => ['key' => ..., 'url' => ..., 'model' => ..., 'timeout' => ...],
'settings' => ['temperature' => ..., 'max_tokens' => ...],

// 4.0
'default' => env('TRANSLATION_PROVIDER', 'openrouter'),
'providers' => [
    'openrouter' => ['driver' => OpenAiCompatibleProvider::class, 'key' => ..., 'url' => ..., 'model' => ..., 'timeout' => ..., 'temperature' => ..., 'max_tokens' => ...],
    // openai, gemini, deepseek, mistral, custom, and the fallback chain
],
```

- [ ] If you changed values in the published 3.x file, republish (`php artisan vendor:publish --tag=translagenius-config --force`) and copy your values into the new structure. If you only ever set environment variables, republishing is all you need, or you can delete the published file and use the shipped one.
- [ ] `supported_languages` keeps its meaning: the first entry is the default locale.

### 6. Check your environment variables

No 3.x variable is renamed or removed. All six are still read:

| 3.x variable | In 4.0 |
|---|---|
| `TRANSLATION_API_KEY` | Key for the default `openrouter` preset when `OPENROUTER_API_KEY` is unset, and for the `custom` preset. |
| `TRANSLATION_API_URL` | URL for `openrouter` when `OPENROUTER_API_URL` is unset, and for `custom`. |
| `TRANSLATION_MODEL` | Model for `openrouter` when `OPENROUTER_MODEL` is unset, and for `custom`. |
| `TRANSLATION_API_TIMEOUT` | Shared by every preset. **The default is now 60 seconds** (was 10). |
| `TRANSLATION_TEMPERATURE` | Shared by every preset. Only `openrouter` still defaults it to 0.3 (see step 9). |
| `TRANSLATION_MAX_TOKENS` | Shared by every preset. **No longer defaults to 200** (see step 9). |

A 3.x `.env` therefore keeps working on the default `openrouter` preset. All other variables are new, and all are optional:

- **Provider choice:** `TRANSLATION_PROVIDER` (default `openrouter`). Presets: `openrouter`, `openai`, `gemini`, `deepseek`, `mistral`, `custom`.
- **Per-preset settings:** `OPENROUTER_*`, `OPENAI_*`, `GEMINI_*`, `DEEPSEEK_*`, `MISTRAL_*`, each with `_API_KEY`, `_API_URL`, `_MODEL`, `_REASONING_EFFORT`, `_BATCH`, `_COST_PER_1K_PROMPT_TOKENS` and `_COST_PER_1K_COMPLETION_TOKENS`. OpenRouter also has `OPENROUTER_SITE_URL` and `OPENROUTER_APP_NAME`.
- **The `custom` preset:** `TRANSLATION_AUTH_HEADER`, `TRANSLATION_TOKEN_LIMIT_PARAM`, `TRANSLATION_CUSTOM_BATCH`, `TRANSLATION_COST_PER_1K_PROMPT_TOKENS`, `TRANSLATION_COST_PER_1K_COMPLETION_TOKENS`.
- **Fallback chain:** `TRANSLATION_FALLBACK_CHAIN`, `TRANSLATION_FALLBACK_HTTP_RETRIES`.
- **Source locale:** `TRANSLATION_SOURCE_LOCALE`.
- **Prompt:** `TRANSLATION_SYSTEM_PROMPT`, `TRANSLATION_REASONING_EFFORT`.
- **Batching:** `TRANSLATION_BATCH`, `TRANSLATION_BATCH_PROMPT`, `TRANSLATION_BATCH_RESPONSE_FORMAT`.
- **Cache:** `TRANSLATION_CACHE`, `TRANSLATION_CACHE_STORE`, `TRANSLATION_CACHE_TTL`.
- **Review workflow:** `TRANSLATION_REVIEW`, `TRANSLATION_REVIEW_LOCALES`, `TRANSLATION_REVIEW_WHILE_PENDING`, `TRANSLATION_REVIEW_ON_REJECT`.
- **Queue:** `TRANSLATION_QUEUE_CONNECTION`, `TRANSLATION_QUEUE`, `TRANSLATION_DISPATCH_AFTER_COMMIT`.
- **Job runtime:** `TRANSLATION_JOB_TIMEOUT`.
- **Retries:** `TRANSLATION_HTTP_RETRIES`, `TRANSLATION_HTTP_RETRY_AFTER_MAX`, `TRANSLATION_JOB_TRIES`, `TRANSLATION_JOB_BACKOFF`.
- **Usage tracking:** `TRANSLATION_USAGE_TRACKING_ENABLED`.

The README's environment variable reference lists each one with its default.

- [ ] Your `.env` sets a key for the provider you use.

### 7. Update code that touches package internals

Skip this step if your app only uses the `Translatable` trait, its scopes and the `SetLocale` middleware through Laravel's middleware registration.

- [ ] **`Services\AutoTranslationService` is removed.** Type-hint `Contracts\TranslatorInterface` instead, or use the `TranslaGenius` facade. `translate($text, $source, $target)` now returns a `DTOs\TranslationResult`. Read the text from `->text`; it is no longer a bare string.
- [ ] **`Middleware\SetLocale` takes a constructor argument** (`Contracts\LocaleResolverInterface`). Laravel's middleware registration and `app(SetLocale::class)` resolve it for you. Only `new SetLocale()` breaks.
- [ ] **`Jobs\TranslateFields` is constructed with one `DTOs\TranslationJobPayload`.** If you dispatch it yourself, wrap the arguments: `TranslateFields::dispatch(new TranslationJobPayload($model, $fields))`. Its `handle()` now takes `(TranslatorInterface $translator, TranslationWriter $writer)`, and `prepareTranslations()` is replaced by `translateMissingPairs()`.
- [ ] **Custom jobs:** a model can now name its own job by overriding `protected static function translationJobClass(): string`. That class must implement `Contracts\TranslationJob`, whose constructor takes the `TranslationJobPayload`. Extending `TranslateFields` satisfies it. A class that doesn't implement it is rejected at dispatch with `Exceptions\InvalidTranslationJobException`.
- [ ] **`Traits\Translatable::hasSourceLocaleChanged()` is removed.** It was a protected helper of the 3.x `updated` listener; the listener now builds a re-translation plan instead. Remove any call to it from a model.
- [ ] **The `get_current_locale()` and `get_supported_languages()` helpers are deprecated.** They still work. In new code, resolve `Contracts\LocaleResolverInterface` and call `currentLocale()` or `supportedLocales()`. If your app defines functions with either name, the package no longer calls yours.
- [ ] **Exceptions have a common base.** Every exception the package defines extends `Exceptions\TranslaGeniusException`. Configuration errors (unknown provider, missing key, bad chain or driver) throw `Exceptions\TranslationConfigurationException`, a `TranslationFailedException`; a 3.x missing-key error was an `HttpResponseException`.
- [ ] **A custom `TranslationProviderInterface` must not return empty text.** `DTOs\TranslationResult` throws `InvalidArgumentException` for empty or whitespace-only text. Throw `Exceptions\TranslationFailedException` instead.

The new public names, for reference:

- Facade: `CodingPartners\TranslaGenius\Facades\TranslaGenius` (auto-registered alias `TranslaGenius`), with `translate()` and `reviews()`.
- Contracts: `TranslatorInterface`, `BatchTranslatorInterface`, `TranslationProviderInterface`, `BatchTranslationProviderInterface`, `LocaleResolverInterface`, `TranslationJob`, `HasTranslatableAttributes`.

### 8. Publish the migrations, if you use the features behind them

4.0 adds two optional tables. Both stubs are published together, under one tag:

```bash
php artisan vendor:publish --tag=translagenius-migrations
php artisan migrate
```

| Table | Needed only when |
|---|---|
| `translation_usage_logs` | `TRANSLATION_USAGE_TRACKING_ENABLED=true` |
| `translation_reviews` | `TRANSLATION_REVIEW=true` |

With both features off, neither table is needed. If you publish both, you can delete the migration file of the feature you don't use before running `migrate`.

- [ ] Tables are migrated for each optional feature you turn on.

### 9. Review the behavior changes

Nothing to edit here, but each of these changes what your app does after the upgrade.

- [ ] **Source locale is captured when the job is dispatched.** 3.x read the locale inside the queue worker, so on a real (non-`sync`) queue it usually translated from the worker's `app.locale`, not from the language the record was saved in. 4.0 records the request's locale at dispatch. Set `TRANSLATION_SOURCE_LOCALE` if your content is always authored in one language; it takes precedence over the request locale. `translagenius:backfill` takes `--source=`, then `TRANSLATION_SOURCE_LOCALE`, then `app.locale`.
- [ ] **Editing the source text re-translates.** A change to a field's source-locale text re-translates that field's other locales and overwrites them. A target locale set in the same save is kept. A target edited by hand after the source edit is not overwritten. Creating records and `translagenius:backfill` still only fill missing locales.
- [ ] **Jobs have a timeout, and the queue's `retry_after` must exceed it.** A translation job may now run up to its own worst case (120 to 900 seconds by default; `TRANSLATION_JOB_TIMEOUT` sets it). Raise `retry_after` on the queue connection in `config/queue.php` above that (Laravel's default is 90), or the queue hands a running job to a second worker. The HTTP timeout default is now 60 seconds.
- [ ] **One job per record at a time.** A second job for a record that is being translated waits, re-queued with a short delay, using a lock in the default cache store. Use a store that shares locks between workers (Redis, database, Memcached, DynamoDB).
- [ ] **Model events.** Like 3.x, translations are saved without model events, so observers don't see them. New: `Events\TranslationsPersisted` fires once per job with the record and the pairs written, and `TRANSLATION_FIRE_MODEL_EVENTS=true` fires the model's `updated` and `saved` events once per job. Use either for Scout or cache busting.
- [ ] **Jobs dispatch after the database transaction commits**, and not at all on rollback. To use the queue connection's own `after_commit` setting instead, set `TRANSLATION_DISPATCH_AFTER_COMMIT=false`. Jobs go to `TRANSLATION_QUEUE_CONNECTION` / `TRANSLATION_QUEUE` when set.
- [ ] **Retries changed.** 3.x retried every failed HTTP call up to 3 times, including 4xx responses, then caught and logged the failure, so the queue never retried the job. 4.0 makes 1 quick HTTP retry for connection errors, 429 and 5xx (never for other 4xx), honors `Retry-After` up to 10 seconds, and lets the job fail to the queue: 3 attempts with backoff `10,30`. A field/locale pair can take at most 6 requests. Failed jobs now land in `failed_jobs` and fire `Events\TranslationFailed`.
- [ ] **No fixed `max_tokens`.** 3.x always sent `max_tokens: 200`, which could cut long texts. 4.0 sends no limit unless `TRANSLATION_MAX_TOKENS` is set. A reply cut off at the limit now fails the pair instead of being saved incomplete.
- [ ] **`temperature` is omitted on most presets.** Only `openrouter` still defaults to 0.3. The other presets send no temperature unless `TRANSLATION_TEMPERATURE` is set, so the model's own default applies.
- [ ] **The prompt changed.** Requests use `system` and `user` messages instead of one combined message. Set `TRANSLATION_SYSTEM_PROMPT` to customize it.
- [ ] **Existing translations are no longer re-requested.** 3.x re-translated and re-billed every other locale on each save. 4.0 fills only missing field/locale pairs and saves each as soon as it is translated.

### 10. Verify

- [ ] `php artisan translagenius:check` passes.
- [ ] Saving a record in your source language fills the other locales.
