<?php

namespace MongoDB\Laravel\Tests\Eloquent\Models;

use MongoDB\Laravel\Eloquent\Model;

class EloquentWithAggregateEmbeddedModel extends Model
{
    protected $connection = 'mongodb';
    public $timestamps = false;
    protected $guarded = [];
}
