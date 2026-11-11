<?php

namespace CodingPartners\TranslaGenius\Tests\TestModels;

use CodingPartners\TranslaGenius\Traits\Translatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A translatable model with a UUID (string) primary key. */
class UuidTranslatableModel extends Model
{
    use HasUuids, Translatable;

    protected $table = 'test_string_key_models';

    protected $guarded = [];

    public $timestamps = false;

    public $translatable = ['name', 'description'];
}
