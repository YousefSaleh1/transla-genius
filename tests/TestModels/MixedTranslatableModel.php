<?php

namespace CodingPartners\TranslaGenius\Tests\TestModels;

use CodingPartners\TranslaGenius\Traits\Translatable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\Attributes\Translatable as TranslatableColumns;

/**
 * Declares one column with the property and one with the attribute. spatie
 * merges the two (property first, duplicates removed).
 */
#[TranslatableColumns('description', 'name')]
class MixedTranslatableModel extends Model
{
    use Translatable;

    protected $table = 'test_translatable_models';

    protected $guarded = [];

    public $timestamps = false;

    public $translatable = ['name'];
}
