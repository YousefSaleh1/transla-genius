<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Listeners\LogTranslationUsage;
use CodingPartners\TranslaGenius\Providers\CachingProvider;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every numeric setting follows one rule: an empty or non-numeric value
 * (e.g. `TRANSLATION_API_TIMEOUT=` in .env) means "use the default", never
 * the 0 that (int) '' would give.
 */
class NumericSettingsTest extends TestCase
{
    private const ENV = 'TRANSLATION_API_TIMEOUT';

    protected function tearDown(): void
    {
        putenv(self::ENV);
        unset($_ENV[self::ENV], $_SERVER[self::ENV]);

        parent::tearDown();
    }

    protected function property(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }

    protected function provider(array $config): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider(['key' => 'k', 'url' => 'https://test-api-url.com', 'model' => 'm'] + $config);
    }

    public function test_an_empty_timeout_env_value_gives_the_default_timeout()
    {
        putenv(self::ENV.'=');
        $_ENV[self::ENV] = $_SERVER[self::ENV] = '';

        $shipped = require __DIR__.'/../../src/config/translagenius.php';
        $this->assertSame(10, $this->property($this->provider($shipped['providers']['openrouter']), 'timeout'));
    }

    /** @return array<string, array{mixed, int}> */
    public static function timeouts(): array
    {
        return [
            'empty' => ['', 10],
            'spaces' => ['  ', 10],
            'not a number' => ['ten', 10],
            'null' => [null, 10],
            'explicit zero (no timeout)' => ['0', 0],
            'a number' => ['25', 25],
            'an int' => [25, 25],
        ];
    }

    #[DataProvider('timeouts')]
    public function test_timeout(mixed $value, int $expected)
    {
        $this->assertSame($expected, $this->property($this->provider(['timeout' => $value]), 'timeout'));
    }

    public function test_optional_numbers_are_unset_when_empty_or_not_numeric()
    {
        foreach (['', 'abc', null] as $value) {
            $provider = $this->provider(['temperature' => $value, 'max_tokens' => $value]);
            $this->assertNull($this->property($provider, 'temperature'), var_export($value, true));
            $this->assertNull($this->property($provider, 'maxTokens'), var_export($value, true));
        }

        $provider = $this->provider(['temperature' => '0', 'max_tokens' => '500']);
        $this->assertSame(0.0, $this->property($provider, 'temperature'));
        $this->assertSame(500, $this->property($provider, 'maxTokens'));
    }

    public function test_retry_counts_fall_back_to_their_defaults()
    {
        $provider = $this->provider(['http_retries' => 'abc', 'retry_after_max' => '']);
        $this->assertSame(1, $this->property($provider, 'httpRetries'));
        $this->assertSame(10, $this->property($provider, 'retryAfterMax'));

        config(['translagenius.retries.http' => 'abc', 'translagenius.retries.http_retry_after_max' => 'x']);
        $resolved = $this->app->make(TranslationProviderInterface::class);
        $this->assertSame(1, $this->property($resolved, 'httpRetries'));
        $this->assertSame(10, $this->property($resolved, 'retryAfterMax'));
    }

    public function test_job_tries_and_backoff_fall_back_to_their_defaults()
    {
        $payload = new TranslationJobPayload(new TestTranslatableModel, ['name']);

        config(['translagenius.retries.job_tries' => 'three', 'translagenius.retries.job_backoff' => 'soon']);
        $job = new TranslateFields($payload);
        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 30], $job->backoff());

        // Non-numeric entries in the list are dropped, numeric ones kept.
        config(['translagenius.retries.job_backoff' => 'x, 20 ,']);
        $this->assertSame([20], (new TranslateFields($payload))->backoff());
    }

    public function test_cache_ttl_falls_back_to_its_default()
    {
        config(['translagenius.cache.enabled' => true, 'translagenius.cache.ttl' => 'month']);

        $provider = $this->app->make(TranslationProviderInterface::class);
        $this->assertInstanceOf(CachingProvider::class, $provider);
        $this->assertSame(2592000, $this->property($provider, 'ttl'));
    }

    public function test_a_non_numeric_cost_rate_is_unknown_not_free()
    {
        config(['translagenius.providers.openrouter.cost_per_1k_prompt_tokens' => 'abc', 'translagenius.providers.openrouter.cost_per_1k_completion_tokens' => '']);

        $estimate = (new \ReflectionMethod(LogTranslationUsage::class, 'estimateCost'))
            ->invoke(new LogTranslationUsage, 'openrouter', 100, 50);

        $this->assertNull($estimate);
    }
}
