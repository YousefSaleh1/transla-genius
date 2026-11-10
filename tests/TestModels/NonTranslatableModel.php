<?php

namespace CodingPartners\TranslaGenius\Tests\TestModels;

use Illuminate\Database\Eloquent\Model;

/**
 * A plain Eloquent model that does NOT use the Translatable trait -
 * exists solely to exercise BackfillCommand's validation of that
 * requirement.
 */
class NonTranslatableModel extends Model
{
    protected $table = 'test_translatable_models';
    protected $guarded = [];
    public $timestamps = false;
}
