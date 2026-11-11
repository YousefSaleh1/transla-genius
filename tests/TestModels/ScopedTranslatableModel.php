<?php

namespace CodingPartners\TranslaGenius\Tests\TestModels;

use CodingPartners\TranslaGenius\Traits\Translatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A model whose global scope can hide rows (as a soft delete or a tenant scope would). */
class ScopedTranslatableModel extends Model
{
    use Translatable;

    /** Ids the global scope hides. */
    public static array $hiddenIds = [];

    protected $table = 'test_translatable_models';

    protected $guarded = [];

    public $timestamps = false;

    public $translatable = ['name', 'description'];

    protected static function booted(): void
    {
        static::addGlobalScope('hidden', fn (Builder $query) => $query->whereNotIn('id', static::$hiddenIds ?: [0]));
    }
}
