<?php

namespace MongoDB\Laravel\Tests\Eloquent;

use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Tests\TestCase;

/** Copied from {@see \Illuminate\Tests\Integration\Database\EloquentWithCountTest\EloquentWithCountTest} */
class EloquentWithCountTest extends TestCase
{
    protected function tearDown(): void
    {
        EloquentWithCountModel1::truncate();
        EloquentWithCountModel2::truncate();
        EloquentWithCountModel3::truncate();
        EloquentWithCountModel4::truncate();

        parent::tearDown();
    }

    public function testItBasic()
    {
        $one = EloquentWithCountModel1::create(['id' => 123]);
        $two = $one->twos()->create(['value' => 456]);
        $two->threes()->create();

        $results = EloquentWithCountModel1::withCount([
            'twos' => function ($query) {
                $query->where('value', '>=', 456);
            },
        ]);

        $this->assertEquals([
            ['id' => 123, 'twos_count' => 1],
        ], $results->get()->toArray());
    }

    public function testWithMultipleResults()
    {
        $ones = [
            EloquentWithCountModel1::create(['id' => 1]),
            EloquentWithCountModel1::create(['id' => 2]),
            EloquentWithCountModel1::create(['id' => 3]),
        ];

        $ones[0]->twos()->create(['value' => 1]);
        $ones[0]->twos()->create(['value' => 2]);
        $ones[0]->twos()->create(['value' => 3]);
        $ones[0]->twos()->create(['value' => 1]);
        $ones[2]->twos()->create(['value' => 1]);
        $ones[2]->twos()->create(['value' => 2]);

        $results = EloquentWithCountModel1::withCount([
            'twos' => function ($query) {
                $query->where('value', '>=', 2);
            },
        ]);

        $this->assertEquals([
            ['id' => 1, 'twos_count' => 2],
            ['id' => 2, 'twos_count' => 0],
            ['id' => 3, 'twos_count' => 1],
        ], $results->get()->toArray());
    }

    public function testGlobalScopes()
    {
        $one = EloquentWithCountModel1::create();
        $one->fours()->create();

        $result = EloquentWithCountModel1::withCount('fours')->first();
        $this->assertEquals(0, $result->fours_count);

        $result = EloquentWithCountModel1::withCount('allFours')->first();
        $this->assertEquals(1, $result->all_fours_count);
    }

    public function testSortingScopes()
    {
        $one = EloquentWithCountModel1::create();
        $one->twos()->create();

        $query = EloquentWithCountModel1::withCount('twos')->getQuery();

        $this->assertNull($query->orders);
        $this->assertSame([], $query->getRawBindings()['order']);
    }
}

class EloquentWithCountModel1 extends Model
{
    protected $connection = 'mongodb';
    public $table = 'one';
    public $timestamps = false;
    protected $guarded = [];

    public function twos()
    {
        return $this->hasMany(EloquentWithCountModel2::class, 'one_id');
    }

    public function fours()
    {
        return $this->hasMany(EloquentWithCountModel4::class, 'one_id');
    }

    public function allFours()
    {
        return $this->fours()->withoutGlobalScopes();
    }
}

class EloquentWithCountModel2 extends Model
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
        return $this->hasMany(EloquentWithCountModel3::class, 'two_id');
    }
}

class EloquentWithCountModel3 extends Model
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

class EloquentWithCountModel4 extends Model
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
