<?php

namespace CodingPartners\TranslaGenius;

use CodingPartners\TranslaGenius\Console\Commands\BackfillCommand;
use CodingPartners\TranslaGenius\Console\Commands\CheckCommand;
use CodingPartners\TranslaGenius\Contracts\LocaleResolverInterface;
use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
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
     * `$app->make($driver, ['config' => $providerConfig])`.
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
            $chain = $providerConfig['chain'] ?? [];

            if (empty($chain)) {
                throw new RuntimeException(
                    "TranslaGenius: fallback provider [{$name}] has no `chain` configured. "
                    . 'Set `chain` to an array of at least 2 other provider names from `providers`.'
                );
            }

            $resolvedChain = array_map(
                fn (string $chainedName) => $this->resolveProvider($app, $chainedName, [...$seen, $name]),
                $chain
            );

            return new FallbackProvider($resolvedChain);
        }

        return $app->make($providerConfig['driver'], ['config' => $providerConfig]);
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

        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckCommand::class,
                BackfillCommand::class,
            ]);
        }
    }
}
