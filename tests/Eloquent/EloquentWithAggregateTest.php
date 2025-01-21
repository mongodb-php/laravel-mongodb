<?php

namespace MongoDB\Laravel\Tests\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Tests\TestCase;

use function count;

class EloquentWithAggregateTest extends TestCase
{
    protected function tearDown(): void
    {
        EloquentWithCountModel1::truncate();
        EloquentWithCountModel2::truncate();
        EloquentWithCountModel3::truncate();
        EloquentWithCountModel4::truncate();

        parent::tearDown();
    }

    public function testWithAggregate()
    {
        EloquentWithCountModel1::create(['id' => 1]);
        $one = EloquentWithCountModel1::create(['id' => 2]);
        $one->twos()->create(['value' => 4]);
        $one->twos()->create(['value' => 6]);

        $results = EloquentWithCountModel1::withCount('twos')->where('id', 2);
        $this->assertSame([
            ['id' => 2, 'twos_count' => 2],
        ], $results->get()->toArray());

        $results = EloquentWithCountModel1::withMax('twos', 'value')->where('id', 2);
        $this->assertSame([
            ['id' => 2, 'twos_max' => 6],
        ], $results->get()->toArray());

        $results = EloquentWithCountModel1::withMin('twos', 'value')->where('id', 2);
        $this->assertSame([
            ['id' => 2, 'twos_min' => 4],
        ], $results->get()->toArray());

        $results = EloquentWithCountModel1::withAvg('twos', 'value')->where('id', 2);
        $this->assertSame([
            ['id' => 2, 'twos_avg' => 5.0],
        ], $results->get()->toArray());
    }

    public function testWithAggregateFiltered()
    {
        EloquentWithCountModel1::create(['id' => 1]);
        $one = EloquentWithCountModel1::create(['id' => 2]);
        $one->twos()->create(['value' => 4]);
        $one->twos()->create(['value' => 6]);
        $one->twos()->create(['value' => 8]);
        $filter = static function (Builder $query) {
            $query->where('value', '<=', 6);
        };

        $results = EloquentWithCountModel1::withCount(['twos' => $filter])->where('id', 2);
        $this->assertSame([
            ['id' => 2, 'twos_count' => 2],
        ], $results->get()->toArray());

        $results = EloquentWithCountModel1::withMax(['twos' => $filter], 'value')->where('id', 2);
        $this->assertSame([
            ['id' => 2, 'twos_max' => 6],
        ], $results->get()->toArray());

        $results = EloquentWithCountModel1::withMin(['twos' => $filter], 'value')->where('id', 2);
        $this->assertSame([
            ['id' => 2, 'twos_min' => 4],
        ], $results->get()->toArray());

        $results = EloquentWithCountModel1::withAvg(['twos' => $filter], 'value')->where('id', 2);
        $this->assertSame([
            ['id' => 2, 'twos_avg' => 5.0],
        ], $results->get()->toArray());
    }

    public function testWithAggregateMultipleResults()
    {
        $connection = DB::connection('mongodb');
        $ones = [
            EloquentWithCountModel1::create(['id' => 1]),
            EloquentWithCountModel1::create(['id' => 2]),
            EloquentWithCountModel1::create(['id' => 3]),
            EloquentWithCountModel1::create(['id' => 4]),
        ];

        $ones[0]->twos()->create(['value' => 1]);
        $ones[0]->twos()->create(['value' => 2]);
        $ones[0]->twos()->create(['value' => 3]);
        $ones[0]->twos()->create(['value' => 1]);
        $ones[2]->twos()->create(['value' => 1]);
        $ones[2]->twos()->create(['value' => 2]);

        $connection->enableQueryLog();

        // Count
        $results = EloquentWithCountModel1::withCount([
            'twos' => function ($query) {
                $query->where('value', '>=', 2);
            },
        ]);

        $this->assertSame([
            ['id' => 1, 'twos_count' => 2],
            ['id' => 2, 'twos_count' => 0],
            ['id' => 3, 'twos_count' => 1],
            ['id' => 4, 'twos_count' => 0],
        ], $results->get()->toArray());

        $this->assertSame(2, count($connection->getQueryLog()));
        $connection->flushQueryLog();

        // Max
        $results = EloquentWithCountModel1::withMax([
            'twos' => function ($query) {
                $query->where('value', '>=', 2);
            },
        ], 'value');

        $this->assertSame([
            ['id' => 1, 'twos_max' => 3],
            ['id' => 2, 'twos_max' => null],
            ['id' => 3, 'twos_max' => 2],
            ['id' => 4, 'twos_max' => null],
        ], $results->get()->toArray());

        $this->assertSame(2, count($connection->getQueryLog()));
        $connection->flushQueryLog();

        // Min
        $results = EloquentWithCountModel1::withMin([
            'twos' => function ($query) {
                $query->where('value', '>=', 2);
            },
        ], 'value');

        $this->assertSame([
            ['id' => 1, 'twos_min' => 2],
            ['id' => 2, 'twos_min' => null],
            ['id' => 3, 'twos_min' => 2],
            ['id' => 4, 'twos_min' => null],
        ], $results->get()->toArray());

        $this->assertSame(2, count($connection->getQueryLog()));
        $connection->flushQueryLog();

        // Avg
        $results = EloquentWithCountModel1::withAvg([
            'twos' => function ($query) {
                $query->where('value', '>=', 2);
            },
        ], 'value');

        $this->assertSame([
            ['id' => 1, 'twos_avg' => 2.5],
            ['id' => 2, 'twos_avg' => null],
            ['id' => 3, 'twos_avg' => 2.0],
            ['id' => 4, 'twos_avg' => null],
        ], $results->get()->toArray());

        $this->assertSame(2, count($connection->getQueryLog()));
        $connection->flushQueryLog();
    }

    public function testGlobalScopes()
    {
        $one = EloquentWithCountModel1::create();
        $one->fours()->create();

        $result = EloquentWithCountModel1::withCount('fours')->first();
        $this->assertSame(0, $result->fours_count);

        $result = EloquentWithCountModel1::withCount('allFours')->first();
        $this->assertSame(1, $result->all_fours_count);
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
