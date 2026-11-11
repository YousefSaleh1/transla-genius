<?php

namespace CodingPartners\TranslaGenius;

use CodingPartners\TranslaGenius\Console\Commands\BackfillCommand;
use CodingPartners\TranslaGenius\Console\Commands\CheckCommand;
use CodingPartners\TranslaGenius\Console\Commands\ReviewApproveAllCommand;
use CodingPartners\TranslaGenius\Console\Commands\ReviewListCommand;
use CodingPartners\TranslaGenius\Console\Commands\ReviewPruneCommand;
use CodingPartners\TranslaGenius\Contracts\LocaleResolverInterface;
use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\Events\TranslationCompleted;
use CodingPartners\TranslaGenius\Events\TranslationRequestBilled;
use CodingPartners\TranslaGenius\Exceptions\TranslationConfigurationException;
use CodingPartners\TranslaGenius\Listeners\LogTranslationUsage;
use CodingPartners\TranslaGenius\LocaleResolvers\DefaultLocaleResolver;
use CodingPartners\TranslaGenius\Review\ReviewManager;
use CodingPartners\TranslaGenius\Services\Translator;
use CodingPartners\TranslaGenius\Support\ConfiguredLocale;
use CodingPartners\TranslaGenius\Support\ProviderFactory;
use CodingPartners\TranslaGenius\Support\Setting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Support\ServiceProvider;

/**
 * Class TranslaGeniusServiceProvider
 *
 * Bootstraps the TranslaGenius package: merges config, publishes it, and
 * binds the configured TranslationProviderInterface driver, the
 * TranslatorInterface that resolves and delegates to it, and the
 * LocaleResolverInterface used throughout the package.
 */
class TranslaGeniusServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     *
     * @throws TranslationConfigurationException When TranslationProviderInterface is
     *                                           resolved and no provider is configured for
     *                                           translagenius.default (raised lazily, inside the singleton
     *                                           factory - not at register() call time).
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/config/translagenius.php',
            'translagenius'
        );

        // The app's configured locale, read when it's used (a tenant may set
        // it before each job), but never a value app()->setLocale() wrote.
        $this->app->instance(ConfiguredLocale::class, new ConfiguredLocale((string) $this->app->make('config')->get('app.locale', 'en')));

        // Built from the config as it is when it's used, never held from
        // boot: a worker serving several tenants changes config between
        // jobs. The factory reuses the stack while the config is unchanged.
        $this->app->singleton(ProviderFactory::class);
        $this->app->bind(TranslationProviderInterface::class, fn ($app) => $app->make(ProviderFactory::class)->current());

        // Resolves its provider when first used; see Translator::__construct().
        $this->app->singleton(Translator::class, fn () => new Translator);
        $this->app->singleton(TranslatorInterface::class, fn ($app) => $app->make(Translator::class));

        $this->app->singleton(LocaleResolverInterface::class, DefaultLocaleResolver::class);

        $this->app->singleton(ReviewManager::class);
    }

    /**
     * An integer setting read from env-backed config: null, '' (an empty
     * `VAR=` in .env) or a non-numeric value means "use the default". See
     * Support\Setting.
     *
     * @internal Package wiring; not covered by the backward compatibility promise.
     */
    public static function intSetting(mixed $value, int $default): int
    {
        return Setting::int($value, $default);
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
            __DIR__.'/config/translagenius.php' => config_path('translagenius.php'),
        ], 'translagenius-config');

        // Publish the usage-log migration - opt-in, same as the feature it
        // backs (translagenius.usage_tracking.enabled). Timestamped fresh
        // at publish time, like any other package-shipped migration stub.
        $this->publishes([
            __DIR__.'/database/migrations/create_translation_usage_logs_table.php.stub' => database_path(
                'migrations/'.date('Y_m_d_His').'_create_translation_usage_logs_table.php'
            ),
            // Only needed with the review workflow on (TRANSLATION_REVIEW).
            __DIR__.'/database/migrations/create_translation_reviews_table.php.stub' => database_path(
                'migrations/'.date('Y_m_d_His', time() + 1).'_create_translation_reviews_table.php'
            ),
        ], 'translagenius-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckCommand::class,
                BackfillCommand::class,
                ReviewListCommand::class,
                ReviewApproveAllCommand::class,
                ReviewPruneCommand::class,
            ]);
        }

        $this->registerUsageTrackingListener();

        $this->app->make('events')->listen(LocaleUpdated::class, function (LocaleUpdated $event) {
            $this->app->make(ConfiguredLocale::class)->localeWasSet((string) $event->locale, $event->previousLocale ?? null);
        });
    }

    /**
     * Register Listeners\LogTranslationUsage for Events\TranslationCompleted
     * and Events\TranslationRequestBilled. Always registered: the listener
     * checks translagenius.usage_tracking.enabled when it handles an event,
     * so tracking can be turned on at runtime, or per tenant. Off, it
     * returns before touching the database, so the table isn't needed.
     */
    protected function registerUsageTrackingListener(): void
    {
        $this->app->make('events')->listen(TranslationCompleted::class, LogTranslationUsage::class);
        $this->app->make('events')->listen(
            TranslationRequestBilled::class,
            [LogTranslationUsage::class, 'handleBilled']
        );
    }
}
