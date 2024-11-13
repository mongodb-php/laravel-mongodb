<?php

namespace MongoDB\Laravel\Tests;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as LaravelCollection;
use Illuminate\Support\Facades\Schema;
use MongoDB\Builder\Query;
use MongoDB\Builder\Search;
use MongoDB\Collection as MongoDBCollection;
use MongoDB\Driver\Exception\ServerException;
use MongoDB\Laravel\Schema\Builder;
use MongoDB\Laravel\Tests\Models\Book;

use function array_map;
use function assert;

use function mt_getrandmax;
use function rand;
use function range;
use function srand;
use function sort;
use function usleep;
use function usort;

class AtlasSearchTest extends TestCase
{
    private array $vectors;

    public function setUp(): void
    {
        parent::setUp();

        Book::insert($this->addVector([
            ['title' => 'Introduction to Algorithms'],
            ['title' => 'Clean Code: A Handbook of Agile Software Craftsmanship'],
            ['title' => 'Design Patterns: Elements of Reusable Object-Oriented Software'],
            ['title' => 'The Pragmatic Programmer: Your Journey to Mastery'],
            ['title' => 'Artificial Intelligence: A Modern Approach'],
            ['title' => 'Structure and Interpretation of Computer Programs'],
            ['title' => 'Code Complete: A Practical Handbook of Software Construction'],
            ['title' => 'The Art of Computer Programming'],
            ['title' => 'Computer Networks'],
            ['title' => 'Operating System Concepts'],
            ['title' => 'Database System Concepts'],
            ['title' => 'Compilers: Principles, Techniques, and Tools'],
            ['title' => 'Introduction to the Theory of Computation'],
            ['title' => 'Modern Operating Systems'],
            ['title' => 'Computer Organization and Design'],
            ['title' => 'The Mythical Man-Month: Essays on Software Engineering'],
            ['title' => 'Algorithms'],
            ['title' => 'Understanding Machine Learning: From Theory to Algorithms'],
            ['title' => 'Deep Learning'],
            ['title' => 'Pattern Recognition and Machine Learning'],
        ]));

        $collection = $this->getConnection('mongodb')->getCollection('books');
        assert($collection instanceof MongoDBCollection);

        try {
            $collection->createSearchIndex([
                'mappings' => [
                    'fields' => [
                        'title' => [
                            ['type' => 'string', 'analyzer' => 'lucene.english'],
                            ['type' => 'autocomplete', 'analyzer' => 'lucene.english'],
                            ['type' => 'token'],
                        ],
                    ],
                ],
            ]);

            $collection->createSearchIndex([
                'mappings' => ['dynamic' => true],
            ], ['name' => 'dynamic_search']);

            $collection->createSearchIndex([
                'fields' => [
                    ['type' => 'vector', 'numDimensions' => 4, 'path' => 'vector4', 'similarity' => 'cosine'],
                    ['type' => 'vector', 'numDimensions' => 32, 'path' => 'vector32', 'similarity' => 'euclidean'],
                    ['type' => 'filter', 'path' => 'title'],
                ],
            ], ['name' => 'vector', 'type' => 'vectorSearch']);
        } catch (ServerException $e) {
            if (Builder::isAtlasSearchNotSupportedException($e)) {
                self::markTestSkipped('Atlas Search not supported. ' . $e->getMessage());
            }

            throw $e;
        }

        // Wait for the index to be ready
        do {
            $ready = true;
            usleep(10_000);
            foreach ($collection->listSearchIndexes() as $index) {
                if ($index['status'] !== 'READY') {
                    $ready = false;
                }
            }
        } while (! $ready);
    }

    public function tearDown(): void
    {
        $this->getConnection('mongodb')->getCollection('books')->drop();

        parent::tearDown();
    }

    public function testGetIndexes()
    {
        $indexes = Schema::getIndexes('books');

        self::assertIsArray($indexes);
        self::assertCount(4, $indexes);

        // Order of indexes is not guaranteed
        usort($indexes, fn ($a, $b) => $a['name'] <=> $b['name']);

        $expected = [
            [
                'name' => '_id_',
                'columns' => ['_id'],
                'primary' => true,
                'type' => null,
                'unique' => false,
            ],
            [
                'name' => 'default',
                'columns' => ['title'],
                'type' => 'search',
                'primary' => false,
                'unique' => false,
            ],
            [
                'name' => 'dynamic_search',
                'columns' => ['dynamic'],
                'type' => 'search',
                'primary' => false,
                'unique' => false,
            ],
            [
                'name' => 'vector',
                'columns' => ['vector4', 'vector32', 'title'],
                'type' => 'vectorSearch',
                'primary' => false,
                'unique' => false,
            ],
        ];

        self::assertSame($expected, $indexes);
    }

    public function testEloquentBuilderSearch()
    {
        $results = Book::search(
            sort: ['title' => 1],
            operator: Search::text('title', 'systems'),
        );

        self::assertInstanceOf(EloquentCollection::class, $results);
        self::assertCount(3, $results);
        self::assertInstanceOf(Book::class, $results->first());
        self::assertSame([
            'Database System Concepts',
            'Modern Operating Systems',
            'Operating System Concepts',
        ], $results->pluck('title')->all());
    }

    public function testDatabaseBuilderSearch()
    {
        $results = $this->getConnection('mongodb')->table('books')
            ->search(Search::text('title', 'systems'), sort: ['title' => 1]);

        self::assertInstanceOf(LaravelCollection::class, $results);
        self::assertCount(3, $results);
        self::assertIsArray($results->first());
        self::assertSame([
            'Database System Concepts',
            'Modern Operating Systems',
            'Operating System Concepts',
        ], $results->pluck('title')->all());
    }

    public function testEloquentBuilderAutocomplete()
    {
        $results = Book::autocomplete('title', 'system');

        self::assertInstanceOf(LaravelCollection::class, $results);
        self::assertCount(3, $results);
        self::assertSame([
            'Database System Concepts',
            'Modern Operating Systems',
            'Operating System Concepts',
        ], $results->sort()->values()->all());
    }

    public function testDatabaseBuilderAutocomplete()
    {
        $results = $this->getConnection('mongodb')->table('books')
            ->autocomplete('title', 'system');

        self::assertInstanceOf(LaravelCollection::class, $results);
        self::assertCount(3, $results);
        // Sort results, because order is not guaranteed
        $results = $results->all();
        sort($results);
        self::assertSame([
            'Database System Concepts',
            'Modern Operating Systems',
            'Operating System Concepts',
        ], $results);
    }

    public function testDatabaseBuilderVectorSearch()
    {
        $results = $this->getConnection('mongodb')->table('books')
            ->vectorSearch(
                index: 'vector',
                path: 'vector4',
                queryVector: $this->vectors[7], // This is an exact match of the vector
                limit: 4,
                exact: true,
            );

        self::assertInstanceOf(LaravelCollection::class, $results);
        self::assertCount(4, $results);
        self::assertSame('The Art of Computer Programming', $results->first()['title']);
        self::assertSame(1.0, $results->first()['vectorSearchScore']);
    }

    public function testEloquentBuilderVectorSearch()
    {
        $results = Book::vectorSearch(
            index: 'vector',
            path: 'vector4',
            queryVector: $this->vectors[7],
            limit: 5,
            numCandidates: 15,
            // excludes the exact match
            filter: Query::query(
                title: Query::ne('The Art of Computer Programming'),
            ),
        );

        self::assertInstanceOf(EloquentCollection::class, $results);
        self::assertCount(5, $results);
        self::assertInstanceOf(Book::class, $results->first());
        self::assertNotSame('The Art of Computer Programming', $results->first()->title);
        self::assertSame('The Mythical Man-Month: Essays on Software Engineering', $results->first()->title);
        self::assertThat(
            $results->first()->vectorSearchScore,
            self::logicalAnd(self::isType('float'), self::greaterThan(0.9), self::lessThan(1.0)),
        );
    }

    /** Generate random vectors using fixed seed to make tests deterministic */
    private function addVector(array $items): array
    {
        srand(1);
        foreach ($items as &$item) {
            $this->vectors[] = $item['vector4'] = array_map(fn () => rand() / mt_getrandmax(), range(0, 3));
        }

        return $items;
    }
}
