<?php

namespace CodingPartners\TranslaGenius\Tests\TestModels;

use CodingPartners\TranslaGenius\Traits\Translatable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\Attributes\Translatable as TranslatableColumns;

/** Declares its translatable columns with spatie's attribute only. */
#[TranslatableColumns('name', 'description')]
class AttributeTranslatableModel extends Model
{
    use Translatable;

    protected $table = 'test_translatable_models';

    protected $guarded = [];

    public $timestamps = false;
}
