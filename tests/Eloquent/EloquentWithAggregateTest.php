<?php

namespace MongoDB\Laravel\Tests\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Tests\Eloquent\Models\EloquentWithAggregateModel1;
use MongoDB\Laravel\Tests\Eloquent\Models\EloquentWithAggregateModel2;
use MongoDB\Laravel\Tests\Eloquent\Models\EloquentWithAggregateModel3;
use MongoDB\Laravel\Tests\Eloquent\Models\EloquentWithAggregateModel4;
use MongoDB\Laravel\Tests\TestCase;

use function count;
use function ksort;

class EloquentWithAggregateTest extends TestCase
{
    protected function tearDown(): void
    {
        EloquentWithAggregateModel1::truncate();
        EloquentWithAggregateModel2::truncate();
        EloquentWithAggregateModel3::truncate();
        EloquentWithAggregateModel4::truncate();

        parent::tearDown();
    }

    public function testWithAggregate()
    {
        EloquentWithAggregateModel1::create(['id' => 1]);
        $one = EloquentWithAggregateModel1::create(['id' => 2]);
        $one->twos()->create(['value' => 4]);
        $one->twos()->create(['value' => 6]);

        $results = EloquentWithAggregateModel1::withCount('twos')->where('id', 2);
        self::assertSameResults([
            ['id' => 2, 'twos_count' => 2],
        ], $results->get());

        $results = EloquentWithAggregateModel1::withMax('twos', 'value')->where('id', 2);
        self::assertSameResults([
            ['id' => 2, 'twos_max' => 6],
        ], $results->get());

        $results = EloquentWithAggregateModel1::withMin('twos', 'value')->where('id', 2);
        self::assertSameResults([
            ['id' => 2, 'twos_min' => 4],
        ], $results->get());

        $results = EloquentWithAggregateModel1::withAvg('twos', 'value')->where('id', 2);
        self::assertSameResults([
            ['id' => 2, 'twos_avg' => 5.0],
        ], $results->get());
    }

    public function testWithAggregateEmbed()
    {
        EloquentWithAggregateModel1::create(['id' => 1]);
        $one = EloquentWithAggregateModel1::create(['id' => 2]);
        $one->embeddeds()->create(['value' => 4]);
        $one->embeddeds()->create(['value' => 6]);

        $results = EloquentWithAggregateModel1::withCount('embeddeds')->select('id')->where('id', 2);
        self::assertSameResults([
            ['id' => 2, 'embeddeds_count' => 2],
        ], $results->get());

        $results = EloquentWithAggregateModel1::withMax('embeddeds', 'value')->select('id')->where('id', 2);
        self::assertSameResults([
            ['id' => 2, 'embeddeds_max' => 6],
        ], $results->get());

        $results = EloquentWithAggregateModel1::withMin('embeddeds', 'value')->select('id')->where('id', 2);
        self::assertSameResults([
            ['id' => 2, 'embeddeds_min' => 4],
        ], $results->get());

        $results = EloquentWithAggregateModel1::withAvg('embeddeds', 'value')->select('id')->where('id', 2);
        self::assertSameResults([
            ['id' => 2, 'embeddeds_avg' => 5.0],
        ], $results->get());
    }

    public function testWithAggregateFiltered()
    {
        EloquentWithAggregateModel1::create(['id' => 1]);
        $one = EloquentWithAggregateModel1::create(['id' => 2]);
        $one->twos()->create(['value' => 4]);
        $one->twos()->create(['value' => 6]);
        $one->twos()->create(['value' => 8]);
        $filter = static function (Builder $query) {
            $query->where('value', '<=', 6);
        };

        $results = EloquentWithAggregateModel1::withCount(['twos' => $filter])->where('id', 2);
        self::assertSameResults([
            ['id' => 2, 'twos_count' => 2],
        ], $results->get());

        $results = EloquentWithAggregateModel1::withMax(['twos' => $filter], 'value')->where('id', 2);
        self::assertSameResults([
            ['id' => 2, 'twos_max' => 6],
        ], $results->get());

        $results = EloquentWithAggregateModel1::withMin(['twos' => $filter], 'value')->where('id', 2);
        self::assertSameResults([
            ['id' => 2, 'twos_min' => 4],
        ], $results->get());

        $results = EloquentWithAggregateModel1::withAvg(['twos' => $filter], 'value')->where('id', 2);
        self::assertSameResults([
            ['id' => 2, 'twos_avg' => 5.0],
        ], $results->get());
    }

    public function testWithAggregateEmbedFiltered()
    {
        self::markTestSkipped('EmbedsMany does not support filtering. $filter requires an expression but the Query Builder generates query predicates.');

        EloquentWithAggregateModel1::create(['id' => 1]);
        $one = EloquentWithAggregateModel1::create(['id' => 2]);
        $one->embeddeds()->create(['value' => 4]);
        $one->embeddeds()->create(['value' => 6]);
        $one->embeddeds()->create(['value' => 8]);
        $filter = static function (Builder $query) {
            $query->where('value', '<=', 6);
        };

        $results = EloquentWithAggregateModel1::withCount(['embeddeds' => $filter])->where('id', 2);
        self::assertSameResults([
            ['id' => 2, 'embeddeds_count' => 2],
        ], $results->get());

        $results = EloquentWithAggregateModel1::withMax(['embeddeds' => $filter], 'value')->where('id', 2);
        self::assertSameResults([
            ['id' => 2, 'embeddeds_max' => 6],
        ], $results->get());

        $results = EloquentWithAggregateModel1::withMin(['embeddeds' => $filter], 'value')->where('id', 2);
        self::assertSameResults([
            ['id' => 2, 'embeddeds_min' => 4],
        ], $results->get());

        $results = EloquentWithAggregateModel1::withAvg(['embeddeds' => $filter], 'value')->where('id', 2);
        self::assertSameResults([
            ['id' => 2, 'embeddeds_avg' => 5.0],
        ], $results->get());
    }

    public function testWithAggregateMultipleResults()
    {
        $connection = DB::connection('mongodb');
        $ones = [
            EloquentWithAggregateModel1::create(['id' => 1]),
            EloquentWithAggregateModel1::create(['id' => 2]),
            EloquentWithAggregateModel1::create(['id' => 3]),
            EloquentWithAggregateModel1::create(['id' => 4]),
        ];

        $ones[0]->twos()->create(['value' => 1]);
        $ones[0]->twos()->create(['value' => 2]);
        $ones[0]->twos()->create(['value' => 3]);
        $ones[0]->twos()->create(['value' => 1]);
        $ones[2]->twos()->create(['value' => 1]);
        $ones[2]->twos()->create(['value' => 2]);

        $connection->enableQueryLog();

        // Count
        $results = EloquentWithAggregateModel1::withCount([
            'twos' => function ($query) {
                $query->where('value', '>=', 2);
            },
        ]);

        self::assertSameResults([
            ['id' => 1, 'twos_count' => 2],
            ['id' => 2, 'twos_count' => 0],
            ['id' => 3, 'twos_count' => 1],
            ['id' => 4, 'twos_count' => 0],
        ], $results->get());

        self::assertSame(2, count($connection->getQueryLog()));
        $connection->flushQueryLog();

        // Max
        $results = EloquentWithAggregateModel1::withMax([
            'twos' => function ($query) {
                $query->where('value', '>=', 2);
            },
        ], 'value');

        self::assertSameResults([
            ['id' => 1, 'twos_max' => 3],
            ['id' => 2, 'twos_max' => null],
            ['id' => 3, 'twos_max' => 2],
            ['id' => 4, 'twos_max' => null],
        ], $results->get());

        self::assertSame(2, count($connection->getQueryLog()));
        $connection->flushQueryLog();

        // Min
        $results = EloquentWithAggregateModel1::withMin([
            'twos' => function ($query) {
                $query->where('value', '>=', 2);
            },
        ], 'value');

        self::assertSameResults([
            ['id' => 1, 'twos_min' => 2],
            ['id' => 2, 'twos_min' => null],
            ['id' => 3, 'twos_min' => 2],
            ['id' => 4, 'twos_min' => null],
        ], $results->get());

        self::assertSame(2, count($connection->getQueryLog()));
        $connection->flushQueryLog();

        // Avg
        $results = EloquentWithAggregateModel1::withAvg([
            'twos' => function ($query) {
                $query->where('value', '>=', 2);
            },
        ], 'value');

        self::assertSameResults([
            ['id' => 1, 'twos_avg' => 2.5],
            ['id' => 2, 'twos_avg' => null],
            ['id' => 3, 'twos_avg' => 2.0],
            ['id' => 4, 'twos_avg' => null],
        ], $results->get());

        self::assertSame(2, count($connection->getQueryLog()));
        $connection->flushQueryLog();
    }

    public function testGlobalScopes()
    {
        $one = EloquentWithAggregateModel1::create();
        $one->fours()->create();

        $result = EloquentWithAggregateModel1::withCount('fours')->first();
        self::assertSame(0, $result->fours_count);

        $result = EloquentWithAggregateModel1::withCount('allFours')->first();
        self::assertSame(1, $result->all_fours_count);
    }

    private static function assertSameResults(array $expected, Collection $collection)
    {
        $actual = $collection->toArray();

        foreach ($actual as &$item) {
            ksort($item);
        }

        foreach ($expected as &$item) {
            ksort($item);
        }

        self::assertSame($expected, $actual);
    }
}
