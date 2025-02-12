<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Inventory extends Model
{
    protected $connection = 'mongodb';
    protected $table = 'inventory';
    protected $fillable = ['_id', 'sku', 'description', 'instock'];
}
