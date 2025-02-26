<?php

namespace MongoDB\Laravel\Tests\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

class EloquentWithAggregateHybridModel extends Model
{
    protected $connection = 'sqlite';
    public $table = 'hybrid';
    public $timestamps = false;
    protected $guarded = [];
}
