<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Traits\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

/**
 * The re-translation plan carries hashes of the source and of each stale
 * target, not the text itself: a queued job stays a few KB however long
 * the fields are (SQS refuses messages over 256 KB).
 */
class PayloadSizeTest extends TestCase
{
    private const FIELDS = ['f1', 'f2', 'f3', 'f4', 'f5'];

    private const TARGETS = ['ar', 'es', 'fr', 'de', 'it', 'pt', 'ja', 'ko', 'zh', 'ru'];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('long_texts', function (Blueprint $table) {
            $table->id();
            foreach (self::FIELDS as $field) {
                $table->json($field);
            }
        });
        config(['translagenius.supported_languages' => ['en', ...self::TARGETS]]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('long_texts');

        parent::tearDown();
    }

    /** 5 fields x 5 KB, each already translated into 10 languages; then every source is edited. */
    protected function editAllSources(): Model
    {
        $text = fn (string $tag) => substr(str_repeat("{$tag} ", 1000), 0, 5000); // 5 KB
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = ['en' => $text("{$field}-en")];
            foreach (self::TARGETS as $locale) {
                $values[$field][$locale] = $text("{$field}-{$locale}");
            }
        }

        $model = LongTextModel::withoutEvents(fn () => LongTextModel::create($values));

        Queue::fake();
        foreach (self::FIELDS as $field) {
            $model->setTranslation($field, 'en', $text("{$field}-en-edited"));
        }
        $model->save();

        return $model;
    }

    public function test_the_worst_case_re_translation_payload_is_a_few_kb()
    {
        $this->editAllSources();

        $job = Queue::pushed(TranslateFields::class)->first();
        $size = strlen(serialize($job));

        $this->assertLessThan(8 * 1024, $size, "Payload is {$size} bytes.");
    }

    public function test_the_hashed_plan_still_re_translates_from_the_current_source()
    {
        Sleep::fake();
        Http::fake(fn (Request $request) => Http::response(['choices' => [['message' => ['content' => 'T:'.substr($request['messages'][1]['content'], 0, 12)], 'finish_reason' => 'stop']]]));

        $model = $this->editAllSources();
        $job = unserialize(serialize(Queue::pushed(TranslateFields::class)->first()));
        $job->handle($this->app->make(TranslatorInterface::class), $this->app->make(TranslationWriter::class));

        $fresh = LongTextModel::find($model->id);
        $this->assertSame('T:f3-en-edited', $fresh->getTranslation('f3', 'ko', false));
        Http::assertSentCount(50);
    }

    /** A plan in the format queued by pre-release builds (full text) still runs. */
    public function test_a_plan_with_full_text_from_an_earlier_build_still_runs()
    {
        Sleep::fake();
        Http::fake(fn () => Http::response(['choices' => [['message' => ['content' => 'NEW'], 'finish_reason' => 'stop']]]));

        $model = LongTextModel::withoutEvents(fn () => LongTextModel::create([
            'f1' => ['en' => 'Edited', 'ar' => 'OLD', 'es' => 'BY HAND'],
            'f2' => ['en' => ''], 'f3' => ['en' => ''], 'f4' => ['en' => ''], 'f5' => ['en' => ''],
        ]));
        config(['translagenius.supported_languages' => ['en', 'ar', 'es']]);

        $legacyPlan = ['f1' => ['locale' => 'en', 'text' => 'Edited', 'keep' => [], 'targets' => ['ar' => 'OLD', 'es' => 'STALE']]];
        (new TranslateFields(new TranslationJobPayload($model, ['f1'], 'en', $legacyPlan)))
            ->handle($this->app->make(TranslatorInterface::class), $this->app->make(TranslationWriter::class));

        $fresh = LongTextModel::find($model->id);
        $this->assertSame('NEW', $fresh->getTranslation('f1', 'ar', false));
        $this->assertSame('BY HAND', $fresh->getTranslation('f1', 'es', false), 'A target edited since the plan is kept.');
        Http::assertSentCount(1);
    }
}

class LongTextModel extends Model
{
    use Translatable;

    protected $table = 'long_texts';

    protected $guarded = [];

    public $timestamps = false;

    public $translatable = ['f1', 'f2', 'f3', 'f4', 'f5'];
}
