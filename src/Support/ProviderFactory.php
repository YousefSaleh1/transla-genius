<?php

namespace CodingPartners\TranslaGenius\Support;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\Exceptions\TranslationConfigurationException;
use CodingPartners\TranslaGenius\Providers\CachingProvider;
use CodingPartners\TranslaGenius\Providers\FallbackProvider;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use Illuminate\Contracts\Foundation\Application;

/**
 * Builds the provider stack (provider or fallback chain, then the cache
 * wrapper) from the config as it is when it's asked for.
 *
 * Nothing holds config from boot: a queue worker serving several tenants
 * changes config between jobs, and each job must go out with its own key,
 * URL, model, provider and cache setting. Building a stack is cheap but not
 * free, and a provider instance keeps its HTTP connection, so stacks are
 * memoized by a fingerprint of the config they were built from: the same
 * config gets the same instances back.
 *
 * @internal Support\ is package wiring; not covered by the backward compatibility promise.
 */
final class ProviderFactory
{
    /** Distinct configs kept at once (one per tenant, typically); the oldest goes first. */
    private const MAX_STACKS = 16;

    /** @var array<string, TranslationProviderInterface> fingerprint => stack */
    private array $stacks = [];

    public function __construct(protected Application $app) {}

    /**
     * The provider stack for the current config.
     *
     * @throws TranslationConfigurationException If the config can't build one.
     */
    public function current(): TranslationProviderInterface
    {
        $fingerprint = $this->fingerprint();

        if (isset($this->stacks[$fingerprint])) {
            return $this->stacks[$fingerprint];
        }

        $config = $this->app->make('config');
        $name = (string) $config->get('translagenius.default');
        $this->cacheProfiles = [];

        $provider = $this->resolveProvider($this->app, $name);
        $stack = $this->wrapWithCache($this->app, $provider, $this->cacheProfileFor($name));

        if (count($this->stacks) >= self::MAX_STACKS) {
            array_shift($this->stacks);
        }

        return $this->stacks[$fingerprint] = $stack;
    }

    /**
     * Everything a stack is built from: the provider entries and the
     * default, the package-wide prompt, batch, retry and cache settings, and
     * the cache store's name. Not the languages, source locale, queue,
     * review or usage settings, which don't change the stack.
     */
    public function fingerprint(): string
    {
        $config = $this->app->make('config');

        $relevant = [];
        foreach (['default', 'providers', 'prompt', 'batch', 'retries', 'cache'] as $key) {
            $relevant[$key] = $config->get("translagenius.{$key}");
        }

        return hash('sha256', serialize($relevant));
    }

    /**
     * Resolve a TranslationProviderInterface for one entry under
     * translagenius.providers, by name.
     *
     * A `driver` of FallbackProvider::class is handled specially: rather
     * than constructing it with the raw config array like any other
     * provider, its `chain` key (an array of other provider names) is
     * resolved recursively through this same method first, so
     * FallbackProvider itself stays a pure decorator over already-built
     * TranslationProviderInterface instances - see FallbackProvider's own
     * docblock. Every other driver is constructed the normal way:
     * `$app->make($driver, ['config' => $providerConfig])`, with the entry's
     * own key added as `name` so the provider can report which entry it is.
     *
     * @param  string  $name  Key under translagenius.providers.
     * @param  array<int, string>  $seen  Provider names already being
     *                                    resolved in this call chain, to detect a `chain` that
     *                                    (directly or indirectly) references itself.
     * @param  bool  $inChain  Whether $name is being resolved as a member of a
     *                         fallback chain. Chain members get translagenius.retries.fallback_http
     *                         HTTP retries and never sleep on Retry-After, so failover is fast.
     *
     * @throws TranslationConfigurationException If $name has no config, no `driver`, a
     *                                           `driver` class that doesn't exist or doesn't implement
     *                                           TranslationProviderInterface, an empty `chain` (for a
     *                                           FallbackProvider driver), or `chain` forms a cycle.
     */
    protected function resolveProvider(Application $app, string $name, array $seen = [], bool $inChain = false): TranslationProviderInterface
    {
        if (in_array($name, $seen, true)) {
            throw new TranslationConfigurationException(
                'TranslaGenius: provider chain forms a cycle: '
                .implode(' -> ', [...$seen, $name])
            );
        }

        $config = $app->make('config');
        $providerConfig = $config->get("translagenius.providers.{$name}");

        if (empty($providerConfig) || empty($providerConfig['driver'])) {
            throw new TranslationConfigurationException(
                "TranslaGenius: no provider configured for [{$name}]. "
                .'Check the `default` and `providers` keys in config/translagenius.php.'
            );
        }

        if ($providerConfig['driver'] === FallbackProvider::class) {
            $chain = $this->normalizeChain($providerConfig['chain'] ?? []);

            if (empty($chain)) {
                throw new TranslationConfigurationException(
                    "TranslaGenius: fallback provider [{$name}] has no `chain` configured. "
                    .'Set `chain` (or TRANSLATION_FALLBACK_CHAIN for the shipped `fallback` entry) '
                    .'to at least 2 other provider names from `providers`, e.g. "openai,gemini".'
                );
            }

            // Keyed by config name, so a failed chain names its members.
            $resolvedChain = [];
            foreach ($chain as $chainedName) {
                $resolvedChain[$chainedName] = $this->resolveProvider($app, $chainedName, [...$seen, $name], true);
            }

            $memberProfiles = array_map(fn (string $member) => $this->cacheProfiles[$member], $chain);
            $this->cacheProfiles[$name] = [
                'fingerprint' => ['fallback' => array_column($memberProfiles, 'fingerprint')],
                'uses_field' => in_array(true, array_column($memberProfiles, 'uses_field'), true),
                'uses_model' => in_array(true, array_column($memberProfiles, 'uses_model'), true),
                'primary' => $memberProfiles[0]['primary'],
                'is_chain' => true,
            ];

            return new FallbackProvider($resolvedChain);
        }

        $driver = $providerConfig['driver'];

        if (! is_string($driver) || ! class_exists($driver)) {
            throw new TranslationConfigurationException(sprintf(
                'TranslaGenius: provider [%s] has driver class [%s], which does not exist. '
                .'Check the class name and that it is autoloadable.',
                $name,
                is_string($driver) ? $driver : get_debug_type($driver)
            ));
        }

        if (! is_subclass_of($driver, TranslationProviderInterface::class)) {
            throw new TranslationConfigurationException(sprintf(
                'TranslaGenius: provider [%s] has driver class [%s], which does not implement %s.',
                $name,
                $driver,
                TranslationProviderInterface::class
            ));
        }

        $retries = (array) $config->get('translagenius.retries', []);
        $retryDefaults = [
            'http_retries' => Setting::int($retries['http'] ?? null, 1),
            'retry_after_max' => Setting::int($retries['http_retry_after_max'] ?? null, 10),
        ];

        // An entry's own non-empty `prompt` wins over the package-level one;
        // an empty value at either level falls through (to the provider's
        // built-in default if both are empty).
        $ownPrompt = trim((string) ($providerConfig['prompt'] ?? '')) !== '' ? $providerConfig['prompt'] : null;
        $providerConfig['prompt'] = $ownPrompt ?? $config->get('translagenius.prompt');

        // Same precedence for batching: an entry's own `batch` / `batch_prompt`
        // when set, otherwise translagenius.batch.enabled / .prompt.
        $ownBatch = $providerConfig['batch'] ?? null;
        $providerConfig['batch'] = ($ownBatch === null || $ownBatch === '')
            ? $config->get('translagenius.batch.enabled', false)
            : $ownBatch;
        $ownBatchPrompt = trim((string) ($providerConfig['batch_prompt'] ?? '')) !== '' ? $providerConfig['batch_prompt'] : null;
        $providerConfig['batch_prompt'] = $ownBatchPrompt ?? $config->get('translagenius.batch.prompt');
        $chainOverrides = $inChain
            ? ['http_retries' => Setting::int($retries['fallback_http'] ?? null, 0), 'retry_after_max' => 0]
            : [];

        $finalConfig = array_merge($retryDefaults, $providerConfig, $chainOverrides, ['name' => $name]);
        $this->cacheProfiles[$name] = $this->cacheProfile($driver, $finalConfig);

        return $app->make($driver, ['config' => $finalConfig]);
    }

    /**
     * Per resolved entry, what CachingProvider needs to key and store
     * results: see cacheProfile(). Filled by resolveProvider().
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $cacheProfiles = [];

    /**
     * The cache profile resolveProvider() recorded for $name.
     *
     * @return array<string, mixed>
     */
    protected function cacheProfileFor(string $name): array
    {
        return $this->cacheProfiles[$name];
    }

    /**
     * The output-affecting part of one provider entry, for the cache key.
     *
     * In: driver, name, url, model, temperature, reasoning_effort, body,
     * batch_response_format and the effective single/batch prompt templates
     * (the built-in defaults when unset, so an upgrade that changes them
     * invalidates too). Out: secrets and headers, timeouts and retries, token
     * limits (a truncated reply is never cached), the batch on/off switch
     * (single and batch results share a key per locale) and cost rates.
     * Values are normalized so "0.3" from .env and 0.3 from config agree.
     *
     * {field}/{model} are part of the key only if a template uses them; for a
     * driver other than OpenAiCompatibleProvider, whose prompts can't be
     * inspected, they always are.
     *
     * @param  array<string, mixed>  $config  The entry as passed to the driver.
     * @return array<string, mixed>
     */
    protected function cacheProfile(string $driver, array $config): array
    {
        $isOpenAiCompatible = is_a($driver, OpenAiCompatibleProvider::class, true);
        $prompt = self::normalize($config['prompt'] ?? null) ?? ($isOpenAiCompatible ? OpenAiCompatibleProvider::DEFAULT_PROMPT : null);
        $batchPrompt = self::normalize($config['batch_prompt'] ?? null) ?? ($isOpenAiCompatible ? OpenAiCompatibleProvider::DEFAULT_BATCH_PROMPT : null);
        $templates = $prompt."\n".$batchPrompt;

        $fingerprint = ['driver' => $driver, 'name' => $config['name']];
        foreach (['url', 'model', 'temperature', 'reasoning_effort', 'batch_response_format'] as $key) {
            $fingerprint[$key] = self::normalize($config[$key] ?? null);
        }
        $fingerprint['body'] = self::normalize((array) ($config['body'] ?? []));
        $fingerprint['prompt'] = $prompt;
        $fingerprint['batch_prompt'] = $batchPrompt;

        return [
            'fingerprint' => $fingerprint,
            'uses_field' => ! $isOpenAiCompatible || str_contains($templates, '{field}'),
            'uses_model' => ! $isOpenAiCompatible || str_contains($templates, '{model}'),
            'primary' => $config['name'],
            'is_chain' => false,
        ];
    }

    /**
     * Comparable form of a config value: '' and whitespace become null,
     * numeric strings become numbers, arrays are key-sorted recursively.
     */
    protected static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            ksort($value);

            return array_map(fn ($item) => self::normalize($item), $value);
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return null;
            }

            return is_numeric($value) ? (string) (float) $value : $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) (float) $value;
        }

        return $value;
    }

    /**
     * When translagenius.cache.enabled (TRANSLATION_CACHE) is on, wrap the
     * resolved provider - a single entry or a whole fallback chain - in a
     * CachingProvider. Off: returned as is, not wrapped at all.
     *
     * @param  array<string, mixed>  $profile  From cacheProfile() / the chain branch.
     */
    protected function wrapWithCache(Application $app, TranslationProviderInterface $provider, array $profile): TranslationProviderInterface
    {
        $settings = (array) $app->make('config')->get('translagenius.cache', []);
        $enabled = filter_var($settings['enabled'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;

        if (! $enabled) {
            return $provider;
        }

        $store = trim((string) ($settings['store'] ?? ''));
        $ttl = Setting::int($settings['ttl'] ?? null, 2592000);

        return new CachingProvider(
            inner: $provider,
            cache: $app->make('cache')->store($store === '' ? null : $store),
            ttl: $ttl === 0 ? null : $ttl,
            fingerprint: hash('sha256', CachingProvider::encode($profile['fingerprint'])),
            keyByField: $profile['uses_field'],
            keyByModel: $profile['uses_model'],
            cacheableProvider: $profile['is_chain'] ? $profile['primary'] : null,
        );
    }

    /**
     * Normalize a fallback `chain` to a list of provider names. Accepts an
     * array or a comma-separated string (as read from
     * TRANSLATION_FALLBACK_CHAIN); trims each name and drops empty items, so
     * " openai, ,gemini " becomes ['openai', 'gemini'].
     *
     * @param  array<int, string>|string|null  $chain
     * @return array<int, string>
     */
    protected function normalizeChain(array|string|null $chain): array
    {
        $names = is_array($chain) ? $chain : explode(',', (string) $chain);

        return array_values(array_filter(
            array_map(fn ($name) => trim((string) $name), $names),
            fn (string $name) => $name !== ''
        ));
    }
}
