<?php

namespace CodingPartners\TranslaGenius\Tests\TestModels;

use CodingPartners\TranslaGenius\Traits\Translatable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A translatable model with a ULID (string) primary key. */
class UlidTranslatableModel extends Model
{
    use HasUlids, Translatable;

    protected $table = 'test_string_key_models';
    protected $guarded = [];
    public $timestamps = false;

    public $translatable = ['name', 'description'];
}
