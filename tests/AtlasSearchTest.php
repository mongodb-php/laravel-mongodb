<?php

namespace MongoDB\Laravel\Tests;

use Illuminate\Support\Facades\Schema;
use MongoDB\Collection as MongoDBCollection;
use MongoDB\Driver\Exception\ServerException;
use MongoDB\Laravel\Schema\Builder;
use MongoDB\Laravel\Tests\Models\Book;

use function assert;
use function usleep;
use function usort;

class AtlasSearchTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Book::insert([
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
        ]);

        $collection = $this->getConnection('mongodb')->getCollection('books');
        assert($collection instanceof MongoDBCollection);
        try {
            $collection->createSearchIndex([
                'mappings' => [
                    'fields' => [
                        'title' => [
                            ['type' => 'string', 'analyzer' => 'lucene.english'],
                            ['type' => 'autocomplete', 'analyzer' => 'lucene.english'],
                        ],
                    ],
                ],
            ]);

            $collection->createSearchIndex([
                'mappings' => ['dynamic' => true],
            ], ['name' => 'dynamic_search']);

            $collection->createSearchIndex([
                'fields' => [
                    ['type' => 'vector', 'numDimensions' => 16, 'path' => 'vector16', 'similarity' => 'cosine'],
                    ['type' => 'vector', 'numDimensions' => 32, 'path' => 'vector32', 'similarity' => 'euclidean'],
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
                'columns' => ['vector16', 'vector32'],
                'type' => 'vectorSearch',
                'primary' => false,
                'unique' => false,
            ],
        ];

        self::assertSame($expected, $indexes);
    }
}
