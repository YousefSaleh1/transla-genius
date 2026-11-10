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
use CodingPartners\TranslaGenius\Providers\FallbackProvider;
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

        $this->app->singleton(TranslationProviderInterface::class, function ($app) {
            $name = $app->make('config')->get('translaGenius.default');

            return $this->resolveProvider($app, $name);
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
     * @return TranslationProviderInterface
     *
     * @throws RuntimeException If $name has no config, no `driver`, an
     *         empty `chain` (for a FallbackProvider driver), or `chain`
     *         forms a cycle.
     */
    protected function resolveProvider(Application $app, string $name, array $seen = []): TranslationProviderInterface
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
                fn (string $chainedName) => $this->resolveProvider($app, $chainedName, [...$seen, $name]),
                $chain
            );

            return new FallbackProvider($resolvedChain);
        }

        return $app->make($providerConfig['driver'], [
            'config' => array_merge($providerConfig, ['name' => $name]),
        ]);
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
    }
}
