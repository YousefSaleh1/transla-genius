<?php

namespace CodingPartners\TranslaGenius\Providers;

use CodingPartners\TranslaGenius\Contracts\BatchTranslationProviderInterface;
use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationBatchRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationBatchResult;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;
use Illuminate\Contracts\Cache\Repository;

/**
 * Decorator that serves repeated translations from Laravel's cache, so the
 * same source text translated with the same setup is paid for once, across
 * every record and model.
 *
 * Wrapped around the resolved provider automatically when
 * TRANSLATION_CACHE=true (see TranslaGeniusServiceProvider). The key covers
 * the text, both locales, the provider name and a fingerprint of everything
 * in its configuration that can change the output; see cacheKey().
 *
 * Only successful results are stored - never a failure, a truncated reply,
 * or an invalid batch value - and, behind a fallback chain, only results the
 * chain's primary produced (see shouldStore()).
 */
class CachingProvider implements BatchTranslationProviderInterface
{
    /** Bumped whenever the key format changes, so old entries simply miss. */
    public const KEY_VERSION = 'v1';

    public const KEY_PREFIX = 'translagenius';

    /**
     * @param  TranslationProviderInterface  $inner  The provider being cached.
     * @param  Repository  $cache  The cache store to use.
     * @param  int|null  $ttl  Seconds to keep an entry; null keeps it forever.
     * @param  string  $fingerprint  Stable hash of the output-affecting setup.
     * @param  bool  $keyByField  Include the field name in the key (when a prompt uses {field}).
     * @param  bool  $keyByModel  Include the model name in the key (when a prompt uses {model}).
     * @param  string|null  $cacheableProvider  Only results whose `provider` equals this are
     *                                          stored (the chain's primary); null stores results from any provider.
     */
    public function __construct(
        protected TranslationProviderInterface $inner,
        protected Repository $cache,
        protected ?int $ttl,
        protected string $fingerprint,
        protected bool $keyByField,
        protected bool $keyByModel,
        protected ?string $cacheableProvider = null,
    ) {}

    public function inner(): TranslationProviderInterface
    {
        return $this->inner;
    }

    public function translate(TranslationRequest $request): TranslationResult
    {
        $key = $this->cacheKey($request->text, $request->sourceLocale, $request->targetLocale, $request->field, $request->model);

        if (($hit = $this->lookup($key)) !== null) {
            return new TranslationResult(text: $hit['text'], provider: $hit['provider'], cached: true);
        }

        $result = $this->inner->translate($request);
        $this->store($key, $result->text, $result->provider);

        return $result;
    }

    public function supportsBatching(): bool
    {
        return $this->inner instanceof BatchTranslationProviderInterface && $this->inner->supportsBatching();
    }

    /**
     * Serve every cached locale, and send the rest to the inner provider as
     * a batch when two or more miss. A single miss is left out of the
     * result, so the caller requests it on its own through translate() (one
     * request, through the normal single-locale path). A fully cached field
     * makes no request at all.
     */
    public function translateBatch(TranslationBatchRequest $request): TranslationBatchResult
    {
        $translations = [];
        $cachedLocales = [];
        $providers = [];
        $keys = [];
        $misses = [];

        foreach ($request->targetLocales as $locale) {
            $keys[$locale] = $this->cacheKey($request->text, $request->sourceLocale, $locale, $request->field, $request->model);

            if (($hit = $this->lookup($keys[$locale])) !== null) {
                $translations[$locale] = $hit['text'];
                $cachedLocales[] = $locale;
                $providers[] = $hit['provider'];
            } else {
                $misses[] = $locale;
            }
        }

        if (count($misses) < 2 || ! $this->supportsBatching()) {
            return new TranslationBatchResult(
                translations: $translations,
                provider: $providers[0] ?? null,
                cachedLocales: $cachedLocales,
            );
        }

        $fresh = $this->inner->translateBatch(new TranslationBatchRequest(
            $request->text,
            $request->sourceLocale,
            $misses,
            $request->field,
            $request->model,
        ));

        foreach ($fresh->translations as $locale => $text) {
            if (isset($keys[$locale])) {
                $translations[$locale] = $text;
                $this->store($keys[$locale], $text, $fresh->provider);
            }
        }

        // Keep the requested order, so the first produced pair is predictable.
        $ordered = [];
        foreach ($request->targetLocales as $locale) {
            if (isset($translations[$locale])) {
                $ordered[$locale] = $translations[$locale];
            }
        }

        return new TranslationBatchResult(
            translations: $ordered,
            provider: $fresh->provider ?? ($providers[0] ?? null),
            promptTokens: $fresh->promptTokens,
            completionTokens: $fresh->completionTokens,
            requestId: $fresh->requestId,
            truncated: $fresh->truncated,
            cachedLocales: $cachedLocales,
        );
    }

    /**
     * translagenius:v1:<sha256>. Field/model context is part of the hash only
     * when the active prompt templates use {field}/{model} (or the provider
     * isn't one whose prompts we can inspect), so records across models
     * share hits under the default prompts.
     */
    public function cacheKey(string $text, string $sourceLocale, string $targetLocale, ?string $field = null, ?string $model = null): string
    {
        $parts = [
            'setup' => $this->fingerprint,
            'source' => $sourceLocale,
            'target' => $targetLocale,
            'text' => $text,
        ];

        if ($this->keyByField) {
            $parts['field'] = (string) $field;
        }

        if ($this->keyByModel) {
            $parts['model'] = (string) $model;
        }

        return self::KEY_PREFIX.':'.self::KEY_VERSION.':'.hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{text: string, provider: string|null}|null
     */
    protected function lookup(string $key): ?array
    {
        $value = $this->cache->get($key);

        return is_array($value) && is_string($value['text'] ?? null) && trim($value['text']) !== ''
            ? ['text' => $value['text'], 'provider' => $value['provider'] ?? null]
            : null;
    }

    protected function store(string $key, string $text, ?string $provider): void
    {
        if (! $this->shouldStore($text, $provider)) {
            return;
        }

        $value = ['text' => $text, 'provider' => $provider];

        $this->ttl === null
            ? $this->cache->forever($key, $value)
            : $this->cache->put($key, $value, $this->ttl);
    }

    /**
     * Never an empty value, and behind a fallback chain only what the primary
     * produced: a result a secondary served during an outage isn't cached, so
     * once the primary recovers, the next identical text gets its output.
     */
    protected function shouldStore(string $text, ?string $provider): bool
    {
        if (trim($text) === '') {
            return false;
        }

        return $this->cacheableProvider === null || $provider === $this->cacheableProvider;
    }

    /**
     * Remove the entry for one text/locale pair (the review workflow does this
     * when a translation is rejected).
     */
    public function forgetTranslation(string $text, string $sourceLocale, string $targetLocale, ?string $field = null, ?string $model = null): void
    {
        $this->cache->forget($this->cacheKey($text, $sourceLocale, $targetLocale, $field, $model));
    }

    /**
     * Store a reviewer-approved edit as the entry for one text/locale pair, so
     * the next identical source text gets the corrected version. It's human
     * approved, so it is stored whichever provider produced the original.
     */
    public function storeReviewed(string $text, string $sourceLocale, string $targetLocale, ?string $field, ?string $model, string $translation, ?string $provider): void
    {
        if (trim($translation) === '') {
            return;
        }

        $key = $this->cacheKey($text, $sourceLocale, $targetLocale, $field, $model);
        $value = ['text' => $translation, 'provider' => $provider];

        $this->ttl === null ? $this->cache->forever($key, $value) : $this->cache->put($key, $value, $this->ttl);
    }
}
