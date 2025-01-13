<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests\Query;

use ArgumentCountError;
use BadMethodCallException;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Tests\Database\DatabaseQueryBuilderTest;
use InvalidArgumentException;
use LogicException;
use Mockery as m;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\ReadPreference;
use MongoDB\Laravel\Connection;
use MongoDB\Laravel\Query\Builder;
use MongoDB\Laravel\Query\Grammar;
use MongoDB\Laravel\Query\Processor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function collect;
use function method_exists;
use function now;
use function sprintf;
use function var_export;

class BuilderTest extends TestCase
{
    #[DataProvider('provideQueryBuilderToMql')]
    public function testMql(array $expected, Closure $build, ?string $requiredMethod = null): void
    {
        if ($requiredMethod && ! method_exists(Builder::class, $requiredMethod)) {
            $this->markTestSkipped(sprintf('Method "%s::%s()" does not exist.', Builder::class, $requiredMethod));
        }

        $builder = $build(self::getBuilder());
        $this->assertInstanceOf(Builder::class, $builder);
        $mql = $builder->toMql();

        // Operations that return a Cursor expect a "typeMap" option.
        if (isset($expected['find'][1])) {
            $expected['find'][1]['typeMap'] = ['root' => 'object', 'document' => 'array'];
        }

        if (isset($expected['aggregate'][1])) {
            $expected['aggregate'][1]['typeMap'] = ['root' => 'object', 'document' => 'array'];
        }

        // Compare with assertEquals because the query can contain BSON objects.
        $this->assertEquals($expected, $mql, var_export($mql, true));
    }

    public static function provideQueryBuilderToMql(): iterable
    {
        /**
         * Builder::aggregate() and Builder::count() cannot be tested because they return the result,
         * without modifying the builder.
         */
        $date = new DateTimeImmutable('2016-07-12 15:30:00');

        yield 'select replaces previous select' => [
            ['find' => [[], ['projection' => ['bar' => 1]]]],
            fn (Builder $builder) => $builder->select('foo')->select('bar'),
        ];

        yield 'select array' => [
            ['find' => [[], ['projection' => ['foo' => 1, 'bar' => 1]]]],
            fn (Builder $builder) => $builder->select(['foo', 'bar']),
        ];

        /** @see DatabaseQueryBuilderTest::testAddingSelects */
        yield 'addSelect' => [
            ['find' => [[], ['projection' => ['foo' => 1, 'bar' => 1, 'baz' => 1, 'boom' => 1]]]],
            fn (Builder $builder) => $builder->select('foo')
                ->addSelect('bar')
                ->addSelect(['baz', 'boom'])
                ->addSelect('bar'),
        ];

        yield 'select all' => [
            ['find' => [[], []]],
            fn (Builder $builder) => $builder->select('*'),
        ];

        yield 'find all with select' => [
            ['find' => [[], ['projection' => ['foo' => 1, 'bar' => 1]]]],
            fn (Builder $builder) => $builder->select('foo', 'bar'),
        ];

        yield 'find equals' => [
            ['find' => [['foo' => 'bar'], []]],
            fn (Builder $builder) => $builder->where('foo', 'bar'),
        ];

        yield 'find with numeric field name' => [
            ['find' => [['123' => 'bar'], []]],
            fn (Builder $builder) => $builder->where(123, 'bar'),
        ];

        yield 'where with single array of conditions' => [
            [
                'find' => [
                    [
                        '$and' => [
                            ['foo' => 1],
                            ['bar' => 2],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder->where(['foo' => 1, 'bar' => 2]),
        ];

        yield 'find > date' => [
            ['find' => [['foo' => ['$gt' => new UTCDateTime($date)]], []]],
            fn (Builder $builder) => $builder->where('foo', '>', $date),
        ];

        /** @see DatabaseQueryBuilderTest::testBasicWhereIns */
        yield 'whereIn' => [
            ['find' => [['foo' => ['$in' => ['bar', 'baz']]], []]],
            fn (Builder $builder) => $builder->whereIn('foo', ['bar', 'baz']),
        ];

        // Nested array are not flattened like in the Eloquent builder. MongoDB can compare objects.
        yield 'whereIn nested array' => [
            ['find' => [['_id' => ['$in' => [['issue' => 45582], ['_id' => 2], [3]]]], []]],
            fn (Builder $builder) => $builder->whereIn('id', [['issue' => 45582], ['id' => 2], [3]]),
        ];

        yield 'orWhereIn' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['foo' => 1],
                            ['foo' => ['$in' => [1, 2, 3]]],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder->where('foo', '=', 1)
                ->orWhereIn('foo', [1, 2, 3]),
        ];

        /** @see DatabaseQueryBuilderTest::testBasicWhereNotIns */
        yield 'whereNotIn' => [
            ['find' => [['foo' => ['$nin' => [1, 2, 3]]], []]],
            fn (Builder $builder) => $builder->whereNotIn('foo', [1, 2, 3]),
        ];

        yield 'orWhereNotIn' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['foo' => 1],
                            ['foo' => ['$nin' => [1, 2, 3]]],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder->where('foo', '=', 1)
                ->orWhereNotIn('foo', [1, 2, 3]),
        ];

        /** @see DatabaseQueryBuilderTest::testEmptyWhereIns */
        yield 'whereIn empty array' => [
            ['find' => [['_id' => ['$in' => []]], []]],
            fn (Builder $builder) => $builder->whereIn('id', []),
        ];

        yield 'find limit offset select' => [
            ['find' => [[], ['limit' => 10, 'skip' => 5, 'projection' => ['foo' => 1, 'bar' => 1]]]],
            fn (Builder $builder) => $builder->limit(10)->offset(5)->select('foo', 'bar'),
        ];

        yield 'where accepts $ in operators' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['foo' => ['$type' => 2]],
                            ['foo' => ['$type' => 4]],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder
                ->where('foo', '$type', 2)
                ->orWhere('foo', '$type', 4),
        ];

        /** @see DatabaseQueryBuilderTest::testBasicWhereNot() */
        yield 'whereNot (multiple)' => [
            [
                'find' => [
                    [
                        '$and' => [
                            ['$nor' => [['name' => 'foo']]],
                            ['$nor' => [['name' => ['$ne' => 'bar']]]],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder
                ->whereNot('name', 'foo')
                ->whereNot('name', '<>', 'bar'),
        ];

        /** @see DatabaseQueryBuilderTest::testBasicOrWheres() */
        yield 'where orWhere' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['age' => 1],
                            ['email' => 'foo'],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder
                ->where('age', '=', 1)
                ->orWhere('email', '=', 'foo'),
        ];

        /** @see DatabaseQueryBuilderTest::testBasicOrWhereNot() */
        yield 'orWhereNot' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['$nor' => [['name' => 'foo']]],
                            ['$nor' => [['name' => ['$ne' => 'bar']]]],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder
                ->orWhereNot('name', 'foo')
                ->orWhereNot('name', '<>', 'bar'),
        ];

        yield 'whereNot orWhere' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['$nor' => [['name' => 'foo']]],
                            ['name' => ['$ne' => 'bar']],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder
                ->whereNot('name', 'foo')
                ->orWhere('name', '<>', 'bar'),
        ];

        /** @see DatabaseQueryBuilderTest::testWhereNot() */
        yield 'whereNot callable' => [
            [
                'find' => [
                    ['$nor' => [['name' => 'foo']]],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder
                ->whereNot(fn (Builder $q) => $q->where('name', 'foo')),
        ];

        yield 'where whereNot' => [
            [
                'find' => [
                    [
                        '$and' => [
                            ['name' => 'bar'],
                            ['$nor' => [['email' => 'foo']]],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder
                ->where('name', '=', 'bar')
                ->whereNot(function (Builder $q) {
                    $q->where('email', '=', 'foo');
                }),
        ];

        yield 'whereNot (nested)' => [
            [
                'find' => [
                    [
                        '$nor' => [
                            [
                                '$and' => [
                                    ['name' => 'foo'],
                                    ['$nor' => [['email' => ['$ne' => 'bar']]]],
                                ],
                            ],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder
                ->whereNot(function (Builder $q) {
                    $q->where('name', '=', 'foo')
                      ->whereNot('email', '<>', 'bar');
                }),
        ];

        yield 'orWhere orWhereNot' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['name' => 'bar'],
                            ['$nor' => [['email' => 'foo']]],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder
                ->orWhere('name', '=', 'bar')
                ->orWhereNot(function (Builder $q) {
                    $q->where('email', '=', 'foo');
                }),
        ];

        yield 'where orWhereNot' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['name' => 'bar'],
                            ['$nor' => [['email' => 'foo']]],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder
                ->where('name', '=', 'bar')
                ->orWhereNot('email', '=', 'foo'),
        ];

        /** @see DatabaseQueryBuilderTest::testWhereNotWithArrayConditions() */
        yield 'whereNot with arrays of single condition' => [
            [
                'find' => [
                    [
                        '$nor' => [
                            [
                                '$and' => [
                                    ['foo' => 1],
                                    ['bar' => 2],
                                ],
                            ],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder
                ->whereNot([['foo', 1], ['bar', 2]]),
        ];

        yield 'whereNot with single array of conditions' => [
            [
                'find' => [
                    [
                        '$nor' => [
                            [
                                '$and' => [
                                    ['foo' => 1],
                                    ['bar' => 2],
                                ],
                            ],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder
                ->whereNot(['foo' => 1, 'bar' => 2]),
        ];

        yield 'whereNot with arrays of single condition with operator' => [
            [
                'find' => [
                    [
                        '$nor' => [
                            [
                                '$and' => [
                                    ['foo' => 1],
                                    ['bar' => ['$lt' => 2]],
                                ],
                            ],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder
                ->whereNot([
                    ['foo', 1],
                    ['bar', '<', 2],
                ]),
        ];

        yield 'where all' => [
            ['find' => [['tags' => ['$all' => ['ssl', 'security']]], []]],
            fn (Builder $builder) => $builder->where('tags', 'all', ['ssl', 'security']),
        ];

        yield 'where all nested operators' => [
            [
                'find' => [
                    [
                        'tags' => [
                            '$all' => [
                                ['$elemMatch' => ['size' => 'M', 'num' => ['$gt' => 50]]],
                                ['$elemMatch' => ['num' => 100, 'color' => 'green']],
                            ],
                        ],
                    ],
                    [],
                ],
            ],
            fn (Builder $builder) => $builder->where('tags', 'all', [
                ['$elemMatch' => ['size' => 'M', 'num' => ['$gt' => 50]]],
                ['$elemMatch' => ['num' => 100, 'color' => 'green']],
            ]),
        ];

        /** @see DatabaseQueryBuilderTest::testForPage() */
        yield 'forPage' => [
            ['find' => [[], ['limit' => 20, 'skip' => 40]]],
            fn (Builder $builder) => $builder->forPage(3, 20),
        ];

        /** @see DatabaseQueryBuilderTest::testLimitsAndOffsets() */
        yield 'offset limit' => [
            ['find' => [[], ['skip' => 5, 'limit' => 10]]],
            fn (Builder $builder) => $builder->offset(5)->limit(10),
        ];

        yield 'offset limit zero (unset)' => [
            ['find' => [[], []]],
            fn (Builder $builder) => $builder
                ->offset(0)->limit(0),
        ];

        yield 'offset limit zero (reset)' => [
            ['find' => [[], []]],
            fn (Builder $builder) => $builder
                ->offset(5)->limit(10)
                ->offset(0)->limit(0),
        ];

        yield 'offset limit negative (unset)' => [
            ['find' => [[], []]],
            fn (Builder $builder) => $builder
                ->offset(-5)->limit(-10),
        ];

        yield 'offset limit null (reset)' => [
            ['find' => [[], []]],
            fn (Builder $builder) => $builder
                ->offset(5)->limit(10)
                ->offset(null)->limit(null),
        ];

        yield 'skip take (aliases)' => [
            ['find' => [[], ['skip' => 5, 'limit' => 10]]],
            fn (Builder $builder) => $builder->skip(5)->limit(10),
        ];

        /** @see DatabaseQueryBuilderTest::testOrderBys() */
        yield 'orderBy multiple columns' => [
            ['find' => [[], ['sort' => ['email' => 1, 'age' => -1]]]],
            fn (Builder $builder) => $builder
                ->orderBy('email')
                ->orderBy('age', 'desc'),
        ];

        yield 'orders by id field' => [
            ['find' => [[], ['sort' => ['_id' => 1]]]],
            fn (Builder $builder) => $builder->orderBy('id'),
        ];

        yield 'orders = null' => [
            ['find' => [[], []]],
            function (Builder $builder) {
                $builder->orders = null;

                return $builder;
            },
        ];

        yield 'orders = []' => [
            ['find' => [[], []]],
            function (Builder $builder) {
                $builder->orders = [];

                return $builder;
            },
        ];

        yield 'multiple orders with direction' => [
            ['find' => [[], ['sort' => ['email' => -1, 'age' => 1]]]],
            fn (Builder $builder) => $builder
                ->orderBy('email', -1)
                ->orderBy('age', 1),
        ];

        yield 'orderByDesc' => [
            ['find' => [[], ['sort' => ['email' => -1]]]],
            fn (Builder $builder) => $builder->orderByDesc('email'),
        ];

        /** @see DatabaseQueryBuilderTest::testReorder() */
        yield 'reorder reset' => [
            ['find' => [[], []]],
            fn (Builder $builder) => $builder->orderBy('name')->reorder(),
        ];

        yield 'reorder column' => [
            ['find' => [[], ['sort' => ['name' => -1]]]],
            fn (Builder $builder) => $builder->orderBy('name')->reorder('name', 'desc'),
        ];

        /** @link https://www.mongodb.com/docs/manual/reference/method/cursor.sort/#text-score-metadata-sort */
        yield 'orderBy array meta' => [
            [
                'find' => [
                    ['$text' => ['$search' => 'operating']],
                    ['sort' => ['score' => ['$meta' => 'textScore']]],
                ],
            ],
            fn (Builder $builder) => $builder
                ->where('$text', ['$search' => 'operating'])
                ->orderBy('score', ['$meta' => 'textScore']),
        ];

        /** @see DatabaseQueryBuilderTest::testWhereBetweens() */
        yield 'whereBetween array of numbers' => [
            ['find' => [['_id' => ['$gte' => 1, '$lte' => 2]], []]],
            fn (Builder $builder) => $builder->whereBetween('id', [1, 2]),
        ];

        yield 'whereBetween nested array of numbers' => [
            ['find' => [['_id' => ['$gte' => [1], '$lte' => [2, 3]]], []]],
            fn (Builder $builder) => $builder->whereBetween('id', [[1], [2, 3]]),
        ];

        $date = new DateTimeImmutable('2018-09-30 15:00:00 +02:00');
        yield 'where $lt DateTimeInterface' => [
            ['find' => [['created_at' => ['$lt' => new UTCDateTime($date)]], []]],
            fn (Builder $builder) => $builder->where('created_at', '<', $date),
        ];

        $period = now()->toPeriod(now()->addMonth());
        yield 'whereBetween CarbonPeriod' => [
            [
                'find' => [
                    [
                        'created_at' => [
                            '$gte' => new UTCDateTime($period->getStartDate()),
                            '$lte' => new UTCDateTime($period->getEndDate()),
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder->whereBetween('created_at', $period),
        ];

        yield 'whereBetween collection' => [
            ['find' => [['_id' => ['$gte' => 1, '$lte' => 2]], []]],
            fn (Builder $builder) => $builder->whereBetween('id', collect([1, 2])),
        ];

        /** @see DatabaseQueryBuilderTest::testOrWhereBetween() */
        yield 'orWhereBetween array of numbers' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['age' => 1],
                            ['age' => ['$gte' => 3, '$lte' => 5]],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder
                ->where('age', '=', 1)
                ->orWhereBetween('age', [3, 5]),
        ];

        /** @link https://www.mongodb.com/docs/manual/reference/bson-type-comparison-order/#arrays */
        yield 'orWhereBetween nested array of numbers' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['age' => 1],
                            ['age' => ['$gte' => [4], '$lte' => [6, 8]]],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder
                ->where('age', '=', 1)
                ->orWhereBetween('age', [[4], [6, 8]]),
        ];

        yield 'orWhereBetween collection' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['age' => 1],
                            ['age' => ['$gte' => 3, '$lte' => 4]],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder
                ->where('age', '=', 1)
                ->orWhereBetween('age', collect([3, 4])),
        ];

        yield 'whereNotBetween array of numbers' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['age' => ['$lte' => 1]],
                            ['age' => ['$gte' => 2]],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder->whereNotBetween('age', [1, 2]),
        ];

        /** @see DatabaseQueryBuilderTest::testOrWhereNotBetween() */
        yield 'orWhereNotBetween array of numbers' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['age' => 1],
                            [
                                '$or' => [
                                    ['age' => ['$lte' => 3]],
                                    ['age' => ['$gte' => 5]],
                                ],
                            ],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder
                ->where('age', '=', 1)
                ->orWhereNotBetween('age', [3, 5]),
        ];

        yield 'orWhereNotBetween nested array of numbers' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['age' => 1],
                            [
                                '$or' => [
                                    ['age' => ['$lte' => [2, 3]]],
                                    ['age' => ['$gte' => [5]]],
                                ],
                            ],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder
                ->where('age', '=', 1)
                ->orWhereNotBetween('age', [[2, 3], [5]]),
        ];

        yield 'orWhereNotBetween collection' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['age' => 1],
                            [
                                '$or' => [
                                    ['age' => ['$lte' => 3]],
                                    ['age' => ['$gte' => 4]],
                                ],
                            ],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder
                ->where('age', '=', 1)
                ->orWhereNotBetween('age', collect([3, 4])),
        ];

        yield 'where like' => [
            ['find' => [['name' => new Regex('^acme$', 'i')], []]],
            fn (Builder $builder) => $builder->where('name', 'like', 'acme'),
        ];

        yield 'where ilike' => [ // Alias for like
            ['find' => [['name' => new Regex('^acme$', 'i')], []]],
            fn (Builder $builder) => $builder->where('name', 'ilike', 'acme'),
        ];

        yield 'where like escape' => [
            ['find' => [['name' => new Regex('^\^ac\.me\$$', 'i')], []]],
            fn (Builder $builder) => $builder->where('name', 'like', '^ac.me$'),
        ];

        yield 'where like unescaped \% \_' => [
            ['find' => [['name' => new Regex('^a%cm_e$', 'i')], []]],
            fn (Builder $builder) => $builder->where('name', 'like', 'a\%cm\_e'),
        ];

        yield 'where like %' => [
            ['find' => [['name' => new Regex('^.*ac.*me.*$', 'i')], []]],
            fn (Builder $builder) => $builder->where('name', 'like', '%ac%%me%'),
        ];

        yield 'where like _' => [
            ['find' => [['name' => new Regex('^.ac..me.$', 'i')], []]],
            fn (Builder $builder) => $builder->where('name', 'like', '_ac__me_'),
        ];

        yield 'whereLike' => [
            ['find' => [['name' => new Regex('^1$', 'i')], []]],
            fn
        (Builder $builder) => $builder->whereLike('name', '1'),
            'whereLike',
        ];

        yield 'whereLike case not sensitive' => [
            ['find' => [['name' => new Regex('^1$', 'i')], []]],
            fn
        (Builder $builder) => $builder->whereLike('name', '1', false),
            'whereLike',
        ];

        yield 'whereLike case sensitive' => [
            ['find' => [['name' => new Regex('^1$', '')], []]],
            fn
        (Builder $builder) => $builder->whereLike('name', '1', true),
            'whereLike',
        ];

        yield 'whereNotLike' => [
            ['find' => [['name' => ['$not' => new Regex('^1$', 'i')]], []]],
            fn
        (Builder $builder) => $builder->whereNotLike('name', '1'),
            'whereNotLike',
        ];

        yield 'whereNotLike case not sensitive' => [
            ['find' => [['name' => ['$not' => new Regex('^1$', 'i')]], []]],
            fn
        (Builder $builder) => $builder->whereNotLike('name', '1', false),
            'whereNotLike',
        ];

        yield 'whereNotLike case sensitive' => [
            ['find' => [['name' => ['$not' => new Regex('^1$', '')]], []]],
            fn
        (Builder $builder) => $builder->whereNotLike('name', '1', true),
            'whereNotLike',
        ];

        $regex = new Regex('^acme$', 'si');
        yield 'where BSON\Regex' => [
            ['find' => [['name' => $regex], []]],
            fn (Builder $builder) => $builder->where('name', 'regex', $regex),
        ];

        yield 'where regexp' => [ // Alias for regex
            ['find' => [['name' => $regex], []]],
            fn (Builder $builder) => $builder->where('name', 'regex', '/^acme$/si'),
        ];

        yield 'where regex delimiter /' => [
            ['find' => [['name' => $regex], []]],
            fn (Builder $builder) => $builder->where('name', 'regex', '/^acme$/si'),
        ];

        yield 'where regex delimiter #' => [
            ['find' => [['name' => $regex], []]],
            fn (Builder $builder) => $builder->where('name', 'regex', '#^acme$#si'),
        ];

        yield 'where regex delimiter ~' => [
            ['find' => [['name' => $regex], []]],
            fn (Builder $builder) => $builder->where('name', 'regex', '#^acme$#si'),
        ];

        yield 'where regex with escaped characters' => [
            ['find' => [['name' => new Regex('a\.c\/m\+e', '')], []]],
            fn (Builder $builder) => $builder->where('name', 'regex', '/a\.c\/m\+e/'),
        ];

        yield 'where not regex' => [
            ['find' => [['name' => ['$not' => $regex]], []]],
            fn (Builder $builder) => $builder->where('name', 'not regex', '/^acme$/si'),
        ];

        yield 'where date' => [
            [
                'find' => [
                    [
                        'created_at' => [
                            '$gte' => new UTCDateTime(new DateTimeImmutable('2018-09-30 00:00:00.000 +00:00')),
                            '$lte' => new UTCDateTime(new DateTimeImmutable('2018-09-30 23:59:59.999 +00:00')),
                        ],
                    ],
                    [],
                ],
            ],
            fn (Builder $builder) => $builder->whereDate('created_at', '2018-09-30'),
        ];

        yield 'where date DateTimeImmutable' => [
            [
                'find' => [
                    [
                        'created_at' => [
                            '$gte' => new UTCDateTime(new DateTimeImmutable('2018-09-30 00:00:00.000 +00:00')),
                            '$lte' => new UTCDateTime(new DateTimeImmutable('2018-09-30 23:59:59.999 +00:00')),
                        ],
                    ],
                    [],
                ],
            ],
            fn (Builder $builder) => $builder->whereDate('created_at', '=', new DateTimeImmutable('2018-09-30 15:00:00 +02:00')),
        ];

        yield 'where date !=' => [
            [
                'find' => [
                    [
                        'created_at' => [
                            '$not' => [
                                '$gte' => new UTCDateTime(new DateTimeImmutable('2018-09-30 00:00:00.000 +00:00')),
                                '$lte' => new UTCDateTime(new DateTimeImmutable('2018-09-30 23:59:59.999 +00:00')),
                            ],
                        ],
                    ],
                    [],
                ],
            ],
            fn (Builder $builder) => $builder->whereDate('created_at', '!=', '2018-09-30'),
        ];

        yield 'where date <' => [
            [
                'find' => [
                    [
                        'created_at' => [
                            '$lt' => new UTCDateTime(new DateTimeImmutable('2018-09-30 00:00:00.000 +00:00')),
                        ],
                    ],
                    [],
                ],
            ],
            fn (Builder $builder) => $builder->whereDate('created_at', '<', '2018-09-30'),
        ];

        yield 'where date >=' => [
            [
                'find' => [
                    [
                        'created_at' => [
                            '$gte' => new UTCDateTime(new DateTimeImmutable('2018-09-30 00:00:00.000 +00:00')),
                        ],
                    ],
                    [],
                ],
            ],
            fn (Builder $builder) => $builder->whereDate('created_at', '>=', '2018-09-30'),
        ];

        yield 'where date >' => [
            [
                'find' => [
                    [
                        'created_at' => [
                            '$gt' => new UTCDateTime(new DateTimeImmutable('2018-09-30 23:59:59.999 +00:00')),
                        ],
                    ],
                    [],
                ],
            ],
            fn (Builder $builder) => $builder->whereDate('created_at', '>', '2018-09-30'),
        ];

        yield 'where date <=' => [
            [
                'find' => [
                    [
                        'created_at' => [
                            '$lte' => new UTCDateTime(new DateTimeImmutable('2018-09-30 23:59:59.999 +00:00')),
                        ],
                    ],
                    [],
                ],
            ],
            fn (Builder $builder) => $builder->whereDate('created_at', '<=', '2018-09-30'),
        ];

        yield 'where day' => [
            [
                'find' => [
                    [
                        '$expr' => [
                            '$eq' => [
                                ['$dayOfMonth' => '$created_at'],
                                5,
                            ],
                        ],
                    ],
                    [],
                ],
            ],
            fn (Builder $builder) => $builder->whereDay('created_at', 5),
        ];

        yield 'where day > string' => [
            [
                'find' => [
                    [
                        '$expr' => [
                            '$gt' => [
                                ['$dayOfMonth' => '$created_at'],
                                5,
                            ],
                        ],
                    ],
                    [],
                ],
            ],
            fn (Builder $builder) => $builder->whereDay('created_at', '>', '05'),
        ];

        yield 'where month' => [
            [
                'find' => [
                    [
                        '$expr' => [
                            '$eq' => [
                                ['$month' => '$created_at'],
                                10,
                            ],
                        ],
                    ],
                    [],
                ],
            ],
            fn (Builder $builder) => $builder->whereMonth('created_at', 10),
        ];

        yield 'where month > string' => [
            [
                'find' => [
                    [
                        '$expr' => [
                            '$gt' => [
                                ['$month' => '$created_at'],
                                5,
                            ],
                        ],
                    ],
                    [],
                ],
            ],
            fn (Builder $builder) => $builder->whereMonth('created_at', '>', '05'),
        ];

        yield 'where year' => [
            [
                'find' => [
                    [
                        '$expr' => [
                            '$eq' => [
                                ['$year' => '$created_at'],
                                2023,
                            ],
                        ],
                    ],
                    [],
                ],
            ],
            fn (Builder $builder) => $builder->whereYear('created_at', 2023),
        ];

        yield 'where year > string' => [
            [
                'find' => [
                    [
                        '$expr' => [
                            '$gt' => [
                                ['$year' => '$created_at'],
                                2023,
                            ],
                        ],
                    ],
                    [],
                ],
            ],
            fn (Builder $builder) => $builder->whereYear('created_at', '>', '2023'),
        ];

        yield 'where time HH:MM:SS' => [
            [
                'find' => [
                    [
                        '$expr' => [
                            '$eq' => [
                                ['$dateToString' => ['date' => '$created_at', 'format' => '%H:%M:%S']],
                                '10:11:12',
                            ],
                        ],
                    ],
                    [],
                ],
            ],
            fn (Builder $builder) => $builder->whereTime('created_at', '10:11:12'),
        ];

        yield 'where time HH:MM' => [
            [
                'find' => [
                    [
                        '$expr' => [
                            '$eq' => [
                                ['$dateToString' => ['date' => '$created_at', 'format' => '%H:%M']],
                                '10:11',
                            ],
                        ],
                    ],
                    [],
                ],
            ],
            fn (Builder $builder) => $builder->whereTime('created_at', '10:11'),
        ];

        yield 'where time HH' => [
            [
                'find' => [
                    [
                        '$expr' => [
                            '$eq' => [
                                ['$dateToString' => ['date' => '$created_at', 'format' => '%H']],
                                '10',
                            ],
                        ],
                    ],
                    [],
                ],
            ],
            fn (Builder $builder) => $builder->whereTime('created_at', '10'),
        ];

        yield 'where time DateTime' => [
            [
                'find' => [
                    [
                        '$expr' => [
                            '$eq' => [
                                ['$dateToString' => ['date' => '$created_at', 'format' => '%H:%M:%S']],
                                '10:11:12',
                            ],
                        ],
                    ],
                    [],
                ],
            ],
            fn (Builder $builder) => $builder->whereTime('created_at', new DateTimeImmutable('2023-08-22 10:11:12')),
        ];

        yield 'where time >' => [
            [
                'find' => [
                    [
                        '$expr' => [
                            '$gt' => [
                                ['$dateToString' => ['date' => '$created_at', 'format' => '%H:%M:%S']],
                                '10:11:12',
                            ],
                        ],
                    ],
                    [],
                ],
            ],
            fn (Builder $builder) => $builder->whereTime('created_at', '>', '10:11:12'),
        ];

        /** @see DatabaseQueryBuilderTest::testBasicSelectDistinct */
        yield 'distinct' => [
            ['distinct' => ['foo', [], []]],
            fn (Builder $builder) => $builder->distinct('foo'),
        ];

        yield 'select distinct' => [
            ['distinct' => ['foo', [], []]],
            fn (Builder $builder) => $builder->select('foo', 'bar')
                ->distinct(),
        ];

        /** @see DatabaseQueryBuilderTest::testBasicSelectDistinctOnColumns */
        yield 'select distinct on' => [
            ['distinct' => ['foo', [], []]],
            fn (Builder $builder) => $builder->distinct('foo')
                ->select('foo', 'bar'),
        ];

        /** @see DatabaseQueryBuilderTest::testLatest() */
        yield 'latest' => [
            ['find' => [[], ['sort' => ['created_at' => -1]]]],
            fn (Builder $builder) => $builder->latest(),
        ];

        yield 'latest limit' => [
            ['find' => [[], ['sort' => ['created_at' => -1], 'limit' => 1]]],
            fn (Builder $builder) => $builder->latest()->limit(1),
        ];

        yield 'latest custom field' => [
            ['find' => [[], ['sort' => ['updated_at' => -1]]]],
            fn (Builder $builder) => $builder->latest('updated_at'),
        ];

        /** @see DatabaseQueryBuilderTest::testOldest() */
        yield 'oldest' => [
            ['find' => [[], ['sort' => ['created_at' => 1]]]],
            fn (Builder $builder) => $builder->oldest(),
        ];

        yield 'oldest limit' => [
            ['find' => [[], ['sort' => ['created_at' => 1], 'limit' => 1]]],
            fn (Builder $builder) => $builder->oldest()->limit(1),
        ];

        yield 'oldest custom field' => [
            ['find' => [[], ['sort' => ['updated_at' => 1]]]],
            fn (Builder $builder) => $builder->oldest('updated_at'),
        ];

        yield 'groupBy' => [
            [
                'aggregate' => [
                    [['$group' => ['_id' => ['foo' => '$foo'], 'foo' => ['$last' => '$foo']]]],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder->groupBy('foo'),
        ];

        yield 'sub-query' => [
            [
                'find' => [
                    [
                        'filters' => [
                            '$elemMatch' => [
                                '$and' => [
                                    ['search_by' => 'by search'],
                                    ['value' => 'foo'],
                                ],
                            ],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder->where(
                'filters',
                'elemMatch',
                function (Builder $elemMatchQuery): void {
                    $elemMatchQuery->where([ 'search_by' => 'by search', 'value' => 'foo' ]);
                },
            ),
        ];

        yield 'id alias for _id' => [
            ['find' => [['_id' => 1], []]],
            fn (Builder $builder) => $builder->where('id', 1),
        ];

        yield 'id alias for _id with $or' => [
            ['find' => [['$or' => [['_id' => 1], ['_id' => 2]]], []]],
            fn (Builder $builder) => $builder->where('id', 1)->orWhere('id', 2),
        ];

        yield 'select colums with id alias' => [
            ['find' => [[], ['projection' => ['name' => 1, 'email' => 1, '_id' => 1]]]],
            fn (Builder $builder) => $builder->select('name', 'email', 'id'),
        ];

        // Method added in Laravel v10.47.0
        /** @see DatabaseQueryBuilderTest::testWhereAll */
        yield 'whereAll' => [
            [
                'find' => [
                    ['$and' => [['last_name' => 'Doe'], ['email' => 'Doe']]],
                    [], // options
                ],
            ],
            fn
            (Builder $builder) => $builder->whereAll(['last_name', 'email'], 'Doe'),
            'whereAll',
        ];

        yield 'whereAll operator' => [
            [
                'find' => [
                    [
                        '$and' => [
                            ['last_name' => ['$not' => new Regex('^.*Doe.*$', 'i')]],
                            ['email' => ['$not' => new Regex('^.*Doe.*$', 'i')]],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn
            (Builder $builder) => $builder->whereAll(['last_name', 'email'], 'not like', '%Doe%'),
            'whereAll',
        ];

        /** @see DatabaseQueryBuilderTest::testOrWhereAll */
        yield 'orWhereAll' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['first_name' => 'John'],
                            ['$and' => [['last_name' => 'Doe'], ['email' => 'Doe']]],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn
            (Builder $builder) => $builder
                ->where('first_name', 'John')
                ->orWhereAll(['last_name', 'email'], 'Doe'),
            'orWhereAll',
        ];

        yield 'orWhereAll operator' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['first_name' => new Regex('^.*John.*$', 'i')],
                            [
                                '$and' => [
                                    ['last_name' => ['$not' => new Regex('^.*Doe.*$', 'i')]],
                                    ['email' => ['$not' => new Regex('^.*Doe.*$', 'i')]],
                                ],
                            ],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn
            (Builder $builder) => $builder
                ->where('first_name', 'like', '%John%')
                ->orWhereAll(['last_name', 'email'], 'not like', '%Doe%'),
            'orWhereAll',
        ];

        // Method added in Laravel v10.47.0
        /** @see DatabaseQueryBuilderTest::testWhereAny */
        yield 'whereAny' => [
            [
                'find' => [
                    ['$or' => [['last_name' => 'Doe'], ['email' => 'Doe']]],
                    [], // options
                ],
            ],
            fn
            (Builder $builder) => $builder->whereAny(['last_name', 'email'], 'Doe'),
            'whereAny',
        ];

        yield 'whereAny operator' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['last_name' => ['$not' => new Regex('^.*Doe.*$', 'i')]],
                            ['email' => ['$not' => new Regex('^.*Doe.*$', 'i')]],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn
            (Builder $builder) => $builder->whereAny(['last_name', 'email'], 'not like', '%Doe%'),
            'whereAny',
        ];

        /** @see DatabaseQueryBuilderTest::testOrWhereAny */
        yield 'orWhereAny' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['first_name' => 'John'],
                            ['$or' => [['last_name' => 'Doe'], ['email' => 'Doe']]],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn
            (Builder $builder) => $builder
                ->where('first_name', 'John')
                ->orWhereAny(['last_name', 'email'], 'Doe'),
            'whereAny',
        ];

        yield 'orWhereAny operator' => [
            [
                'find' => [
                    [
                        '$or' => [
                            ['first_name' => new Regex('^.*John.*$', 'i')],
                            [
                                '$or' => [
                                    ['last_name' => ['$not' => new Regex('^.*Doe.*$', 'i')]],
                                    ['email' => ['$not' => new Regex('^.*Doe.*$', 'i')]],
                                ],
                            ],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn
            (Builder $builder) => $builder
                ->where('first_name', 'like', '%John%')
                ->orWhereAny(['last_name', 'email'], 'not like', '%Doe%'),
            'orWhereAny',
        ];

        yield 'raw filter with _id and date' => [
            [
                'find' => [
                    [
                        '$and' => [
                            [
                                '$or' => [
                                    ['foo._id' => 1],
                                    ['created_at' => ['$gte' => new UTCDateTime(new DateTimeImmutable('2018-09-30 00:00:00.000 +00:00'))]],
                                ],
                            ],
                            ['age' => 15],
                        ],
                    ],
                    [], // options
                ],
            ],
            fn (Builder $builder) => $builder->where([
                '$or' => [
                    ['foo.id' => 1],
                    ['created_at' => ['$gte' => new DateTimeImmutable('2018-09-30 00:00:00 +00:00')]],
                ],
            ])->where('age', 15),
        ];

        yield 'arrow notation' => [
            ['find' => [['data.format' => 1], []]],
            fn (Builder $builder) => $builder->where('data->format', 1),
        ];

        yield 'arrow notation with id' => [
            ['find' => [['embedded._id' => 1], []]],
            fn (Builder $builder) => $builder->where('embedded->id', 1),
        ];

        yield 'options' => [
            ['find' => [[], ['comment' => 'hello']]],
            fn (Builder $builder) => $builder->options(['comment' => 'hello']),
        ];

        yield 'readPreference' => [
            ['find' => [[], ['readPreference' => new ReadPreference(ReadPreference::SECONDARY_PREFERRED)]]],
            fn (Builder $builder) => $builder->readPreference(ReadPreference::SECONDARY_PREFERRED),
        ];

        yield 'readPreference advanced' => [
            ['find' => [[], ['readPreference' => new ReadPreference(ReadPreference::NEAREST, [['dc' => 'ny']], ['maxStalenessSeconds' => 120])]]],
            fn (Builder $builder) => $builder->readPreference(ReadPreference::NEAREST, [['dc' => 'ny']], ['maxStalenessSeconds' => 120]),
        ];

        yield 'hint' => [
            ['find' => [[], ['hint' => ['foo' => 1]]]],
            fn (Builder $builder) => $builder->hint(['foo' => 1]),
        ];

        yield 'timeout' => [
            ['find' => [[], ['maxTimeMS' => 2345]]],
            fn (Builder $builder) => $builder->timeout(2.3456),
        ];
    }

    #[DataProvider('provideExceptions')]
    public function testException($class, $message, Closure $build): void
    {
        $builder = self::getBuilder();

        $this->expectException($class);
        $this->expectExceptionMessage($message);
        $build($builder)->toMQL();
    }

    public static function provideExceptions(): iterable
    {
        yield 'orderBy invalid direction' => [
            InvalidArgumentException::class,
            'Order direction must be "asc" or "desc"',
            fn (Builder $builder) => $builder->orderBy('_id', 'dasc'),
        ];

        /** @see DatabaseQueryBuilderTest::testWhereBetweens */
        yield 'whereBetween array too short' => [
            InvalidArgumentException::class,
            'Between $values must be a list with exactly two elements: [min, max]',
            fn (Builder $builder) => $builder->whereBetween('id', [1]),
        ];

        yield 'whereBetween array too short (nested)' => [
            InvalidArgumentException::class,
            'Between $values must be a list with exactly two elements: [min, max]',
            fn (Builder $builder) => $builder->whereBetween('id', [[1, 2]]),
        ];

        yield 'whereBetween array too long' => [
            InvalidArgumentException::class,
            'Between $values must be a list with exactly two elements: [min, max]',
            fn (Builder $builder) => $builder->whereBetween('id', [1, 2, 3]),
        ];

        yield 'whereBetween collection too long' => [
            InvalidArgumentException::class,
            'Between $values must be a list with exactly two elements: [min, max]',
            fn (Builder $builder) => $builder->whereBetween('id', new Collection([1, 2, 3])),
        ];

        yield 'whereBetween array is not a list' => [
            InvalidArgumentException::class,
            'Between $values must be a list with exactly two elements: [min, max]',
            fn (Builder $builder) => $builder->whereBetween('id', ['min' => 1, 'max' => 2]),
        ];

        yield 'find with single string argument' => [
            ArgumentCountError::class,
            'Too few arguments to function MongoDB\Laravel\Query\Builder::where(\'foo\'), 1 passed and at least 2 expected when the 1st is not an array',
            fn (Builder $builder) => $builder->where('foo'),
        ];

        yield 'find with single numeric argument' => [
            ArgumentCountError::class,
            'Too few arguments to function MongoDB\Laravel\Query\Builder::where(123), 1 passed and at least 2 expected when the 1st is not an array',
            fn (Builder $builder) => $builder->where(123),
        ];

        yield 'where regex not starting with /' => [
            LogicException::class,
            'Missing expected starting delimiter in regular expression "^ac/me$", supported delimiters are: / # ~',
            fn (Builder $builder) => $builder->where('name', 'regex', '^ac/me$'),
        ];

        yield 'where regex not ending with /' => [
            LogicException::class,
            'Missing expected ending delimiter "/" in regular expression "/foo#bar"',
            fn (Builder $builder) => $builder->where('name', 'regex', '/foo#bar'),
        ];

        yield 'whereTime with invalid time' => [
            InvalidArgumentException::class,
            'Invalid time format, expected HH:MM:SS, HH:MM or HH, got "10:11:12:13"',
            fn (Builder $builder) => $builder->whereTime('created_at', '10:11:12:13'),
        ];

        yield 'whereTime out of range' => [
            InvalidArgumentException::class,
            'Invalid time format, expected HH:MM:SS, HH:MM or HH, got "23:70"',
            fn (Builder $builder) => $builder->whereTime('created_at', '23:70'),
        ];

        yield 'whereTime invalid type' => [
            InvalidArgumentException::class,
            'Invalid time format, expected HH:MM:SS, HH:MM or HH, got "stdClass"',
            fn (Builder $builder) => $builder->whereTime('created_at', new stdClass()),
        ];

        yield 'where invalid column type' => [
            InvalidArgumentException::class,
            'First argument of MongoDB\Laravel\Query\Builder::where must be a field path as "string". Got "float"',
            fn (Builder $builder) => $builder->where(2.3, '>', 1),
        ];
    }

    #[DataProvider('getEloquentMethodsNotSupported')]
    public function testEloquentMethodsNotSupported(Closure $callback)
    {
        $builder = self::getBuilder();

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('This method is not supported by MongoDB');

        $callback($builder);
    }

    public static function getEloquentMethodsNotSupported()
    {
        // Most of this methods can be implemented using aggregation framework
        // whereInRaw, whereNotInRaw, orWhereInRaw, orWhereNotInRaw, whereBetweenColumns

        yield 'toSql' => [fn (Builder $builder) => $builder->toSql()];
        yield 'toRawSql' => [fn (Builder $builder) => $builder->toRawSql()];

        /** @see DatabaseQueryBuilderTest::testBasicWhereColumn() */
        /** @see DatabaseQueryBuilderTest::testArrayWhereColumn() */
        yield 'whereColumn' => [fn (Builder $builder) => $builder->whereColumn('first_name', 'last_name')];
        yield 'orWhereColumn' => [fn (Builder $builder) => $builder->orWhereColumn('first_name', 'last_name')];

        /** @see DatabaseQueryBuilderTest::testWhereFulltextMySql() */
        yield 'whereFulltext' => [fn (Builder $builder) => $builder->whereFulltext('body', 'Hello World')];

        /** @see DatabaseQueryBuilderTest::testGroupBys() */
        yield 'groupByRaw' => [fn (Builder $builder) => $builder->groupByRaw('DATE(created_at)')];

        /** @see DatabaseQueryBuilderTest::testOrderBys() */
        yield 'orderByRaw' => [fn (Builder $builder) => $builder->orderByRaw('"age" ? desc', ['foo'])];

        /** @see DatabaseQueryBuilderTest::testInRandomOrderMySql */
        yield 'inRandomOrder' => [fn (Builder $builder) => $builder->inRandomOrder()];

        yield 'union' => [fn (Builder $builder) => $builder->union($builder)];
        yield 'unionAll' => [fn (Builder $builder) => $builder->unionAll($builder)];

        /** @see DatabaseQueryBuilderTest::testRawHavings */
        yield 'havingRaw' => [fn (Builder $builder) => $builder->havingRaw('user_foo < user_bar')];
        yield 'having' => [fn (Builder $builder) => $builder->having('baz', '=', 1)];
        yield 'havingBetween' => [fn (Builder $builder) => $builder->havingBetween('last_login_date', ['2018-11-16', '2018-12-16'])];
        yield 'orHavingRaw' => [fn (Builder $builder) => $builder->orHavingRaw('user_foo < user_bar')];

        /** @see DatabaseQueryBuilderTest::testWhereIntegerInRaw */
        yield 'whereIntegerInRaw' => [fn (Builder $builder) => $builder->whereIntegerInRaw('id', ['1a', 2])];

        /** @see DatabaseQueryBuilderTest::testOrWhereIntegerInRaw */
        yield 'orWhereIntegerInRaw' => [fn (Builder $builder) => $builder->orWhereIntegerInRaw('id', ['1a', 2])];

        /** @see DatabaseQueryBuilderTest::testWhereIntegerNotInRaw */
        yield 'whereIntegerNotInRaw' => [fn (Builder $builder) => $builder->whereIntegerNotInRaw('id', ['1a', 2])];

        /** @see DatabaseQueryBuilderTest::testOrWhereIntegerNotInRaw */
        yield 'orWhereIntegerNotInRaw' => [fn (Builder $builder) => $builder->orWhereIntegerNotInRaw('id', ['1a', 2])];
    }

    private static function getBuilder(): Builder
    {
        $connection = m::mock(Connection::class);
        $processor  = m::mock(Processor::class);
        $connection->shouldReceive('getSession')->andReturn(null);
        $connection->shouldReceive('getQueryGrammar')->andReturn(new Grammar());

        return new Builder($connection, null, $processor);
    }
}
