<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\Exceptions\AllProvidersFailedException;
use CodingPartners\TranslaGenius\Exceptions\TranslationConfigurationException;
use CodingPartners\TranslaGenius\Providers\FallbackProvider;
use CodingPartners\TranslaGenius\Tests\Support\FakeConfigurableProvider;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use CodingPartners\TranslaGenius\TranslaGeniusServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Phase 4c: proves FallbackProvider's chain-of-providers behavior directly
 * (real fakes chained together, driven through actual translate() calls -
 * not mocks), and separately proves it's reachable through the normal
 * config-driven `providers.{name}.driver` resolution
 * (TranslaGeniusServiceProvider::resolveProvider()) rather than needing
 * special-case wiring outside that path - same standard as
 * ProviderSwapTest/LifecycleEventsTest.
 */
class FallbackProviderTest extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        FakeConfigurableProvider::reset();

        Config::set('translagenius.supported_languages', ['en', 'ar', 'es']);

        $migration = include __DIR__.'/../database/migrations/2024_01_01_000000_create_test_tables.php';
        $migration->up();
    }

    protected function tearDown(): void
    {
        FakeConfigurableProvider::reset();

        Schema::dropIfExists('test_translatable_models');

        parent::tearDown();
    }

    public function test_primary_success_means_secondary_is_never_invoked()
    {
        $primary = new FakeConfigurableProvider(['id' => 'primary', 'prefix' => 'P:']);
        $secondary = new FakeConfigurableProvider(['id' => 'secondary', 'prefix' => 'S:']);

        $chain = new FallbackProvider([$primary, $secondary]);

        $result = $chain->translate(new TranslationRequest('Hello', 'en', 'ar'));

        $this->assertSame('P:Hello:ar', $result->text);
        $this->assertSame(1, FakeConfigurableProvider::callCount('primary'));
        $this->assertSame(0, FakeConfigurableProvider::callCount('secondary'));
    }

    public function test_primary_retryable_failure_falls_through_to_secondary()
    {
        $this->assertFallsThroughToSecondaryOnPrimaryFailure(retryable: true);
    }

    public function test_primary_non_retryable_failure_still_falls_through_to_secondary()
    {
        // The design point of this class: a non-retryable failure (e.g. a
        // bad API key) only means retrying the SAME provider won't help -
        // it says nothing about a different provider with different
        // credentials, so the chain still falls through to it.
        $this->assertFallsThroughToSecondaryOnPrimaryFailure(retryable: false);
    }

    private function assertFallsThroughToSecondaryOnPrimaryFailure(bool $retryable): void
    {
        $primary = new FakeConfigurableProvider(['id' => 'primary', 'fail' => true, 'retryable' => $retryable]);
        $secondary = new FakeConfigurableProvider(['id' => 'secondary', 'prefix' => 'S:']);

        $chain = new FallbackProvider([$primary, $secondary]);

        $result = $chain->translate(new TranslationRequest('Hello', 'en', 'ar'));

        $this->assertSame('S:Hello:ar', $result->text);
        $this->assertSame(1, FakeConfigurableProvider::callCount('primary'));
        $this->assertSame(1, FakeConfigurableProvider::callCount('secondary'));
    }

    public function test_all_providers_failing_throws_aggregate_exception_with_every_failure()
    {
        $primary = new FakeConfigurableProvider([
            'id' => 'primary',
            'fail' => true,
            'retryable' => false,
            'status_code' => 401,
        ]);
        $secondary = new FakeConfigurableProvider([
            'id' => 'secondary',
            'fail' => true,
            'retryable' => true,
            'status_code' => 500,
        ]);

        $chain = new FallbackProvider([$primary, $secondary]);

        try {
            $chain->translate(new TranslationRequest('Hello', 'en', 'ar'));
            $this->fail('Expected AllProvidersFailedException to be thrown.');
        } catch (AllProvidersFailedException $e) {
            $failures = $e->getFailures();

            $this->assertCount(2, $failures);

            $this->assertSame(FakeConfigurableProvider::class, $failures[0]['provider']);
            $this->assertSame('primary failed', $failures[0]['message']);
            $this->assertSame(401, $failures[0]['status_code']);
            $this->assertFalse($failures[0]['retryable']);

            $this->assertSame(FakeConfigurableProvider::class, $failures[1]['provider']);
            $this->assertSame('secondary failed', $failures[1]['message']);
            $this->assertSame(500, $failures[1]['status_code']);
            $this->assertTrue($failures[1]['retryable']);

            // Secondary's failure was retryable, so retrying the whole
            // chain again is worth it even though primary's wasn't.
            $this->assertTrue($e->isRetryable());
        }
    }

    public function test_aggregate_exception_is_not_retryable_when_every_failure_is_non_retryable()
    {
        $primary = new FakeConfigurableProvider(['id' => 'primary', 'fail' => true, 'retryable' => false]);
        $secondary = new FakeConfigurableProvider(['id' => 'secondary', 'fail' => true, 'retryable' => false]);

        $chain = new FallbackProvider([$primary, $secondary]);

        try {
            $chain->translate(new TranslationRequest('Hello', 'en', 'ar'));
            $this->fail('Expected AllProvidersFailedException to be thrown.');
        } catch (AllProvidersFailedException $e) {
            $this->assertFalse($e->isRetryable());
        }
    }

    public function test_constructing_with_fewer_than_two_providers_is_rejected()
    {
        $this->expectException(TranslationConfigurationException::class);

        new FallbackProvider([new FakeConfigurableProvider(['id' => 'only'])]);
    }

    /**
     * Full config-driven wiring: `providers.fallback.driver` =
     * FallbackProvider::class with a `chain` of other provider names,
     * resolved through TranslaGeniusServiceProvider - not constructed
     * directly anywhere in this test. Driven through the real automatic
     * model-save flow (Translatable trait -> TranslateFields -> Translator
     * -> the config-resolved FallbackProvider -> its config-resolved
     * chain), same as ProviderSwapTest.
     */
    public function test_chain_config_key_is_resolved_through_the_service_provider()
    {
        Config::set('translagenius.default', 'fallback');
        Config::set('translagenius.providers.primary', [
            'driver' => FakeConfigurableProvider::class,
            'id' => 'cfg-primary',
            'fail' => true,
            'retryable' => false,
            'status_code' => 401,
        ]);
        Config::set('translagenius.providers.secondary', [
            'driver' => FakeConfigurableProvider::class,
            'id' => 'cfg-secondary',
            'prefix' => 'CFG-S:',
        ]);
        Config::set('translagenius.providers.fallback', [
            'driver' => FallbackProvider::class,
            'chain' => ['primary', 'secondary'],
        ]);

        // Force re-resolution against the config just set, rather than
        // whatever the package's own container binding already resolved
        // during app boot.
        $this->app->forgetInstance(TranslationProviderInterface::class);

        $model = TestTranslatableModel::create([
            'name' => ['en' => 'Hello'],
            'description' => ['en' => 'World'],
        ]);

        $model->refresh();

        $this->assertSame('CFG-S:Hello:ar', $model->getTranslation('name', 'ar', false));
        $this->assertGreaterThan(0, FakeConfigurableProvider::callCount('cfg-primary'));
        $this->assertGreaterThan(0, FakeConfigurableProvider::callCount('cfg-secondary'));
    }

    public function test_fallback_driver_with_no_chain_configured_throws_a_clear_error()
    {
        Config::set('translagenius.default', 'fallback');
        Config::set('translagenius.providers.fallback', [
            'driver' => FallbackProvider::class,
        ]);

        $this->app->forgetInstance(TranslationProviderInterface::class);

        $this->expectException(TranslationConfigurationException::class);
        $this->expectExceptionMessage('no `chain` configured');

        $this->app->make(TranslationProviderInterface::class);
    }

    public function test_fallback_chain_referencing_itself_is_rejected_as_a_cycle()
    {
        Config::set('translagenius.default', 'fallback');
        Config::set('translagenius.providers.fallback', [
            'driver' => FallbackProvider::class,
            'chain' => ['fallback', 'fallback'],
        ]);

        $this->app->forgetInstance(TranslationProviderInterface::class);

        $this->expectException(TranslationConfigurationException::class);
        $this->expectExceptionMessage('forms a cycle');

        $this->app->make(TranslationProviderInterface::class);
    }

    protected function getPackageProviders($app)
    {
        return [TranslaGeniusServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
