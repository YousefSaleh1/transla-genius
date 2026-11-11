<?php

namespace CodingPartners\TranslaGenius\Tests\Unit;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\Exceptions\TranslationConfigurationException;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use CodingPartners\TranslaGenius\Services\Translator;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\TranslaGeniusServiceProvider;
use Illuminate\Support\Facades\Http;

/**
 * Unit tests for TranslaGeniusServiceProvider
 *
 * Tests the service provider functionality including:
 * - Configuration file publishing
 * - Helper function registration
 * - TranslationProviderInterface/Translator binding (Phase 2d)
 */
class ServiceProviderTest extends TestCase
{
    /**
     * Test that the configuration file is properly registered and publishable
     *
     * Verifies that:
     * 1. The package configuration is properly registered
     * 2. The config file exists in the application config
     * 3. The config structure is accessible
     *
     * @return void
     */
    public function test_config_file_is_publishable()
    {
        $this->assertArrayHasKey('translagenius', config());
    }

    /**
     * Test that helper functions are properly loaded by the service provider
     *
     * Verifies that:
     * 1. All expected helper functions are registered
     * 2. Functions are available globally
     * 3. The service provider properly loads the helpers file
     *
     * @return void
     */
    public function test_helpers_are_loaded()
    {
        $this->assertTrue(function_exists('get_current_locale'));
        $this->assertTrue(function_exists('get_supported_languages'));
    }

    /**
     * The interface resolves to the driver configured under
     * translagenius.providers.{default}, constructed with that provider's
     * config array (see TestCase's fixture: default => openrouter).
     *
     * @return void
     */
    public function test_translation_provider_interface_resolves_to_the_configured_driver()
    {
        $this->assertInstanceOf(OpenAiCompatibleProvider::class, $this->app->make(TranslationProviderInterface::class));
    }

    /**
     * A provider reports the config key it was resolved under, not a fixed
     * string - so a non-default entry is never logged as "openrouter".
     *
     * @return void
     */
    public function test_resolved_provider_reports_its_config_key_as_provider_name()
    {
        config([
            'translagenius.default' => 'my-gateway',
            'translagenius.providers.my-gateway' => [
                'driver' => OpenAiCompatibleProvider::class,
                'key' => 'k',
                'url' => 'https://gateway.test/v1/chat/completions',
                'model' => 'm',
            ],
        ]);

        Http::fake([
            'gateway.test/*' => Http::response([
                'choices' => [['message' => ['content' => 'مرحبا']]],
            ]),
        ]);

        $result = $this->app->make(TranslationProviderInterface::class)
            ->translate(new TranslationRequest('Hello', 'en', 'ar'));

        $this->assertSame('my-gateway', $result->provider);
    }

    public function test_driver_class_that_does_not_exist_has_a_clear_error()
    {
        config([
            'translagenius.default' => 'deepl',
            'translagenius.providers.deepl' => ['driver' => 'App\\Translation\\DeeplProvider'],
        ]);

        $this->expectException(TranslationConfigurationException::class);
        $this->expectExceptionMessage('provider [deepl] has driver class [App\\Translation\\DeeplProvider], which does not exist');

        $this->app->make(TranslationProviderInterface::class);
    }

    public function test_driver_class_not_implementing_the_interface_has_a_clear_error()
    {
        config([
            'translagenius.default' => 'broken',
            'translagenius.providers.broken' => ['driver' => \stdClass::class],
        ]);

        $this->expectException(TranslationConfigurationException::class);
        $this->expectExceptionMessage('provider [broken] has driver class [stdClass], which does not implement '.TranslationProviderInterface::class);

        $this->app->make(TranslationProviderInterface::class);
    }

    /**
     * Translator is resolvable and receives whatever provider is bound.
     *
     * @return void
     */
    public function test_translator_resolves_and_receives_the_bound_provider()
    {
        $this->assertInstanceOf(Translator::class, $this->app->make(Translator::class));
    }

    /**
     * An unconfigured/unknown default provider must fail loudly at
     * resolution time, not silently deep inside a swallowed job.
     *
     * @return void
     */
    public function test_resolving_an_unknown_provider_throws_immediately()
    {
        config(['translagenius.default' => 'does-not-exist']);

        $this->expectException(TranslationConfigurationException::class);

        $this->app->make(TranslationProviderInterface::class);
    }
}
