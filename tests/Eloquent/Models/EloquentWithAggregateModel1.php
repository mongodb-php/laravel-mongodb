<?php

namespace MongoDB\Laravel\Tests\Eloquent\Models;

use MongoDB\Laravel\Eloquent\Model;

class EloquentWithAggregateModel1 extends Model
{
    protected $connection = 'mongodb';
    public $table = 'one';
    public $timestamps = false;
    protected $guarded = [];

    public function twos()
    {
        return $this->hasMany(EloquentWithAggregateModel2::class, 'one_id');
    }

    public function fours()
    {
        return $this->hasMany(EloquentWithAggregateModel4::class, 'one_id');
    }

    public function allFours()
    {
        return $this->fours()->withoutGlobalScopes();
    }

    public function embeddeds()
    {
        return $this->embedsMany(EloquentWithAggregateEmbeddedModel::class);
    }

    public function hybrids()
    {
        return $this->hasMany(EloquentWithAggregateHybridModel::class, 'one_id');
    }
}
