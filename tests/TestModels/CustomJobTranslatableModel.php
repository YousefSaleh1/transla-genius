<?php

namespace CodingPartners\TranslaGenius\Tests\TestModels;

use CodingPartners\TranslaGenius\Tests\Support\MarkerTranslationJob;
use CodingPartners\TranslaGenius\Traits\Translatable;
use Illuminate\Database\Eloquent\Model;

/**
 * A Translatable model overriding translationJobClass() (Phase 4a) to
 * dispatch MarkerTranslationJob instead of the default TranslateFields -
 * exists so BackfillCommand's respect for that override (Phase 4d's
 * quick fix) can be exercised via a real class name CLI argument, the way
 * IsolationSwapTest's equivalent anonymous class can't be (an anonymous
 * class has no stable string name to pass as {model}).
 */
class CustomJobTranslatableModel extends Model
{
    use Translatable;

    protected $table = 'test_translatable_models';

    protected $guarded = [];

    public $timestamps = false;

    public $translatable = ['name', 'description'];

    protected static function translationJobClass(): string
    {
        return MarkerTranslationJob::class;
    }
}
