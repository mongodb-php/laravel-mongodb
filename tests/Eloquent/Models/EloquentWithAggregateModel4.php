<?php

namespace MongoDB\Laravel\Tests\Eloquent\Models;

use MongoDB\Laravel\Eloquent\Model;

class EloquentWithAggregateModel4 extends Model
{
    protected $connection = 'mongodb';
    public $table = 'four';
    public $timestamps = false;
    protected $guarded = [];

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('app', function ($builder) {
            $builder->where('id', '>', 1);
        });
    }
}
