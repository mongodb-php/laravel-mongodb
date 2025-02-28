<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Schema;
use MongoDB\Collection;
use MongoDB\Laravel\Schema\Blueprint;
use MongoDB\Laravel\Tests\TestCase;

use function assert;

class AtlasIdxSchemaBuilderTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAtlasSearchIdx(): void
    {
        // begin-create-search-indexes
        Schema::create('galaxies', function (Blueprint $collection) {
            $collection->searchIndex([
                'mappings' => [
                    'dynamic' => true,
                ],
            ], 'dynamic_index');
            $collection->searchIndex([
                'mappings' => [
                    'fields' => [
                        'name' => [
                            ['type' => 'string', 'analyzer' => 'lucene.english'],
                            ['type' => 'autocomplete', 'analyzer' => 'lucene.english'],
                            ['type' => 'token'],
                        ],
                    ],
                ],
            ], 'auto_index');
        });
        // end-create-search-indexes

        $index = $this->getSearchIndex('galaxies', 'dynamic_index');
        self::assertNotNull($index);

        self::assertSame('dynamic_index', $index['name']);
        self::assertSame('search', $index['type']);
        self::assertTrue($index['latestDefinition']['mappings']['dynamic']);

        $index = $this->getSearchIndex('galaxies', 'auto_index');
        self::assertNotNull($index);

        self::assertSame('auto_index', $index['name']);
        self::assertSame('search', $index['type']);
    }

    public function testVectorSearchIdx(): void
    {
        // begin-create-vs-index
        Schema::create('galaxies', function (Blueprint $collection) {
            $collection->vectorSearchIndex([
                'fields' => [
                    [
                        'type' => 'vector',
                        'numDimensions' => 4,
                        'path' => 'embeddings',
                        'similarity' => 'cosine',
                    ],
                ],
            ], 'vs_index');
        });
        // end-create-vs-index

        $index = $this->getSearchIndex('galaxies', 'vs_index');
        self::assertNotNull($index);

        self::assertSame('vs_index', $index['name']);
        self::assertSame('vectorSearch', $index['type']);
        self::assertSame('vector', $index['latestDefinition']['fields'][0]['type']);
    }

    public function testDropIndexes(): void
    {
        // begin-drop-search-index
        Schema::table('galaxies', function (Blueprint $collection) {
            $collection->dropSearchIndex('auto_index');
        });
        // end-drop-search-index

        Schema::table('galaxies', function (Blueprint $collection) {
            $collection->dropSearchIndex('dynamic_index');
        });

        Schema::table('galaxies', function (Blueprint $collection) {
            $collection->dropSearchIndex('vs_index');
        });

        $index = $this->getSearchIndex('galaxies', 'auto_index');
        self::assertNull($index);

        $index = $this->getSearchIndex('galaxies', 'dynamic_index');
        self::assertNull($index);

        $index = $this->getSearchIndex('galaxies', 'vs_index');
        self::assertNull($index);
    }

    protected function getSearchIndex(string $collection, string $name): ?array
    {
        $collection = $this->getConnection('mongodb')->getCollection($collection);
        assert($collection instanceof Collection);

        foreach ($collection->listSearchIndexes(['name' => $name, 'typeMap' => ['root' => 'array', 'array' => 'array', 'document' => 'array']]) as $index) {
            return $index;
        }

        return null;
    }
}
