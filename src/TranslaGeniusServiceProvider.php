<?php

namespace CodingPartners\TranslaGenius;

use CodingPartners\TranslaGenius\Console\Commands\BackfillCommand;
use CodingPartners\TranslaGenius\Console\Commands\CheckCommand;
use CodingPartners\TranslaGenius\Contracts\LocaleResolverInterface;
use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\Events\TranslationCompleted;
use CodingPartners\TranslaGenius\Listeners\LogTranslationUsage;
use CodingPartners\TranslaGenius\LocaleResolvers\DefaultLocaleResolver;
use CodingPartners\TranslaGenius\Providers\CachingProvider;
use CodingPartners\TranslaGenius\Providers\FallbackProvider;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use CodingPartners\TranslaGenius\Services\Translator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Class TranslaGeniusServiceProvider
 *
 * Bootstraps the TranslaGenius package: merges config, publishes it, and
 * binds the configured TranslationProviderInterface driver, the
 * TranslatorInterface that resolves and delegates to it, and the
 * LocaleResolverInterface used throughout the package.
 *
 * @package CodingPartners\TranslaGenius
 */
class TranslaGeniusServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     *
     * @throws \RuntimeException When TranslationProviderInterface is
     *         resolved and no provider is configured for
     *         translaGenius.default (raised lazily, inside the singleton
     *         factory - not at register() call time).
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/translaGenius.php',
            'translaGenius'
        );

        // The app's configured locale, captured before anything can change
        // it: app()->setLocale() also rewrites config('app.locale'), so a
        // long-running process (a queue worker) can't rely on reading it later.
        $configuredLocale = (string) $this->app['config']->get('app.locale', 'en');
        $this->app->instance('translagenius.configured_locale', $configuredLocale);

        $this->app->singleton(TranslationProviderInterface::class, function ($app) {
            $name = $app->make('config')->get('translaGenius.default');
            $this->cacheProfiles = [];

            $provider = $this->resolveProvider($app, $name);

            return $this->wrapWithCache($app, $provider, $this->cacheProfiles[$name]);
        });

        $this->app->singleton(Translator::class);
        $this->app->singleton(TranslatorInterface::class, fn ($app) => $app->make(Translator::class));

        $this->app->singleton(LocaleResolverInterface::class, DefaultLocaleResolver::class);
    }

    /**
     * Resolve a TranslationProviderInterface for one entry under
     * translaGenius.providers, by name.
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
     * @param Application $app
     * @param string $name Key under translaGenius.providers.
     * @param array<int, string> $seen Provider names already being
     *        resolved in this call chain, to detect a `chain` that
     *        (directly or indirectly) references itself.
     * @param bool $inChain Whether $name is being resolved as a member of a
     *        fallback chain. Chain members get translaGenius.retries.fallback_http
     *        HTTP retries and never sleep on Retry-After, so failover is fast.
     * @return TranslationProviderInterface
     *
     * @throws RuntimeException If $name has no config, no `driver`, a
     *         `driver` class that doesn't exist or doesn't implement
     *         TranslationProviderInterface, an empty `chain` (for a
     *         FallbackProvider driver), or `chain` forms a cycle.
     */
    protected function resolveProvider(Application $app, string $name, array $seen = [], bool $inChain = false): TranslationProviderInterface
    {
        if (in_array($name, $seen, true)) {
            throw new RuntimeException(
                'TranslaGenius: provider chain forms a cycle: '
                . implode(' -> ', [...$seen, $name])
            );
        }

        $config = $app->make('config');
        $providerConfig = $config->get("translaGenius.providers.{$name}");

        if (empty($providerConfig) || empty($providerConfig['driver'])) {
            throw new RuntimeException(
                "TranslaGenius: no provider configured for [{$name}]. "
                . 'Check the `default` and `providers` keys in config/translaGenius.php.'
            );
        }

        if ($providerConfig['driver'] === FallbackProvider::class) {
            $chain = $this->normalizeChain($providerConfig['chain'] ?? []);

            if (empty($chain)) {
                throw new RuntimeException(
                    "TranslaGenius: fallback provider [{$name}] has no `chain` configured. "
                    . 'Set `chain` (or TRANSLATION_FALLBACK_CHAIN for the shipped `fallback` entry) '
                    . 'to at least 2 other provider names from `providers`, e.g. "openai,gemini".'
                );
            }

            $resolvedChain = array_map(
                fn (string $chainedName) => $this->resolveProvider($app, $chainedName, [...$seen, $name], true),
                $chain
            );

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

        if (!is_string($driver) || !class_exists($driver)) {
            throw new RuntimeException(sprintf(
                'TranslaGenius: provider [%s] has driver class [%s], which does not exist. '
                . 'Check the class name and that it is autoloadable.',
                $name,
                is_string($driver) ? $driver : get_debug_type($driver)
            ));
        }

        if (!is_subclass_of($driver, TranslationProviderInterface::class)) {
            throw new RuntimeException(sprintf(
                'TranslaGenius: provider [%s] has driver class [%s], which does not implement %s.',
                $name,
                $driver,
                TranslationProviderInterface::class
            ));
        }

        $retries = (array) $config->get('translaGenius.retries', []);
        $retryDefaults = [
            'http_retries' => self::intSetting($retries['http'] ?? null, 1),
            'retry_after_max' => self::intSetting($retries['http_retry_after_max'] ?? null, 10),
        ];

        // An entry's own non-empty `prompt` wins over the package-level one;
        // an empty value at either level falls through (to the provider's
        // built-in default if both are empty).
        $ownPrompt = trim((string) ($providerConfig['prompt'] ?? '')) !== '' ? $providerConfig['prompt'] : null;
        $providerConfig['prompt'] = $ownPrompt ?? $config->get('translaGenius.prompt');

        // Same precedence for batching: an entry's own `batch` / `batch_prompt`
        // when set, otherwise translaGenius.batch.enabled / .prompt.
        $ownBatch = $providerConfig['batch'] ?? null;
        $providerConfig['batch'] = ($ownBatch === null || $ownBatch === '')
            ? $config->get('translaGenius.batch.enabled', false)
            : $ownBatch;
        $ownBatchPrompt = trim((string) ($providerConfig['batch_prompt'] ?? '')) !== '' ? $providerConfig['batch_prompt'] : null;
        $providerConfig['batch_prompt'] = $ownBatchPrompt ?? $config->get('translaGenius.batch.prompt');
        $chainOverrides = $inChain
            ? ['http_retries' => self::intSetting($retries['fallback_http'] ?? null, 0), 'retry_after_max' => 0]
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
     * @param string $driver
     * @param array<string, mixed> $config The entry as passed to the driver.
     * @return array<string, mixed>
     */
    protected function cacheProfile(string $driver, array $config): array
    {
        $isOpenAiCompatible = is_a($driver, OpenAiCompatibleProvider::class, true);
        $prompt = self::normalize($config['prompt'] ?? null) ?? ($isOpenAiCompatible ? OpenAiCompatibleProvider::DEFAULT_PROMPT : null);
        $batchPrompt = self::normalize($config['batch_prompt'] ?? null) ?? ($isOpenAiCompatible ? OpenAiCompatibleProvider::DEFAULT_BATCH_PROMPT : null);
        $templates = $prompt . "\n" . $batchPrompt;

        $fingerprint = ['driver' => $driver, 'name' => $config['name']];
        foreach (['url', 'model', 'temperature', 'reasoning_effort', 'batch_response_format'] as $key) {
            $fingerprint[$key] = self::normalize($config[$key] ?? null);
        }
        $fingerprint['body'] = self::normalize((array) ($config['body'] ?? []));
        $fingerprint['prompt'] = $prompt;
        $fingerprint['batch_prompt'] = $batchPrompt;

        return [
            'fingerprint' => $fingerprint,
            'uses_field' => !$isOpenAiCompatible || str_contains($templates, '{field}'),
            'uses_model' => !$isOpenAiCompatible || str_contains($templates, '{model}'),
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
     * When translaGenius.cache.enabled (TRANSLATION_CACHE) is on, wrap the
     * resolved provider - a single entry or a whole fallback chain - in a
     * CachingProvider. Off: returned as is, not wrapped at all.
     *
     * @param Application $app
     * @param TranslationProviderInterface $provider
     * @param array<string, mixed> $profile From cacheProfile() / the chain branch.
     * @return TranslationProviderInterface
     */
    protected function wrapWithCache(Application $app, TranslationProviderInterface $provider, array $profile): TranslationProviderInterface
    {
        $settings = (array) $app->make('config')->get('translaGenius.cache', []);
        $enabled = filter_var($settings['enabled'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;

        if (!$enabled) {
            return $provider;
        }

        $store = trim((string) ($settings['store'] ?? ''));
        $ttl = self::intSetting($settings['ttl'] ?? null, 2592000);

        return new CachingProvider(
            inner: $provider,
            cache: $app->make('cache')->store($store === '' ? null : $store),
            ttl: $ttl === 0 ? null : $ttl,
            fingerprint: hash('sha256', json_encode($profile['fingerprint'], JSON_UNESCAPED_UNICODE)),
            keyByField: $profile['uses_field'],
            keyByModel: $profile['uses_model'],
            cacheableProvider: $profile['is_chain'] ? $profile['primary'] : null,
        );
    }

    /**
     * An integer setting read from env-backed config: null or '' (an empty
     * `VAR=` in .env) means "use the default".
     *
     * @param mixed $value
     * @param int $default
     * @return int
     */
    public static function intSetting(mixed $value, int $default): int
    {
        return $value === null || $value === '' ? $default : max(0, (int) $value);
    }

    /**
     * Normalize a fallback `chain` to a list of provider names. Accepts an
     * array or a comma-separated string (as read from
     * TRANSLATION_FALLBACK_CHAIN); trims each name and drops empty items, so
     * " openai, ,gemini " becomes ['openai', 'gemini'].
     *
     * @param array<int, string>|string|null $chain
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

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Publish the configuration file to the application's configuration directory.
        $this->publishes([
            __DIR__ . '/config/translaGenius.php' => config_path('translaGenius.php'),
        ], 'translagenius-config');

        // Publish the usage-log migration - opt-in, same as the feature it
        // backs (translaGenius.usage_tracking.enabled). Timestamped fresh
        // at publish time, like any other package-shipped migration stub.
        $this->publishes([
            __DIR__ . '/database/migrations/create_translation_usage_logs_table.php.stub' => database_path(
                'migrations/' . date('Y_m_d_His') . '_create_translation_usage_logs_table.php'
            ),
        ], 'translagenius-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckCommand::class,
                BackfillCommand::class,
            ]);
        }

        $this->registerUsageTrackingListener();
    }

    /**
     * Register Listeners\LogTranslationUsage against Events\TranslationCompleted
     * - but only when translaGenius.usage_tracking.enabled is true. This
     * check happens here, once, at registration time - not inside the
     * listener itself - so that when the feature is off, the listener is
     * never wired up at all (no listener registered, nothing runs, no
     * query against a table that may not even exist), not merely "runs but
     * writes nothing."
     *
     * @return void
     */
    protected function registerUsageTrackingListener(): void
    {
        if (!$this->app['config']->get('translaGenius.usage_tracking.enabled', false)) {
            return;
        }

        $this->app['events']->listen(TranslationCompleted::class, LogTranslationUsage::class);
        $this->app['events']->listen(
            \CodingPartners\TranslaGenius\Events\TranslationRequestBilled::class,
            [LogTranslationUsage::class, 'handleBilled']
        );
    }
}
