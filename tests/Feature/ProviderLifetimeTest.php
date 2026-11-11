<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Models\TranslationUsageLog;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Support\TranslationJobDispatcher;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

/**
 * Config read when a job runs, not when the process started: a queue worker
 * serving several tenants changes config between jobs, and every job must
 * go out with its own tenant's key, URL, model, provider and cache setting.
 * Nothing calls forgetInstance() here: that's the point.
 */
class ProviderLifetimeTest extends TestCase
{
    /** @var array<int, array{url: string, auth: string, model: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config(['translagenius.supported_languages' => ['en', 'ar']]);

        Http::fake(function (Request $request) {
            $this->sent[] = ['url' => $request->url(), 'auth' => $request->header('Authorization')[0] ?? '', 'model' => $request['model']];

            return Http::response(['choices' => [['message' => ['content' => 'مرحبا'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 1]]);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('translation_usage_logs');

        parent::tearDown();
    }

    /** A job, resolved and run the way a queue worker does. */
    protected function runJob(string $text = 'Hello'): void
    {
        $model = TestTranslatableModel::withoutEvents(fn () => TestTranslatableModel::create(['name' => ['en' => $text], 'description' => ['en' => '']]));

        (new TranslateFields(new TranslationJobPayload($model, ['name'], 'en')))
            ->handle($this->app->make(TranslatorInterface::class), $this->app->make(TranslationWriter::class));
    }

    protected function last(): array
    {
        return $this->sent[array_key_last($this->sent)];
    }

    public function test_a_key_change_between_jobs_is_honored()
    {
        config(['translagenius.providers.openrouter.key' => 'tenant-a-key']);
        $this->runJob();
        $this->assertSame('Bearer tenant-a-key', $this->last()['auth']);

        config(['translagenius.providers.openrouter.key' => 'tenant-b-key']);
        $this->runJob();
        $this->assertSame('Bearer tenant-b-key', $this->last()['auth']);
    }

    public function test_url_and_model_changes_between_jobs_are_honored()
    {
        $this->runJob();
        $this->assertSame(['https://test-api-url.com', 'test-model'], [$this->last()['url'], $this->last()['model']]);

        config(['translagenius.providers.openrouter.url' => 'https://tenant-b.test/v1/chat/completions', 'translagenius.providers.openrouter.model' => 'tenant-b-model']);
        $this->runJob();
        $this->assertSame(['https://tenant-b.test/v1/chat/completions', 'tenant-b-model'], [$this->last()['url'], $this->last()['model']]);
    }

    public function test_a_default_provider_change_between_jobs_is_honored()
    {
        $this->runJob();
        $this->assertSame('https://test-api-url.com', $this->last()['url']);

        config([
            'translagenius.providers.other' => ['driver' => OpenAiCompatibleProvider::class, 'key' => 'other-key', 'url' => 'https://other.test/v1', 'model' => 'other-model'],
            'translagenius.default' => 'other',
        ]);
        $this->runJob();
        $this->assertSame(['https://other.test/v1', 'Bearer other-key'], [$this->last()['url'], $this->last()['auth']]);
    }

    public function test_turning_the_cache_on_between_jobs_is_honored()
    {
        $this->runJob('Free shipping');
        $this->assertCount(1, $this->sent);

        config(['translagenius.cache.enabled' => true]);
        $this->runJob('Free shipping'); // cache now on: requested and stored
        $this->runJob('Free shipping'); // served from the cache
        $this->assertCount(2, $this->sent);
    }

    public function test_unchanged_config_reuses_the_same_provider_instance()
    {
        $first = $this->app->make(TranslationProviderInterface::class);
        $this->assertSame($first, $this->app->make(TranslationProviderInterface::class));

        config(['translagenius.providers.openrouter.key' => 'another-key']);
        $other = $this->app->make(TranslationProviderInterface::class);
        $this->assertNotSame($first, $other);

        config(['translagenius.providers.openrouter.key' => 'test-api-key']);
        $this->assertSame($first, $this->app->make(TranslationProviderInterface::class));
    }

    /** A tenant's locale set in config before its job runs is the default source locale. */
    public function test_a_tenant_locale_set_before_the_job_is_honored()
    {
        $this->assertSame('en', TranslationJobDispatcher::defaultSourceLocale());

        config(['app.locale' => 'ar']); // e.g. a Queue::before hook for this tenant
        $this->assertSame('ar', TranslationJobDispatcher::defaultSourceLocale());

        // A locale set with setLocale() afterwards (a request or a job
        // changing it) is still not the configured one.
        app()->setLocale('es');
        $this->assertSame('ar', TranslationJobDispatcher::defaultSourceLocale());
    }

    public function test_usage_tracking_turned_on_at_runtime_logs()
    {
        (include __DIR__.'/../../src/database/migrations/create_translation_usage_logs_table.php.stub')->up();

        config(['translagenius.usage_tracking.enabled' => true]);
        $this->runJob();

        $this->assertSame(1, TranslationUsageLog::count());

        config(['translagenius.usage_tracking.enabled' => false]);
        $this->runJob();

        $this->assertSame(1, TranslationUsageLog::count());
    }
}
