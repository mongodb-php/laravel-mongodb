<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Sale extends Model
{
    protected $connection = 'mongodb';
    protected $fillable = ['_id', 'item', 'price', 'quantity', 'date', 'items'];
}
