<?php

namespace CodingPartners\TranslaGenius;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\Services\Translator;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Class TranslaGeniusServiceProvider
 *
 * Bootstraps the TranslaGenius package: merges config, publishes it, and
 * binds the configured TranslationProviderInterface driver plus the
 * Translator that resolves and delegates to it.
 *
 * @package CodingPartners\TranslaGenius
 */
class TranslaGeniusServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/translaGenius.php',
            'translaGenius'
        );

        $this->app->singleton(TranslationProviderInterface::class, function ($app) {
            $config = $app->make('config');
            $name = $config->get('translaGenius.default');
            $providerConfig = $config->get("translaGenius.providers.{$name}");

            if (empty($providerConfig) || empty($providerConfig['driver'])) {
                throw new RuntimeException(
                    "TranslaGenius: no provider configured for [{$name}]. "
                    . 'Check the `default` and `providers` keys in config/translaGenius.php.'
                );
            }

            return $app->make($providerConfig['driver'], ['config' => $providerConfig]);
        });

        $this->app->singleton(Translator::class);
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
    }
}
