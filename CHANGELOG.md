# Changelog

All notable changes to `coding-partners/transla-genius` are documented here.

## Unreleased

A breaking architectural release: the translation backend is now swappable behind an interface, translation payloads are typed DTOs instead of raw arrays/strings, and persistence is a separate, narrowly-scoped class instead of being mixed into the queue job.

### Breaking changes

- **Config shape changed.** The flat `api.*` / `settings.*` keys are gone. Config is now provider-keyed:
  ```php
  // Before (1.x)
  'api' => ['key' => ..., 'url' => ..., 'model' => ...],
  'settings' => ['temperature' => ..., 'max_tokens' => ...],

  // After (2.0)
  'default' => 'openrouter',
  'providers' => [
      'openrouter' => ['driver' => OpenRouterProvider::class, 'key' => ..., 'url' => ..., 'model' => ..., 'timeout' => ..., 'temperature' => ..., 'max_tokens' => ...],
  ],
  ```
  Republish the config file (`php artisan vendor:publish --tag=translagenius-config --force`) and migrate any custom values you'd set.
- **`Services\AutoTranslationService` is removed.** If you depended on it directly (type-hinting it, resolving it from the container), depend on `Services\Translator` instead - `Translator::translate($text, $source, $target)` now returns a `DTOs\TranslationResult` (with a `->text` property) instead of a bare string.
- **`TranslateFields::handle()`'s signature changed** from `handle(AutoTranslationService $translationService)` to `handle(Translator $translator, TranslationWriter $writer)`. If you extended or directly invoked this job's `handle()` method, update the call site.
- `Traits\Translatable::scopeFullyTranslated()`'s underlying SQL changed (now driver-agnostic `whereNotNull('field->locale')` instead of MySQL-only raw `JSON_EXTRACT`/`JSON_UNQUOTE`) - behavior is equivalent, but if you depended on the exact generated SQL for any reason, it's different now.

### Added

- `Contracts\TranslationProviderInterface` - the extension point for swapping translation backends. Implement it and register it under `translaGenius.providers` to use DeepL, Google Translate, another LLM, or an internal service - no changes to the job or trait required. See the README's "Custom Translation Providers" section.
- `DTOs\TranslationRequest` / `DTOs\TranslationResult` - typed, `readonly` value objects replacing the raw strings/arrays previously threaded through the job.
- `Services\TranslationWriter` - the locked read-check-write step (introduced in 2.0-dev as part of `TranslateFields`) is now its own small, job-independent class.
- `Facades\TranslaGenius` - convenience facade over `Translator` for translating arbitrary text outside the automatic model-save flow.
- `translaGenius.api.timeout` is now actually applied to the HTTP client (it was defined in config but silently ignored before).

### Changed

- `Services\AutoTranslationService` → split into `Providers\OpenRouterProvider` (HTTP transport, prompt construction, response parsing - the OpenRouter-specific implementation) and `Services\Translator` (a thin class that resolves the configured provider and delegates).

---

## [Unreleased, pre-2.0] - Security & Reliability

Changes made in the lead-up to 2.0 that don't themselves break the public API:

- `TranslateFields` now has explicit `$tries`/`backoff()` and a `failed()` handler; translation failures propagate to the queue's native retry/failure handling instead of being silently swallowed.
- Introduced `Exceptions\TranslationFailedException`, replacing the misused `Illuminate\Http\Exceptions\HttpResponseException`.
- Added pessimistic locking (`lockForUpdate()`) around the job's write step to prevent a concurrent update's translation from being overwritten by a stale one.
- Stopped logging raw stack traces (a plausible path for the API key to end up in application logs via HTTP-client exception trace arguments).
- The translation prompt now uses the chat API's `system`/`user` message roles instead of one concatenated string.
- Added an opt-in `shouldTranslateField()` hook models can define to filter which field content is sent for translation.
- Fixed test-suite defects: a config-shape mismatch between the test fixture and production code, a test file missing its `.php` extension (silently excluded from every run), and a stale assertion string - see the Phase 1/2a audit notes for detail.
