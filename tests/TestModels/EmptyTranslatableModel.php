<?php

namespace CodingPartners\TranslaGenius\Tests\TestModels;

use CodingPartners\TranslaGenius\Traits\Translatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Uses the Translatable trait but declares no translatable fields - exists
 * solely to exercise BackfillCommand's "nothing to backfill" no-op path.
 */
class EmptyTranslatableModel extends Model
{
    use Translatable;

    protected $table = 'test_translatable_models';
    protected $guarded = [];
    public $timestamps = false;

    public $translatable = [];
}
