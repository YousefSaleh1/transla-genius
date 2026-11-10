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
      'openrouter' => ['driver' => OpenRouterProvider::class, 'key' => ..., 'url' => ..., 'model' => ..., 'timeout' => ..., 'temperature' => ..., 'max_tokens' => ...],
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

### Fixed

- `TranslateFields` no longer re-requests translations that already exist. `prepareTranslations()` read each field through the model's magic accessor (`$model->{$field}`), which Spatie's `HasTranslations` resolves to the current locale's string only. Every other locale's existing translation was invisible to the job, so every save re-translated (and re-billed) every non-current locale. It now reads the full locale map via `getTranslations($field)`, and existing field/locale pairs are skipped as intended.
- The HTTP timeout is now actually applied to translation requests. It was defined in config (`api.timeout` in 3.x, now `translaGenius.providers.openrouter.timeout` / `TRANSLATION_API_TIMEOUT`) but silently ignored.
- `TranslateFields` now has explicit `$tries`/`backoff()` and a `failed()` handler; translation failures propagate to the queue's native retry/failure handling instead of being silently swallowed.
- Added pessimistic locking (`lockForUpdate()`) around the job's write step to prevent a concurrent update's translation from being overwritten by a stale one.
- Stopped logging raw stack traces (a plausible path for the API key to end up in application logs via HTTP-client exception trace arguments).
- Fixed test-suite defects: a config-shape mismatch between the test fixture and production code, a test file missing its `.php` extension (silently excluded from every run), and a stale assertion string - see the Phase 1/2a audit notes for detail.

### Changed

- `Services\AutoTranslationService` → split into `Providers\OpenRouterProvider` (HTTP transport, prompt construction, response parsing - the OpenRouter-specific implementation) and `Services\Translator` (a thin class that resolves the configured provider and delegates).
- Introduced `Exceptions\TranslationFailedException`, replacing the misused `Illuminate\Http\Exceptions\HttpResponseException`.
- The translation prompt now uses the chat API's `system`/`user` message roles instead of one concatenated string.
