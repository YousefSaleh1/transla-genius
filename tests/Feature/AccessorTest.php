<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\AccessorTranslatableModel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * An accessor changes what the app reads, not what's stored. Every
 * comparison the package makes is on stored values, so an accessor never
 * makes a paid translation look stale.
 */
class AccessorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config(['translagenius.supported_languages' => ['en', 'ar']]);
        Http::fake(fn (Request $request) => Http::response(['choices' => [['message' => ['content' => 'AR('.$request['messages'][1]['content'].')'], 'finish_reason' => 'stop']]]));
    }

    protected function stored(int $id, string $field): array
    {
        return AccessorTranslatableModel::find($id)->getTranslations($field);
    }

    public function test_a_trim_accessor_keeps_the_translation_and_a_source_edit_re_translates()
    {
        $model = AccessorTranslatableModel::create(['name' => ['en' => "Blue shirt\n"], 'description' => ['en' => '']]);
        $this->assertSame("AR(Blue shirt\n)", $this->stored($model->id, 'name')['ar'] ?? null, 'Saved on the first job.');

        AccessorTranslatableModel::find($model->id)->setTranslation('name', 'en', "Red shirt\n")->save();
        $this->assertSame("AR(Red shirt\n)", $this->stored($model->id, 'name')['ar'] ?? null, 'A source edit re-translates.');
    }

    public function test_a_strip_tags_accessor_keeps_the_translation_and_a_source_edit_re_translates()
    {
        $model = AccessorTranslatableModel::create(['name' => ['en' => 'x'], 'description' => ['en' => '<b>Bold</b> text']]);
        $this->assertSame('AR(<b>Bold</b> text)', $this->stored($model->id, 'description')['ar'] ?? null);

        AccessorTranslatableModel::find($model->id)->setTranslation('description', 'en', '<i>New</i> text')->save();
        $this->assertSame('AR(<i>New</i> text)', $this->stored($model->id, 'description')['ar'] ?? null);
        Http::assertSentCount(3);
    }
}
