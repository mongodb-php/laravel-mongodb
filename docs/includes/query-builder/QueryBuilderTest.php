<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\Collection;
use MongoDB\Driver\ReadPreference;
use MongoDB\Laravel\Tests\TestCase;

use function file_get_contents;
use function json_decode;

class QueryBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $db = DB::connection('mongodb');
        $db->table('movies')
            ->insert(json_decode(file_get_contents(__DIR__ . '/sample_mflix.movies.json'), true));
    }

    protected function importTheaters(): void
    {
        $db = DB::connection('mongodb');

        $db->table('theaters')
            ->insert(json_decode(file_get_contents(__DIR__ . '/sample_mflix.theaters.json'), true));

        $db->table('theaters')
            ->raw()
            ->createIndex(['location.geo' => '2dsphere']);
    }

    protected function tearDown(): void
    {
        $db = DB::connection('mongodb');
        $db->table('movies')->raw()->drop();
        $db->table('theaters')->raw()->drop();

        parent::tearDown();
    }

    public function testOptions(): void
    {
        // begin options
        $result = DB::connection('mongodb')
            ->table('movies')
            ->where('year', 2000)
            ->options(['comment' => 'hello'])
            ->get();
        // end options

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testWhere(): void
    {
        // begin query where
        $result = DB::connection('mongodb')
            ->table('movies')
            ->where('imdb.rating', 9.3)
            ->get();
        // end query where

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testOrWhere(): void
    {
        // begin query orWhere
        $result = DB::connection('mongodb')
            ->table('movies')
            ->where('id', new ObjectId('573a1398f29313caabce9682'))
            ->orWhere('title', 'Back to the Future')
            ->get();
        // end query orWhere

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testAndWhere(): void
    {
        // begin query andWhere
        $result = DB::connection('mongodb')
            ->table('movies')
            ->where('imdb.rating', '>', 8.5)
            ->where('year', '<', 1940)
            ->get();
        // end query andWhere

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testWhereNot(): void
    {
        // begin query whereNot
        $result = DB::connection('mongodb')
            ->table('movies')
            ->whereNot('imdb.rating', '>', 2)
            ->get();
        // end query whereNot

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testNestedLogical(): void
    {
        // begin query nestedLogical
        $result = DB::connection('mongodb')
            ->table('movies')
            ->where('imdb.rating', '>', 8.5)
            ->where(function (Builder $query) {
                return $query
                    ->where('year', 1986)
                    ->orWhere('year', 1996);
            })->get();
        // end query nestedLogical

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testWhereBetween(): void
    {
        // begin query whereBetween
        $result = DB::connection('mongodb')
            ->table('movies')
            ->whereBetween('imdb.rating', [9, 9.5])
            ->get();
        // end query whereBetween

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testWhereNull(): void
    {
        // begin query whereNull
        $result = DB::connection('mongodb')
            ->table('movies')
            ->whereNull('runtime')
            ->get();
        // end query whereNull

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testWhereIn(): void
    {
        // begin query whereIn
        $result = DB::table('movies')
            ->whereIn('title', ['Toy Story', 'Shrek 2', 'Johnny English'])
            ->get();
        // end query whereIn

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testWhereCarbon(): void
    {
        // begin query where date
        $result = DB::connection('mongodb')
            ->table('movies')
            ->where('released', Carbon::create(2010, 1, 15))
            ->get();
        // end query where date

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testWhereDate(): void
    {
        // begin query whereDate string
        $result = DB::connection('mongodb')
            ->table('movies')
            ->whereDate('released', '2010-1-15')
            ->get();
        // end query whereDate string

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testLike(): void
    {
        // begin query like
        $result = DB::table('movies')
            ->where('title', 'like', '%spider_man%')
            ->get();
        // end query like

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testDistinct(): void
    {
        // begin query distinct
        $result = DB::table('movies')
            ->distinct('year')->get();
        // end query distinct

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testGroupBy(): void
    {
        // begin query groupBy
        $result = DB::table('movies')
           ->where('rated', 'G')
           ->groupBy('runtime')
           ->orderBy('runtime', 'asc')
           ->get(['title']);
        // end query groupBy

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testAggCount(): void
    {
        // begin aggregation count
        $result = DB::table('movies')
            ->count();
        // end aggregation count

        $this->assertIsInt($result);
    }

    public function testAggMax(): void
    {
        // begin aggregation max
        $result = DB::table('movies')
            ->max('runtime');
        // end aggregation max

        $this->assertIsInt($result);
    }

    public function testAggMin(): void
    {
        // begin aggregation min
        $result = DB::table('movies')
            ->min('year');
        // end aggregation min

        $this->assertIsInt($result);
    }

    public function testAggAvg(): void
    {
        // begin aggregation avg
        $result = DB::table('movies')
            ->avg('imdb.rating');
        // end aggregation avg

        $this->assertIsFloat($result);
    }

    public function testAggSum(): void
    {
        // begin aggregation sum
        $result = DB::table('movies')
            ->sum('imdb.votes');
        // end aggregation sum

        $this->assertIsInt($result);
    }

    public function testAggWithFilter(): void
    {
        // begin aggregation with filter
        $result = DB::table('movies')
            ->where('year', '>', 2000)
            ->avg('imdb.rating');
        // end aggregation with filter

        $this->assertIsFloat($result);
    }

    public function testOrderBy(): void
    {
        // begin query orderBy
        $result = DB::table('movies')
            ->where('title', 'like', 'back to the future%')
            ->orderBy('imdb.rating', 'desc')
            ->get();
        // end query orderBy

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testSkip(): void
    {
        // begin query skip
        $result = DB::table('movies')
            ->where('title', 'like', 'star trek%')
            ->orderBy('year', 'asc')
            ->skip(4)
            ->get();
        // end query skip

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testProjection(): void
    {
        // begin query projection
        $result = DB::table('movies')
            ->where('imdb.rating', '>', 8.5)
            ->project([
                'title' => 1,
                'cast' => ['$slice' => [1, 3]],
            ])
            ->get();
        // end query projection

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testProjectionWithPagination(): void
    {
        // begin query projection with pagination
        $resultsPerPage = 15;
        $projectionFields = ['title', 'runtime', 'imdb.rating'];

        $result = DB::table('movies')
            ->orderBy('imdb.votes', 'desc')
            ->paginate($resultsPerPage, $projectionFields);
        // end query projection with pagination

        $this->assertInstanceOf(AbstractPaginator::class, $result);
    }

    public function testExists(): void
    {
        // begin query exists
        $result = DB::table('movies')
            ->exists('random_review', true);
        // end query exists

        $this->assertIsBool($result);
    }

    public function testAll(): void
    {
        // begin query all
        $result = DB::table('movies')
            ->where('movies', 'all', ['title', 'rated', 'imdb.rating'])
            ->get();
        // end query all

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testSize(): void
    {
        // begin query size
        $result = DB::table('movies')
            ->where('directors', 'size', 5)
            ->get();
        // end query size

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testType(): void
    {
        // begin query type
        $result = DB::table('movies')
            ->where('released', 'type', 4)
            ->get();
        // end query type

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testMod(): void
    {
        // begin query modulo
        $result = DB::table('movies')
            ->where('year', 'mod', [2, 0])
            ->get();
        // end query modulo

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testWhereRegex(): void
    {
        // begin query whereRegex
        $result = DB::connection('mongodb')
            ->table('movies')
            ->where('title', 'REGEX', new Regex('^the lord of .*', 'i'))
            ->get();
        // end query whereRegex

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testWhereLike(): void
    {
        // begin query whereLike
        $result = DB::connection('mongodb')
            ->table('movies')
            ->whereLike('title', 'Start%', true)
            ->get();
        // end query whereLike

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testWhereRaw(): void
    {
        // begin query raw
        $result = DB::table('movies')
            ->whereRaw([
                'imdb.votes' => ['$gte' => 1000 ],
                '$or' => [
                    ['imdb.rating' => ['$gt' => 7]],
                    ['directors' => ['$in' => [ 'Yasujiro Ozu', 'Sofia Coppola', 'Federico Fellini' ]]],
                ],
            ])->get();
        // end query raw

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testElemMatch(): void
    {
        // begin query elemMatch
        $result = DB::table('movies')
            ->where('writers', 'elemMatch', ['$in' => ['Maya Forbes', 'Eric Roth']])
            ->get();
        // end query elemMatch

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testCursorTimeout(): void
    {
        // begin query cursor timeout
        $result = DB::table('movies')
            ->timeout(2) // value in seconds
            ->where('year', 2001)
            ->get();
        // end query cursor timeout

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testReadPreference(): void
    {
        // begin query read pref
        $result = DB::table('movies')
            ->where('runtime', '>', 240)
            ->readPreference(ReadPreference::SECONDARY_PREFERRED)
            ->get();
        // end query read pref

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function testNear(): void
    {
        $this->importTheaters();

       // begin query near
        $results = DB::table('theaters')
            ->where('location.geo', 'near', [
                '$geometry' => [
                    'type' => 'Point',
                    'coordinates' => [
                        -86.6423,
                        33.6054,
                    ],
                ],
                '$maxDistance' => 50,
            ])->get();
        // end query near

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    public function testGeoWithin(): void
    {
        // begin query geoWithin
        $results = DB::table('theaters')
            ->where('location.geo', 'geoWithin', [
                '$geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [
                        [
                            [-72, 40],
                            [-74, 41],
                            [-72, 39],
                            [-72, 40],
                        ],
                    ],
                ],
            ])->get();
        // end query geoWithin

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    public function testGeoIntersects(): void
    {
        // begin query geoIntersects
        $results = DB::table('theaters')
            ->where('location.geo', 'geoIntersects', [
                '$geometry' => [
                    'type' => 'LineString',
                    'coordinates' => [
                        [-73.600525, 40.74416],
                        [-72.600525, 40.74416],
                    ],
                ],
            ])->get();
        // end query geoIntersects

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    public function testGeoNear(): void
    {
        $this->importTheaters();

        // begin query geoNear
        $results = DB::table('theaters')->raw(
            function (Collection $collection) {
                return $collection->aggregate([
                    [
                        '$geoNear' => [
                            'near' => [
                                'type' => 'Point',
                                'coordinates' => [-118.34, 34.10],
                            ],
                            'distanceField' => 'dist.calculated',
                            'maxDistance' => 500,
                            'includeLocs' => 'dist.location',
                            'spherical' => true,
                        ],
                    ],
                ]);
            },
        )->toArray();
        // end query geoNear

        $this->assertIsArray($results);
        $this->assertSame(8900, $results[0]['theaterId']);
    }

    public function testUpsert(): void
    {
        // begin upsert
        $result = DB::table('movies')
            ->upsert(
                [
                    ['title' => 'Inspector Maigret', 'recommended' => false, 'runtime' => 128],
                    ['title' => 'Petit Maman', 'recommended' => true, 'runtime' => 72],
                ],
                'title',
                'recommended',
            );
        // end upsert

        $this->assertSame(2, $result);

        $this->assertSame(119, DB::table('movies')->where('title', 'Inspector Maigret')->first()->runtime);
        $this->assertSame(false, DB::table('movies')->where('title', 'Inspector Maigret')->first()->recommended);

        $this->assertSame(true, DB::table('movies')->where('title', 'Petit Maman')->first()->recommended);
        $this->assertSame(72, DB::table('movies')->where('title', 'Petit Maman')->first()->runtime);
    }

    public function testUpdateUpsert(): void
    {
        // begin update upsert
        $result = DB::table('movies')
            ->where('title', 'Will Hunting')
            ->update(
                [
                    'plot' => 'An autobiographical movie',
                    'year' => 1998,
                    'writers' => [ 'Will Hunting' ],
                ],
                ['upsert' => true],
            );
        // end update upsert

        $this->assertIsInt($result);
    }

    public function testIncrement(): void
    {
        // begin increment
        $result = DB::table('movies')
            ->where('title', 'Field of Dreams')
            ->increment('imdb.votes', 3000);
        // end increment

        $this->assertIsInt($result);
    }

    public function testIncrementEach(): void
    {
        // begin increment each
        $result = DB::table('movies')
            ->where('title', 'Lost in Translation')
            ->incrementEach([
                'awards.wins' => 2,
                'imdb.votes' => 1050,
            ]);
        // end increment each

        $this->assertIsInt($result);
    }

    public function testDecrement(): void
    {
        // begin decrement
        $result = DB::table('movies')
            ->where('title', 'Sharknado')
            ->decrement('imdb.rating', 0.2);
        // end decrement

        $this->assertIsInt($result);
    }

    public function testDecrementEach(): void
    {
        // begin decrement each
        $result = DB::table('movies')
            ->where('title', 'Dunkirk')
            ->decrementEach([
                'metacritic' => 1,
                'imdb.rating' => 0.4,
            ]);
        // end decrement each

        $this->assertIsInt($result);
    }

    public function testPush(): void
    {
        // begin push
        $result = DB::table('movies')
            ->where('title', 'Office Space')
            ->push('cast', 'Gary Cole');
        // end push

        $this->assertIsInt($result);
    }

    public function testPull(): void
    {
        // begin pull
        $result = DB::table('movies')
            ->where('title', 'Iron Man')
            ->pull('genres', 'Adventure');
        // end pull

        $this->assertIsInt($result);
    }

    public function testUnset(): void
    {
        // begin unset
        $result = DB::table('movies')
            ->where('title', 'Final Accord')
            ->unset('tomatoes.viewer');
        // end unset

        $this->assertIsInt($result);
    }
}
