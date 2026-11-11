<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\Events\TranslationFailed;
use CodingPartners\TranslaGenius\Exceptions\AllProvidersFailedException;
use CodingPartners\TranslaGenius\Exceptions\TranslaGeniusException;
use CodingPartners\TranslaGenius\Exceptions\TranslationConfigurationException;
use CodingPartners\TranslaGenius\Providers\FallbackProvider;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A configuration error (a TRANSLATION_PROVIDER typo, a missing key, a bad
 * chain or driver) can't fix itself between attempts: the job fails on its
 * first attempt, with TranslationConfigurationException, and fires
 * TranslationFailed once. Run through the real `database` queue.
 */
class ConfigurationErrorTest extends TestCase
{
    /** @var array<int, TranslationFailed> */
    private array $failedEvents = [];

    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::fake(fn () => $this->status === 200
            ? Http::response(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']]])
            : Http::response([], $this->status));

        Schema::dropIfExists('jobs');
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        config([
            'queue.default' => 'database',
            'queue.failed.driver' => 'null',
            'queue.connections.database' => [
                'driver' => 'database', 'connection' => config('database.default'), 'table' => 'jobs',
                'queue' => 'default', 'retry_after' => 90, 'after_commit' => false,
            ],
        ]);

        Event::listen(TranslationFailed::class, function (TranslationFailed $e) {
            $this->failedEvents[] = $e;
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('jobs');

        parent::tearDown();
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function misconfigurations(): array
    {
        $member = ['driver' => OpenAiCompatibleProvider::class, 'key' => 'k', 'url' => 'https://x.test', 'model' => 'm'];

        return [
            'unknown provider name (a TRANSLATION_PROVIDER typo)' => [['translagenius.default' => 'opnai'], 'no provider configured for [opnai]'],
            'missing key' => [['translagenius.providers.openrouter.key' => null], 'API key'],
            'fallback without a chain' => [['translagenius.default' => 'fb', 'translagenius.providers.fb' => ['driver' => FallbackProvider::class, 'chain' => '']], 'has no `chain`'],
            'chain of one' => [['translagenius.default' => 'fb', 'translagenius.providers.fb' => ['driver' => FallbackProvider::class, 'chain' => 'openrouter']], 'at least 2'],
            'chain that loops' => [['translagenius.default' => 'fb', 'translagenius.providers.fb' => ['driver' => FallbackProvider::class, 'chain' => 'openrouter,fb']], 'cycle'],
            'driver class that does not exist' => [['translagenius.providers.openrouter.driver' => 'App\\Nope'], 'does not exist'],
            'driver class that is not a provider' => [['translagenius.providers.openrouter.driver' => \stdClass::class], 'does not implement'],
            'chain member that is unknown' => [['translagenius.default' => 'fb', 'translagenius.providers.fb' => ['driver' => FallbackProvider::class, 'chain' => 'openrouter,nope'], 'translagenius.providers.member' => $member], 'no provider configured for [nope]'],
        ];
    }

    #[DataProvider('misconfigurations')]
    public function test_a_configuration_error_fails_the_job_on_its_first_attempt(array $config, string $message)
    {
        TestTranslatableModel::create(['name' => ['en' => 'Hello'], 'description' => ['en' => '']]);
        $this->assertSame(1, DB::table('jobs')->count());

        config($config);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 3]);

        $this->assertSame(0, DB::table('jobs')->count(), 'Failed for good on the first attempt, not released for a retry.');
        $this->assertCount(1, $this->failedEvents);
        $exception = $this->failedEvents[0]->exception;
        $this->assertInstanceOf(TranslationConfigurationException::class, $exception);
        $this->assertFalse($exception->isRetryable());
        $this->assertStringContainsString($message, $exception->getMessage());
        Http::assertNothingSent();
    }

    public function test_a_failed_chain_names_its_members_by_their_config_name()
    {
        $this->status = 500;
        $member = fn (string $host) => ['driver' => OpenAiCompatibleProvider::class, 'key' => 'k', 'url' => "https://{$host}/v1", 'model' => 'm', 'http_retries' => 0];
        config([
            'translagenius.default' => 'chain',
            'translagenius.providers.openai' => $member('openai.test'),
            'translagenius.providers.gemini' => $member('gemini.test'),
            'translagenius.providers.chain' => ['driver' => FallbackProvider::class, 'chain' => 'openai,gemini'],
        ]);

        try {
            app(TranslationProviderInterface::class)->translate(new TranslationRequest('Hello', 'en', 'ar'));
            $this->fail('Expected AllProvidersFailedException.');
        } catch (AllProvidersFailedException $e) {
            $failures = $e->getFailures();
            $this->assertSame(['openai', 'gemini'], array_column($failures, 'provider'));
            $this->assertSame([OpenAiCompatibleProvider::class, OpenAiCompatibleProvider::class], array_column($failures, 'class'));
            $this->assertStringContainsString('openai: ', $e->getMessage());
            $this->assertStringContainsString('gemini: ', $e->getMessage());
        }
    }

    public function test_every_package_exception_extends_the_package_base()
    {
        foreach (glob(__DIR__.'/../../src/Exceptions/*.php') as $file) {
            $class = 'CodingPartners\\TranslaGenius\\Exceptions\\'.basename($file, '.php');
            if ($class === TranslaGeniusException::class) {
                continue;
            }
            $this->assertTrue(is_subclass_of($class, TranslaGeniusException::class), "{$class} must extend TranslaGeniusException");
        }
    }
}
