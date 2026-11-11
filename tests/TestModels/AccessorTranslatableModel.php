<?php

namespace CodingPartners\TranslaGenius\Tests\TestModels;

use CodingPartners\TranslaGenius\Traits\Translatable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Accessors on translatable attributes: what the app reads differs from
 * what's stored (a trimmed name, a description without tags).
 */
class AccessorTranslatableModel extends Model
{
    use Translatable;

    protected $table = 'test_translatable_models';

    protected $guarded = [];

    public $timestamps = false;

    public $translatable = ['name', 'description'];

    protected function name(): Attribute
    {
        return Attribute::get(fn ($value) => is_string($value) ? trim($value) : $value);
    }

    protected function description(): Attribute
    {
        return Attribute::get(fn ($value) => is_string($value) ? strip_tags($value) : $value);
    }
}
