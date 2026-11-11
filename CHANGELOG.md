# Changelog

All notable changes to `coding-partners/transla-genius` are documented here.

## Unreleased

A breaking architectural release: the translation backend is now swappable behind an interface, translation payloads are typed DTOs instead of raw arrays/strings, and persistence is a separate, narrowly-scoped class instead of being mixed into the queue job.

### Breaking changes

- **Config shape changed.** The flat `api.*` / `settings.*` keys are gone. Config is now provider-keyed:
  ```php
  // Before (3.x)
  'api' => ['key' => ..., 'url' => ..., 'model' => ...],
  'settings' => ['temperature' => ..., 'max_tokens' => ...],

  // After
  'default' => 'openrouter',
  'providers' => [
      'openrouter' => ['driver' => OpenAiCompatibleProvider::class, 'key' => ..., 'url' => ..., 'model' => ..., 'timeout' => ..., 'temperature' => ..., 'max_tokens' => ...],
  ],
  ```
  Republish the config file (`php artisan vendor:publish --tag=translagenius-config --force`) and migrate any custom values you'd set.
- **`Services\AutoTranslationService` is removed.** If you depended on it directly (type-hinting it, resolving it from the container), depend on `Contracts\TranslatorInterface` instead - `translate($text, $source, $target)` now returns a `DTOs\TranslationResult` (with a `->text` property) instead of a bare string.
- **`TranslateFields::handle()`'s signature changed** from `handle(AutoTranslationService $translationService)` to `handle(TranslatorInterface $translator, TranslationWriter $writer)`. If you extended or directly invoked this job's `handle()` method, update the call site.
- **`Middleware\SetLocale` now requires constructor injection** (`Contracts\LocaleResolverInterface`) - if you were instantiating it directly (`new SetLocale()`) rather than letting Laravel resolve it from the container, that will break. Resolve it via the container (`app(SetLocale::class)`) or Laravel's normal middleware registration instead.
- **`DTOs\TranslationResult` now throws `InvalidArgumentException` if constructed with empty/whitespace-only text.** A custom `TranslationProviderInterface` implementation that previously returned an empty result without throwing `TranslationFailedException` will now fail loudly at construction time instead of silently producing a result nothing downstream expected.
- `Traits\Translatable::scopeFullyTranslated()`'s underlying SQL changed (now driver-agnostic `whereNotNull('field->locale')` instead of MySQL-only raw `JSON_EXTRACT`/`JSON_UNQUOTE`) - behavior is equivalent, but if you depended on the exact generated SQL for any reason, it's different now.

### Added

- `Contracts\TranslationProviderInterface` - the extension point for swapping translation backends. Implement it and register it under `translaGenius.providers` to use DeepL, Google Translate, another LLM, or an internal service - no changes to the job or trait required. See the README's "Custom Translation Providers" section.
- `Contracts\TranslatorInterface` - the orchestration layer above the provider is now also behind an interface (`Services\Translator` is the default implementation). Rebind it to replace translation-resolution itself (caching, fallback chaining, ...) without touching the job or trait.
- `Contracts\LocaleResolverInterface` - locale resolution (previously two bare global functions with no DI seam) is now a bindable interface (`LocaleResolvers\DefaultLocaleResolver` is the default: `Accept-Language` header + `translaGenius.supported_languages`). `get_current_locale()`/`get_supported_languages()` remain as thin backward-compatible wrappers around whatever's bound.
- `Traits\Translatable::translationJobClass()` - override this on your model to dispatch a custom job instead of the default `TranslateFields`, without forking the trait.
- `Contracts\HasTranslatableAttributes` - documents the real precondition `TranslationWriter`/`TranslateFields` depend on (a model with `getTranslation()`/`setTranslation()`, i.e. one using the `Translatable` trait) for static analysis; enforced at runtime by `TranslationWriter` with a clear error message rather than the `BadMethodCallException` you'd otherwise hit.
- `DTOs\TranslationRequest` / `DTOs\TranslationResult` - typed, `readonly` value objects replacing the raw strings/arrays previously threaded through the job.
- `Services\TranslationWriter` - the locked read-check-write step (introduced earlier in this release as part of `TranslateFields`) is now its own small, job-independent class.
- `Facades\TranslaGenius` - convenience facade over `TranslatorInterface` for translating arbitrary text outside the automatic model-save flow.
- `php artisan translagenius:check` - verifies the active database connection's JSON support (MySQL/MariaDB/PostgreSQL/SQL Server unconditionally have it at this package's stated floors; SQLite genuinely doesn't below 3.38.0 without JSON1 compiled in - see the README's "Database Requirements" section).
- `Events\TranslationStarted` / `Events\TranslationCompleted` / `Events\TranslationFailed` - `TranslateFields` now dispatches these at well-defined points in the translation lifecycle, so features like usage tracking or review workflows can be built as plain listeners instead of forking the job. See the README's "Lifecycle Events" section.
- `Providers\FallbackProvider` - a `TranslationProviderInterface` decorator over an ordered chain of other providers, falling through to the next on any failure and returning the first success. Configured as a `driver` like any other provider, with a `chain` key of other provider names resolved through the same config-driven resolution - see the README's "Provider Fallback / Chaining" section. Throws the new `Exceptions\AllProvidersFailedException` (a `TranslationFailedException` subclass) if every provider in the chain fails, carrying each one's individual failure via `getFailures()`.
- `Traits\Translatable::scopeNotFullyTranslated()` - the exact inverse of `scopeFullyTranslated()`, for finding records missing at least one field/locale.
- `php artisan translagenius:backfill {model}` - finds existing records that aren't fully translated and dispatches `TranslateFields` for each, chunked via `chunkById()`. Reports the affected record count and an estimated API-call count before doing anything, and requires `--force` or an interactive confirmation before dispatching (a bare, unconfirmed run dispatches nothing). `--dry-run` reports only. See the README's "Backfilling Existing Records" section.
- `translaGenius.usage_tracking` (opt-in, `enabled: false` by default) - when turned on, `Listeners\LogTranslationUsage` writes one `Models\TranslationUsageLog` row per `Events\TranslationCompleted`, with an estimated cost computed from configurable per-provider `cost_per_1k_prompt_tokens`/`cost_per_1k_completion_tokens` rates (`null`, never `0`, when a rate or token count isn't available). Ships the package's first publishable migration (`php artisan vendor:publish --tag=translagenius-migrations`), for the `translation_usage_logs` table this backs. `TranslationUsageLog` is public API surface - query it directly for your own usage/cost reporting. See the README's "Usage & Cost Tracking" section. When disabled (the default), no listener is registered and the migration isn't required.
- Added an opt-in `shouldTranslateField()` hook models can define to filter which field content is sent for translation.
- `Providers\OpenAiCompatibleProvider` accepts an optional `headers` array (merged into every request; null/empty values are dropped) an optional `auth_header` (default `Authorization`, which sends `Bearer <key>`; any other header name sends the raw key), and an optional `token_limit_param` (default `max_tokens`; set `max_completion_tokens` for APIs that deprecate `max_tokens`, such as OpenAI's).
- Ready-made provider presets in the shipped config: `openrouter` (still the default), `openai`, `gemini`, `deepseek`, `mistral`, and `custom` for any other OpenAI-compatible endpoint (Ollama, vLLM, LiteLLM, ...). Each reads its own environment variables, so switching providers is a `.env` change (`TRANSLATION_PROVIDER=gemini` plus `GEMINI_API_KEY=...`). The legacy `TRANSLATION_API_KEY`/`TRANSLATION_API_URL`/`TRANSLATION_MODEL` variables keep working for the `openrouter` default and drive the `custom` entry.
- Failover through `.env` alone: `TRANSLATION_PROVIDER=fallback` with `TRANSLATION_FALLBACK_CHAIN=openai,gemini` (comma-separated, whitespace trimmed, empty items ignored) uses the shipped `fallback` entry. A `chain` may now be an array or a comma-separated string.
- Provider resolution now fails fast with a clear `RuntimeException`, naming the provider key and class, when a `driver` class doesn't exist or doesn't implement `TranslationProviderInterface`.

### Fixed

- Failed provider calls were retried regardless of status: the HTTP client's retry callback was passed in its sleep-duration slot, so a 401 or 404 was sent 3 times per job attempt, and a persistent 5xx up to 9 times across the job's own retries. 4xx responses other than 429 now fail on the first response at every layer.
- A translation cut off at the provider's output token limit (`finish_reason` `length`) was saved as if complete. It now fails with a non-retryable `TranslationFailedException` that names `TRANSLATION_MAX_TOKENS`; the job isn't retried, the `TranslationFailed` event fires, and a fallback chain moves on to its next provider.
- `TranslationResult->provider` now reports the `translaGenius.providers` key the provider was resolved under (for example `gemini`). It was hardcoded to `openrouter`, so pointing the OpenAI-compatible provider at any other service logged usage under the wrong provider name and looked up the wrong cost rates.
- `TranslateFields` no longer re-requests translations that already exist. `prepareTranslations()` read each field through the model's magic accessor (`$model->{$field}`), which Spatie's `HasTranslations` resolves to the current locale's string only. Every other locale's existing translation was invisible to the job, so every save re-translated (and re-billed) every non-current locale. It now reads the full locale map via `getTranslations($field)`, and existing field/locale pairs are skipped as intended.
- The HTTP timeout is now actually applied to translation requests. It was defined in config (`api.timeout` in 3.x, now `translaGenius.providers.openrouter.timeout` / `TRANSLATION_API_TIMEOUT`) but silently ignored.
- `TranslateFields` now has explicit `$tries`/`backoff()` and a `failed()` handler; translation failures propagate to the queue's native retry/failure handling instead of being silently swallowed.
- Added pessimistic locking (`lockForUpdate()`) around the job's write step to prevent a concurrent update's translation from being overwritten by a stale one.
- Stopped logging raw stack traces (a plausible path for the API key to end up in application logs via HTTP-client exception trace arguments).

### Changed

- Retry defaults: 1 quick HTTP retry (`TRANSLATION_HTTP_RETRIES`) for connection errors, 429 and 5xx, then the queue's 3 attempts (`TRANSLATION_JOB_TRIES`, backoff `TRANSLATION_JOB_BACKOFF`). A 429's `Retry-After` is honored: waited out in-request up to `TRANSLATION_HTTP_RETRY_AFTER_MAX` seconds (default 10), otherwise used as the queue release delay. Fallback chain members make no HTTP retries by default (`TRANSLATION_FALLBACK_HTTP_RETRIES=0`), so failover is immediate. Worst case per field/locale is 6 requests.
- `TRANSLATION_MAX_TOKENS` is unset by default (was 200), so no limit is sent and each provider's own default applies. Set it to cap output.
- `temperature` is optional: a null or empty value isn't sent. Only the `openrouter` preset still defaults it (0.3); the other presets leave it to the model.
- `Services\AutoTranslationService` → split into `Providers\OpenAiCompatibleProvider` (HTTP transport, prompt construction, response parsing - the OpenRouter-specific implementation) and `Services\Translator` (a thin class that resolves the configured provider and delegates).
- Introduced `Exceptions\TranslationFailedException`, replacing the misused `Illuminate\Http\Exceptions\HttpResponseException`.
- The translation prompt now uses the chat API's `system`/`user` message roles instead of one concatenated string.
