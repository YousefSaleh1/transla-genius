<?php

namespace CodingPartners\TranslaGenius\Tests\TestModels;

use CodingPartners\TranslaGenius\Traits\Translatable;
use Illuminate\Database\Eloquent\Model;

class TestTranslatableModel extends Model
{
    use Translatable;

    protected $table = 'test_translatable_models';

    protected $guarded = [];

    public $timestamps = false;

    public $translatable = ['name', 'description'];
}
