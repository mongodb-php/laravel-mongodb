<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Movie extends Model
{
    protected $connection = 'mongodb';
    protected $table = 'movies';
    protected $fillable = ['title', 'year', 'plot'];
}
