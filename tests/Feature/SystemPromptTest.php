<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;
use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

class SystemPromptTest extends TestCase
{
    /** The prompt as it was hardcoded before it became configurable. */
    private const LEGACY_PROMPT_EN_AR = "You are a translation engine. Translate the user's message from en to ar. "
        . 'Respond with only the translated text - no explanation, commentary, or additional formatting.';

    /** @var array<int, array<int, array{role: string, content: string}>> */
    private array $sentMessages = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake(function (Request $request) {
            $this->sentMessages[] = $request['messages'];

            return Http::response(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']]]);
        });
    }

    protected function systemPrompts(): array
    {
        return array_map(fn (array $messages) => $messages[0]['content'], $this->sentMessages);
    }

    /** Run the real job for the `name` field into `ar` only. */
    protected function translateName(string $text = 'Hello'): void
    {
        config(['translaGenius.supported_languages' => ['en', 'ar']]);
        $this->app->forgetInstance(TranslationProviderInterface::class);
        $this->app->forgetInstance(TranslatorInterface::class);
        $this->app->forgetInstance(\CodingPartners\TranslaGenius\Services\Translator::class);

        $model = TestTranslatableModel::create(['name' => ['en' => $text], 'description' => ['en' => '']]);
        (new TranslateFields(new TranslationJobPayload($model, ['name'])))
            ->handle($this->app->make(TranslatorInterface::class), $this->app->make(TranslationWriter::class));
    }

    public function test_default_prompt_is_byte_identical_to_the_previous_hardcoded_one()
    {
        (new OpenAiCompatibleProvider(['key' => 'k', 'url' => 'https://test-api-url.com', 'model' => 'm']))
            ->translate(new TranslationRequest('Hello', 'en', 'ar'));

        $this->assertSame([self::LEGACY_PROMPT_EN_AR], $this->systemPrompts());
    }

    public function test_default_prompt_through_the_container_with_nothing_configured()
    {
        config(['translaGenius.prompt' => null]);

        $this->translateName();

        $this->assertSame([self::LEGACY_PROMPT_EN_AR], $this->systemPrompts());
    }

    public function test_empty_or_blank_prompt_falls_back_to_the_default()
    {
        config(['translaGenius.prompt' => '   ', 'translaGenius.providers.openrouter.prompt' => '']);

        $this->translateName();

        $this->assertSame([self::LEGACY_PROMPT_EN_AR], $this->systemPrompts());
    }

    public function test_placeholders_are_substituted_and_unknown_ones_are_left_untouched()
    {
        config(['translaGenius.prompt' => 'Translate this {model} {field} from {source_locale} to {target_locale}. Keep {brand} unchanged.']);

        $this->translateName();

        $this->assertSame(['Translate this TestTranslatableModel name from en to ar. Keep {brand} unchanged.'], $this->systemPrompts());
    }

    public function test_context_placeholders_are_empty_when_no_context_is_given()
    {
        (new OpenAiCompatibleProvider(['key' => 'k', 'url' => 'https://test-api-url.com', 'model' => 'm', 'prompt' => '[{field}|{model}] {source_locale}->{target_locale}']))
            ->translate(new TranslationRequest('Hello', 'en', 'ar'));

        $this->assertSame(['[|] en->ar'], $this->systemPrompts());
    }

    public function test_per_provider_prompt_wins_over_the_package_prompt()
    {
        config([
            'translaGenius.prompt' => 'GLOBAL {target_locale}',
            'translaGenius.providers.openrouter.prompt' => 'PROVIDER {target_locale}',
        ]);

        $this->translateName();

        $this->assertSame(['PROVIDER ar'], $this->systemPrompts());
    }

    public function test_prompt_comes_from_the_env_var_in_the_shipped_config()
    {
        putenv('TRANSLATION_SYSTEM_PROMPT=Translate to {target_locale}, formally.');
        $_ENV['TRANSLATION_SYSTEM_PROMPT'] = $_SERVER['TRANSLATION_SYSTEM_PROMPT'] = 'Translate to {target_locale}, formally.';

        try {
            $shipped = require __DIR__ . '/../../src/config/translaGenius.php';
        } finally {
            putenv('TRANSLATION_SYSTEM_PROMPT');
            unset($_ENV['TRANSLATION_SYSTEM_PROMPT'], $_SERVER['TRANSLATION_SYSTEM_PROMPT']);
        }

        config(['translaGenius.prompt' => $shipped['prompt']]);
        $this->translateName();

        $this->assertSame(['Translate to ar, formally.'], $this->systemPrompts());
    }

    /**
     * The safety boundary: field content only ever goes in the `user`
     * message. Even a prompt that names {text}/{content}/{message} can't pull
     * it into the system role - those aren't placeholders, so they stay
     * literal.
     */
    public function test_field_content_stays_in_the_user_message_whatever_the_prompt()
    {
        config(['translaGenius.prompt' => 'Context: {text} {content} {message} {user} {field}']);

        $this->translateName('SECRET FIELD CONTENT');

        $this->assertCount(1, $this->sentMessages);
        [$system, $user] = $this->sentMessages[0];

        $this->assertSame('system', $system['role']);
        $this->assertSame('Context: {text} {content} {message} {user} name', $system['content']);
        $this->assertStringNotContainsString('SECRET', $system['content']);

        $this->assertSame(['role' => 'user', 'content' => 'SECRET FIELD CONTENT'], $user);
        $this->assertCount(2, $this->sentMessages[0]);
    }
}
