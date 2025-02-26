<?php

namespace MongoDB\Laravel\Tests\Eloquent\Models;

use MongoDB\Laravel\Eloquent\Model;

class EloquentWithAggregateModel3 extends Model
{
    protected $connection = 'mongodb';
    public $table = 'three';
    public $timestamps = false;
    protected $guarded = [];

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('app', function ($builder) {
            $builder->where('id', '>', 0);
        });
    }
}
