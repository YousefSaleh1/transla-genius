<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\Events\TranslationRequestBilled;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use CodingPartners\TranslaGenius\Providers\FallbackProvider;
use CodingPartners\TranslaGenius\Providers\OpenAiCompatibleProvider;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * One rule decides whether a translation is there: blank means null, or a
 * string that is empty after trim(). Whitespace-only model output is a
 * failure; "0" is a real value, as source text and as a translation.
 */
class BlankAndZeroTranslationTest extends TestCase
{
    /** @var array<string, string> host => reply content */
    private array $replies = [];

    /** @var array<int, string> "host to-locale text" per request */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::fake(function (Request $request) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            preg_match('/ to (\w+)\./', $request['messages'][0]['content'], $m);
            $text = $request['messages'][1]['content'];
            $this->requests[] = "{$host} {$m[1]} {$text}";

            return Http::response([
                'choices' => [['message' => ['content' => $this->replies[$host] ?? "{$text}|{$m[1]}"], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 1],
            ]);
        });
    }

    protected function provider(string $host): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider(['key' => 'k', 'url' => "https://{$host}/v1/chat/completions", 'model' => 'm', 'name' => $host, 'http_retries' => 0]);
    }

    public function test_whitespace_output_is_a_non_retryable_translation_failure_billed_as_unusable()
    {
        $this->replies['primary.test'] = "  \n\t";
        $billed = [];
        Event::listen(TranslationRequestBilled::class, function ($e) use (&$billed) {
            $billed[] = $e->outcome;
        });

        try {
            $this->provider('primary.test')->translate(new TranslationRequest('Hello', 'en', 'ar'));
            $this->fail('Expected TranslationFailedException.');
        } catch (TranslationFailedException $e) {
            $this->assertFalse($e->isRetryable());
        }

        $this->assertSame([TranslationRequestBilled::UNUSABLE], $billed);
    }

    public function test_whitespace_output_in_a_chain_moves_on_to_the_secondary()
    {
        $this->replies['primary.test'] = "  \n";
        $this->replies['secondary.test'] = 'مرحبا';

        $chain = new FallbackProvider([$this->provider('primary.test'), $this->provider('secondary.test')]);
        $result = $chain->translate(new TranslationRequest('Hello', 'en', 'ar'));

        $this->assertSame('مرحبا', $result->text);
        $this->assertSame('secondary.test', $result->provider);
    }

    public function test_a_zero_translation_is_saved_and_counted_as_translated()
    {
        $this->replies['test-api-url.com'] = '0';

        $model = TestTranslatableModel::create(['name' => ['en' => 'Zero'], 'description' => ['en' => 'None']]);

        $fresh = TestTranslatableModel::find($model->id);
        $this->assertSame('0', $fresh->getTranslation('name', 'ar', false));
        $this->assertSame('0', $fresh->getTranslation('description', 'es', false));
        $this->assertSame(1, TestTranslatableModel::fullyTranslated()->count());
        $this->assertSame(0, TestTranslatableModel::notFullyTranslated()->count());

        // A later job sees "0" as present and requests nothing.
        $this->requests = [];
        $this->artisan('translagenius:backfill', ['model' => TestTranslatableModel::class, '--force' => true, '--source' => 'en'])->assertSuccessful();
        $this->assertSame([], $this->requests);
    }

    public function test_a_stored_zero_is_not_overwritten_by_a_fill_missing_job()
    {
        $model = TestTranslatableModel::create(['name' => ['en' => 'Zero', 'ar' => '0'], 'description' => ['en' => '']]);

        $this->assertSame('0', TestTranslatableModel::find($model->id)->getTranslation('name', 'ar', false));
        $this->assertSame(['test-api-url.com es Zero'], $this->requests, 'Only the missing locale is requested.');
    }

    public function test_zero_source_text_is_translated_not_skipped()
    {
        $model = TestTranslatableModel::create(['name' => ['en' => '0'], 'description' => ['en' => '']]);

        $this->assertSame('0|ar', TestTranslatableModel::find($model->id)->getTranslation('name', 'ar', false));
        $this->assertContains('test-api-url.com ar 0', $this->requests);
    }

    public function test_editing_the_source_to_whitespace_requests_nothing()
    {
        $model = TestTranslatableModel::create(['name' => ['en' => 'Hello'], 'description' => ['en' => '']]);
        $this->requests = [];

        TestTranslatableModel::find($model->id)->setTranslation('name', 'en', '   ')->save();

        $this->assertSame([], $this->requests);
    }
}
