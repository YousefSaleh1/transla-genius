<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\AttributeTranslatableModel;
use CodingPartners\TranslaGenius\Tests\TestModels\MixedTranslatableModel;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Translatable columns can be declared the way spatie/laravel-translatable
 * allows: the $translatable property, its #[Translatable] attribute, or both.
 * The package follows spatie's own getTranslatableAttributes().
 */
class TranslatableColumnsDeclarationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::fake(function (Request $request) {
            preg_match('/ to (\w+)\./', $request['messages'][0]['content'], $m);

            return Http::response(['choices' => [['message' => ['content' => $request['messages'][1]['content'].'|'.$m[1]], 'finish_reason' => 'stop']]]);
        });
    }

    /** @return array<string, array{class-string<Model>}> */
    public static function declarations(): array
    {
        return [
            'property' => [TestTranslatableModel::class],
            'attribute' => [AttributeTranslatableModel::class],
            'property and attribute' => [MixedTranslatableModel::class],
        ];
    }

    #[DataProvider('declarations')]
    public function test_the_declared_columns_are_the_translatable_attributes(string $class)
    {
        $this->assertEqualsCanonicalizing(['name', 'description'], (new $class)->getTranslatableAttributes());
    }

    public function test_property_and_attribute_follow_spatie_precedence()
    {
        // Property entries first, then the attribute's, without duplicates.
        $this->assertSame(['name', 'description'], (new MixedTranslatableModel)->getTranslatableAttributes());
    }

    #[DataProvider('declarations')]
    public function test_create_translate_scopes_and_backfill(string $class)
    {
        $model = $class::create(['name' => ['en' => 'Lamp'], 'description' => ['en' => 'Bright']]);

        $fresh = $class::find($model->getKey());
        $this->assertSame('Lamp|ar', $fresh->getTranslation('name', 'ar', false));
        $this->assertSame('Bright|es', $fresh->getTranslation('description', 'es', false));
        $this->assertSame(1, $class::fullyTranslated()->count());
        $this->assertSame(0, $class::notFullyTranslated()->count());

        // Lose one translation behind the model's back; backfill restores it.
        DB::table('test_translatable_models')->where('id', $model->getKey())
            ->update(['description' => json_encode(['en' => 'Bright', 'ar' => 'Bright|ar'])]);
        $this->assertSame(1, $class::notFullyTranslated()->count());

        $this->artisan('translagenius:backfill', ['model' => $class, '--force' => true, '--source' => 'en'])->assertSuccessful();

        $this->assertSame('Bright|es', $class::find($model->getKey())->getTranslation('description', 'es', false));
        $this->assertSame(1, $class::fullyTranslated()->count());
    }
}
