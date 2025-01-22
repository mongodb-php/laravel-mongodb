<?php

namespace MongoDB\Laravel\Tests\Eloquent\Models;

use MongoDB\Laravel\Eloquent\Model;

class EloquentWithAggregateModel2 extends Model
{
    protected $connection = 'mongodb';
    public $table = 'two';
    public $timestamps = false;
    protected $guarded = [];
    protected $withCount = ['threes'];

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('app', function ($builder) {
            $builder->latest();
        });
    }

    public function threes()
    {
        return $this->hasMany(EloquentWithAggregateModel3::class, 'two_id');
    }
}
