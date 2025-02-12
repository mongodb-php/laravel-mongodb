<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Sale;
use DateTimeImmutable;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Builder\Accumulator;
use MongoDB\Builder\Expression;
use MongoDB\Builder\Expression\YearOperator;
use MongoDB\Builder\Query;
use MongoDB\Builder\Type\Sort;
use MongoDB\Laravel\Tests\Models\User;
use MongoDB\Laravel\Tests\TestCase;

class AggregationsBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/Sale.php';
        require_once __DIR__ . '/Order.php';
        require_once __DIR__ . '/Inventory.php';

        parent::setUp();

        User::truncate();

        // begin aggregation builder sample data
        User::insert([
            ['name' => 'Alda Gröndal', 'occupation' => 'engineer', 'birthday' => new UTCDateTime(new DateTimeImmutable('2002-01-01'))],
            ['name' => 'Francois Soma', 'occupation' => 'engineer', 'birthday' => new UTCDateTime(new DateTimeImmutable('1998-02-02'))],
            ['name' => 'Janet Doe', 'occupation' => 'designer', 'birthday' => new UTCDateTime(new DateTimeImmutable('1987-03-03'))],
            ['name' => 'Eliud Nkosana', 'occupation' => 'engineer', 'birthday' => new UTCDateTime(new DateTimeImmutable('1984-04-04'))],
            ['name' => 'Bran Steafan', 'occupation' => 'engineer', 'birthday' => new UTCDateTime(new DateTimeImmutable('1998-05-05'))],
            ['name' => 'Ellis Lee', 'occupation' => 'designer', 'birthday' => new UTCDateTime(new DateTimeImmutable('1996-06-06'))],
        ]);
        // end aggregation builder sample data
    }

    public function testAggregationBuilderMatchStage(): void
    {
        // begin aggregation match stage
        $pipeline = User::aggregate()
            ->match(Query::or(
                Query::query(occupation: Query::eq('designer')),
                Query::query(name: Query::eq('Eliud Nkosana')),
            ));
        $result = $pipeline->get();
        // end aggregation match stage

        $this->assertEquals(3, $result->count());
    }

    public function testAggregationBuilderGroupStage(): void
    {
        // begin aggregation group stage
        $pipeline = User::aggregate()
            ->group(_id: Expression::fieldPath('occupation'));
        $result = $pipeline->get();
        // end aggregation group stage

        $this->assertEquals(2, $result->count());
    }

    public function testAggregationBuilderSortStage(): void
    {
        // begin aggregation sort stage
        $pipeline = User::aggregate()
            ->sort(name: Sort::Desc);
        $result = $pipeline->get();
        // end aggregation sort stage

        $this->assertEquals(6, $result->count());
        $this->assertEquals('Janet Doe', $result->first()['name']);
    }

    public function testAggregationBuilderProjectStage(): void
    {
        // begin aggregation project stage
        $pipeline = User::aggregate()
            ->project(_id: 0, name: 1);
        $result = $pipeline->get();
        // end aggregation project stage

        $this->assertEquals(6, $result->count());
        $this->assertNotNull($result->first()['name']);
        $this->assertArrayNotHasKey('_id', $result->first());
    }

    public function testAggregationBuilderMatchGroup(): void
    {
        Sale::truncate();

        Sale::insert([
            [
                '_id' => 1,
                'item' => 'abc',
                'price' => new Decimal128('10'),
                'quantity' => 2,
                'date' => new UTCDateTime(new DateTimeImmutable('2014-03-01T08:00:00Z')),
            ],
            [
                '_id' => 2,
                'item' => 'jkl',
                'price' => new Decimal128('20'),
                'quantity' => 1,
                'date' => new UTCDateTime(new DateTimeImmutable('2014-03-01T09:00:00Z')),
            ],
            [
                '_id' => 3,
                'item' => 'xyz',
                'price' => new Decimal128('5'),
                'quantity' => 10,
                'date' => new UTCDateTime(new DateTimeImmutable('2014-03-15T09:00:00Z')),
            ],
            [
                '_id' => 4,
                'item' => 'xyz',
                'price' => new Decimal128('5'),
                'quantity' => 20,
                'date' => new UTCDateTime(new DateTimeImmutable('2014-04-04T11:21:39.736Z')),
            ],
            [
                '_id' => 5,
                'item' => 'abc',
                'price' => new Decimal128('10'),
                'quantity' => 10,
                'date' => new UTCDateTime(new DateTimeImmutable('2014-04-04T21:23:13.331Z')),
            ],
            [
                '_id' => 6,
                'item' => 'def',
                'price' => new Decimal128('7.5'),
                'quantity' => 5,
                'date' => new UTCDateTime(new DateTimeImmutable('2015-06-04T05:08:13Z')),
            ],
            [
                '_id' => 7,
                'item' => 'def',
                'price' => new Decimal128('7.5'),
                'quantity' => 10,
                'date' => new UTCDateTime(new DateTimeImmutable('2015-09-10T08:43:00Z')),
            ],
            [
                '_id' => 8,
                'item' => 'abc',
                'price' => new Decimal128('10'),
                'quantity' => 5,
                'date' => new UTCDateTime(new DateTimeImmutable('2016-02-06T20:20:13Z')),
            ],
        ]);

        // start-builder-match-group
        $pipeline = Sale::aggregate()
            ->match(
                date: [
                    Query::gte(new UTCDateTime(new DateTimeImmutable('2014-01-01'))),
                    Query::lt(new UTCDateTime(new DateTimeImmutable('2015-01-01'))),
                ],
            )
            ->group(
                _id: Expression::dateToString(Expression::dateFieldPath('date'), '%Y-%m-%d'),
                totalSaleAmount: Accumulator::sum(
                    Expression::multiply(
                        Expression::numberFieldPath('price'),
                        Expression::numberFieldPath('quantity'),
                    ),
                ),
                averageQuantity: Accumulator::avg(
                    Expression::numberFieldPath('quantity'),
                ),
                count: Accumulator::sum(1),
            )
            ->sort(
                totalSaleAmount: Sort::Desc,
            );
        // end-builder-match-group

        $result = $pipeline->get();

        $this->assertEquals(3, $result->count());
        $this->assertNotNull($result->first()['totalSaleAmount']);
    }

    public function testAggregationBuilderUnwind(): void
    {
        Sale::truncate();

        Sale::insert([
            [
                '_id' => '1',
                'items' => [
                    [
                        'name' => 'pens',
                        'tags' => ['writing', 'office', 'school', 'stationary'],
                        'price' => new Decimal128('12.00'),
                        'quantity' => 5,
                    ],
                    [
                        'name' => 'envelopes',
                        'tags' => ['stationary', 'office'],
                        'price' => new Decimal128('19.95'),
                        'quantity' => 8,
                    ],
                ],
            ],
            [
                '_id' => '2',
                'items' => [
                    [
                        'name' => 'laptop',
                        'tags' => ['office', 'electronics'],
                        'price' => new Decimal128('800.00'),
                        'quantity' => 1,
                    ],
                    [
                        'name' => 'notepad',
                        'tags' => ['stationary', 'school'],
                        'price' => new Decimal128('14.95'),
                        'quantity' => 3,
                    ],
                ],
            ],
        ]);

        // start-builder-unwind
        $pipeline = Sale::aggregate()
            ->unwind(Expression::arrayFieldPath('items'))
            ->unwind(Expression::arrayFieldPath('items.tags'))
            ->group(
                _id: Expression::fieldPath('items.tags'),
                totalSalesAmount: Accumulator::sum(
                    Expression::multiply(
                        Expression::numberFieldPath('items.price'),
                        Expression::numberFieldPath('items.quantity'),
                    ),
                ),
            );
        // end-builder-unwind

        $result = $pipeline->get();

        $this->assertEquals(5, $result->count());
        $this->assertNotNull($result->first()['totalSalesAmount']);
    }

    public function testAggregationBuilderLookup(): void
    {
        Order::truncate();
        Inventory::truncate();

        Order::insert([
            [
                '_id' => 1,
                'item' => 'almonds',
                'price' => 12,
                'quantity' => 2,
            ],
            [
                '_id' => 2,
                'item' => 'pecans',
                'price' => 20,
                'quantity' => 1,
            ],
            [
                '_id' => 3,
            ],
        ]);

        Inventory::insert([
            [
                '_id' => 1,
                'sku' => 'almonds',
                'description' => 'product 1',
                'instock' => 120,
            ],
            [
                '_id' => 2,
                'sku' => 'bread',
                'description' => 'product 2',
                'instock' => 80,
            ],
            [
                '_id' => 3,
                'sku' => 'cashews',
                'description' => 'product 3',
                'instock' => 60,
            ],
            [
                '_id' => 4,
                'sku' => 'pecans',
                'description' => 'product 4',
                'instock' => 70,
            ],
            [
                '_id' => 5,
                'sku' => null,
                'description' => 'Incomplete',
            ],
            [
                '_id' => 6,
            ],
        ]);

        // start-builder-lookup
        $pipeline = Order::aggregate()
            ->lookup(
                from: 'inventory',
                localField: 'item',
                foreignField: 'sku',
                as: 'inventory_docs',
            );
        // end-builder-lookup

        $result = $pipeline->get();

        $this->assertEquals(3, $result->count());
        $this->assertNotNull($result->first()['item']);
    }

    // phpcs:disable Squiz.Commenting.FunctionComment.WrongStyle
    // phpcs:disable Squiz.WhiteSpace.FunctionSpacing.After
    // start custom operator factory function
    public function yearFromField(string $dateFieldName): YearOperator
    {
        return Expression::year(
            Expression::dateFieldPath($dateFieldName),
        );
    }
    // end custom operator factory function
    // phpcs:enable

    public function testCustomOperatorFactory(): void
    {
        // begin custom operator factory usage
        $pipeline = User::aggregate()
            ->addFields(birth_year: $this->yearFromField('birthday'))
            ->project(_id: 0, name: 1, birth_year: 1);
        // end custom operator factory usage

        $result = $pipeline->get();

        $this->assertEquals(6, $result->count());
        $this->assertNotNull($result->first()['birth_year']);
    }
}
