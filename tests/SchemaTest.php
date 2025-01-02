<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\BSON\Binary;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Laravel\Schema\Blueprint;
use MongoDB\Model\IndexInfo;

use function assert;
use function collect;
use function count;
use function sprintf;

class SchemaTest extends TestCase
{
    public function tearDown(): void
    {
        $database = $this->getConnection('mongodb')->getMongoDB();
        assert($database instanceof Database);
        $database->dropCollection('newcollection');
        $database->dropCollection('newcollection_two');
    }

    public function testCreate(): void
    {
        Schema::create('newcollection');
        $this->assertTrue(Schema::hasCollection('newcollection'));
        $this->assertTrue(Schema::hasTable('newcollection'));
    }

    public function testCreateWithCallback(): void
    {
        $instance = $this;

        Schema::create('newcollection', function ($collection) use ($instance) {
            $instance->assertInstanceOf(Blueprint::class, $collection);
        });

        $this->assertTrue(Schema::hasCollection('newcollection'));
    }

    public function testCreateWithOptions(): void
    {
        Schema::create('newcollection_two', null, ['capped' => true, 'size' => 1024]);
        $this->assertTrue(Schema::hasCollection('newcollection_two'));
        $this->assertTrue(Schema::hasTable('newcollection_two'));

        $collection = Schema::getCollection('newcollection_two');
        $this->assertTrue($collection['options']['capped']);
        $this->assertEquals(1024, $collection['options']['size']);
    }

    public function testDrop(): void
    {
        Schema::create('newcollection');
        Schema::drop('newcollection');
        $this->assertFalse(Schema::hasCollection('newcollection'));
    }

    public function testBluePrint(): void
    {
        $instance = $this;

        Schema::table('newcollection', function ($collection) use ($instance) {
            $instance->assertInstanceOf(Blueprint::class, $collection);
        });

        Schema::table('newcollection', function ($collection) use ($instance) {
            $instance->assertInstanceOf(Blueprint::class, $collection);
        });
    }

    public function testIndex(): void
    {
        Schema::table('newcollection', function ($collection) {
            $collection->index('mykey1');
        });

        $index = $this->assertIndexExists('newcollection', 'mykey1_1');
        $this->assertEquals(1, $index['key']['mykey1']);

        Schema::table('newcollection', function ($collection) {
            $collection->index(['mykey2']);
        });

        $index = $this->assertIndexExists('newcollection', 'mykey2_1');
        $this->assertEquals(1, $index['key']['mykey2']);

        Schema::table('newcollection', function ($collection) {
            $collection->string('mykey3')->index();
        });

        $index = $this->assertIndexExists('newcollection', 'mykey3_1');
        $this->assertEquals(1, $index['key']['mykey3']);
    }

    public function testPrimary(): void
    {
        Schema::table('newcollection', function ($collection) {
            $collection->string('mykey', 100)->primary();
        });

        $index = $this->assertIndexExists('newcollection', 'mykey_1');
        $this->assertEquals(1, $index['unique']);
    }

    public function testUnique(): void
    {
        Schema::table('newcollection', function ($collection) {
            $collection->unique('uniquekey');
        });

        $index = $this->assertIndexExists('newcollection', 'uniquekey_1');
        $this->assertEquals(1, $index['unique']);
    }

    public function testDropIndex(): void
    {
        Schema::table('newcollection', function ($collection) {
            $collection->unique('uniquekey');
            $collection->dropIndex('uniquekey_1');
        });

        $this->assertIndexNotExists('newcollection', 'uniquekey_1');

        Schema::table('newcollection', function ($collection) {
            $collection->unique('uniquekey');
            $collection->dropIndex(['uniquekey']);
        });

        $this->assertIndexNotExists('newcollection', 'uniquekey_1');

        Schema::table('newcollection', function ($collection) {
            $collection->index(['field_a', 'field_b']);
        });

        $this->assertIndexExists('newcollection', 'field_a_1_field_b_1');

        Schema::table('newcollection', function ($collection) {
            $collection->dropIndex(['field_a', 'field_b']);
        });

        $this->assertIndexNotExists('newcollection', 'field_a_1_field_b_1');

        $indexName = 'field_a_-1_field_b_1';
        Schema::table('newcollection', function ($collection) {
            $collection->index(['field_a' => -1, 'field_b' => 1]);
        });

        $this->assertIndexExists('newcollection', $indexName);

        Schema::table('newcollection', function ($collection) {
            $collection->dropIndex(['field_a' => -1, 'field_b' => 1]);
        });

        $this->assertIndexNotExists('newcollection', $indexName);

        $indexName = 'custom_index_name';
        Schema::table('newcollection', function ($collection) use ($indexName) {
            $collection->index(['field_a', 'field_b'], $indexName);
        });

        $this->assertIndexExists('newcollection', $indexName);

        Schema::table('newcollection', function ($collection) use ($indexName) {
            $collection->dropIndex($indexName);
        });

        $this->assertIndexNotExists('newcollection', $indexName);
    }

    public function testDropIndexIfExists(): void
    {
        Schema::table('newcollection', function (Blueprint $collection) {
            $collection->unique('uniquekey');
            $collection->dropIndexIfExists('uniquekey_1');
        });

        $this->assertIndexNotExists('newcollection', 'uniquekey');

        Schema::table('newcollection', function (Blueprint $collection) {
            $collection->unique('uniquekey');
            $collection->dropIndexIfExists(['uniquekey']);
        });

        $this->assertIndexNotExists('newcollection', 'uniquekey');

        Schema::table('newcollection', function (Blueprint $collection) {
            $collection->index(['field_a', 'field_b']);
        });

        $this->assertIndexExists('newcollection', 'field_a_1_field_b_1');

        Schema::table('newcollection', function (Blueprint $collection) {
            $collection->dropIndexIfExists(['field_a', 'field_b']);
        });

        $this->assertIndexNotExists('newcollection', 'field_a_1_field_b_1');

        Schema::table('newcollection', function (Blueprint $collection) {
            $collection->index(['field_a', 'field_b'], 'custom_index_name');
        });

        $this->assertIndexExists('newcollection', 'custom_index_name');

        Schema::table('newcollection', function (Blueprint $collection) {
            $collection->dropIndexIfExists('custom_index_name');
        });

        $this->assertIndexNotExists('newcollection', 'custom_index_name');
    }

    public function testHasIndex(): void
    {
        Schema::table('newcollection', function (Blueprint $collection) {
            $collection->index('myhaskey1');
            $this->assertTrue($collection->hasIndex('myhaskey1_1'));
            $this->assertFalse($collection->hasIndex('myhaskey1'));
        });

        Schema::table('newcollection', function (Blueprint $collection) {
            $collection->index('myhaskey2');
            $this->assertTrue($collection->hasIndex(['myhaskey2']));
            $this->assertFalse($collection->hasIndex(['myhaskey2_1']));
        });

        Schema::table('newcollection', function (Blueprint $collection) {
            $collection->index(['field_a', 'field_b']);
            $this->assertTrue($collection->hasIndex(['field_a_1_field_b']));
            $this->assertFalse($collection->hasIndex(['field_a_1_field_b_1']));
        });
    }

    public function testSparse(): void
    {
        Schema::table('newcollection', function ($collection) {
            $collection->sparse('sparsekey');
        });

        $index = $this->assertIndexExists('newcollection', 'sparsekey_1');
        $this->assertEquals(1, $index['sparse']);
    }

    public function testExpire(): void
    {
        Schema::table('newcollection', function ($collection) {
            $collection->expire('expirekey', 60);
        });

        $index = $this->assertIndexExists('newcollection', 'expirekey_1');
        $this->assertEquals(60, $index['expireAfterSeconds']);
    }

    public function testSoftDeletes(): void
    {
        Schema::table('newcollection', function ($collection) {
            $collection->softDeletes();
        });

        Schema::table('newcollection', function ($collection) {
            $collection->string('email')->nullable()->index();
        });

        $index = $this->assertIndexExists('newcollection', 'email_1');
        $this->assertEquals(1, $index['key']['email']);
    }

    public function testFluent(): void
    {
        Schema::table('newcollection', function ($collection) {
            $collection->string('email')->index();
            $collection->string('token')->index();
            $collection->timestamp('created_at');
        });

        $index = $this->assertIndexExists('newcollection', 'email_1');
        $this->assertEquals(1, $index['key']['email']);

        $index = $this->assertIndexExists('newcollection', 'token_1');
        $this->assertEquals(1, $index['key']['token']);
    }

    public function testGeospatial(): void
    {
        Schema::table('newcollection', function ($collection) {
            $collection->geospatial('point');
            $collection->geospatial('area', '2d');
            $collection->geospatial('continent', '2dsphere');
        });

        $index = $this->assertIndexExists('newcollection', 'point_2d');
        $this->assertEquals('2d', $index['key']['point']);

        $index = $this->assertIndexExists('newcollection', 'area_2d');
        $this->assertEquals('2d', $index['key']['area']);

        $index = $this->assertIndexExists('newcollection', 'continent_2dsphere');
        $this->assertEquals('2dsphere', $index['key']['continent']);
    }

    public function testDummies(): void
    {
        Schema::table('newcollection', function ($collection) {
            $collection->boolean('activated')->default(0);
            $collection->integer('user_id')->unsigned();
        });
        $this->expectNotToPerformAssertions();
    }

    public function testSparseUnique(): void
    {
        Schema::table('newcollection', function ($collection) {
            $collection->sparse_and_unique('sparseuniquekey');
        });

        $index = $this->assertIndexExists('newcollection', 'sparseuniquekey_1');
        $this->assertEquals(1, $index['sparse']);
        $this->assertEquals(1, $index['unique']);
    }

    public function testRenameColumn(): void
    {
        DB::connection()->table('newcollection')->insert(['test' => 'value']);
        DB::connection()->table('newcollection')->insert(['test' => 'value 2']);
        DB::connection()->table('newcollection')->insert(['column' => 'column value']);

        $check = DB::connection()->table('newcollection')->get();
        $this->assertCount(3, $check);

        $this->assertObjectHasProperty('test', $check[0]);
        $this->assertObjectNotHasProperty('newtest', $check[0]);

        $this->assertObjectHasProperty('test', $check[1]);
        $this->assertObjectNotHasProperty('newtest', $check[1]);

        $this->assertObjectHasProperty('column', $check[2]);
        $this->assertObjectNotHasProperty('test', $check[2]);
        $this->assertObjectNotHasProperty('newtest', $check[2]);

        Schema::table('newcollection', function (Blueprint $collection) {
            $collection->renameColumn('test', 'newtest');
        });

        $check2 = DB::connection()->table('newcollection')->get();
        $this->assertCount(3, $check2);

        $this->assertObjectHasProperty('newtest', $check2[0]);
        $this->assertObjectNotHasProperty('test', $check2[0]);
        $this->assertSame($check[0]->test, $check2[0]->newtest);

        $this->assertObjectHasProperty('newtest', $check2[1]);
        $this->assertObjectNotHasProperty('test', $check2[1]);
        $this->assertSame($check[1]->test, $check2[1]->newtest);

        $this->assertObjectHasProperty('column', $check2[2]);
        $this->assertObjectNotHasProperty('test', $check2[2]);
        $this->assertObjectNotHasProperty('newtest', $check2[2]);
        $this->assertSame($check[2]->column, $check2[2]->column);
    }

    public function testHasColumn(): void
    {
        $this->assertTrue(Schema::hasColumn('newcollection', '_id'));
        $this->assertTrue(Schema::hasColumn('newcollection', 'id'));

        DB::connection()->table('newcollection')->insert(['column1' => 'value', 'embed' => ['_id' => 1]]);

        $this->assertTrue(Schema::hasColumn('newcollection', 'column1'));
        $this->assertFalse(Schema::hasColumn('newcollection', 'column2'));
        $this->assertTrue(Schema::hasColumn('newcollection', 'embed._id'));
        $this->assertTrue(Schema::hasColumn('newcollection', 'embed.id'));
    }

    public function testHasColumns(): void
    {
        $this->assertTrue(Schema::hasColumns('newcollection', ['_id']));
        $this->assertTrue(Schema::hasColumns('newcollection', ['id']));

        // Insert documents with both column1 and column2
        DB::connection()->table('newcollection')->insert([
            ['column1' => 'value1', 'column2' => 'value2'],
            ['column1' => 'value3'],
        ]);

        $this->assertTrue(Schema::hasColumns('newcollection', ['column1', 'column2']));
        $this->assertFalse(Schema::hasColumns('newcollection', ['column1', 'column3']));
    }

    public function testGetTables()
    {
        DB::connection('mongodb')->table('newcollection')->insert(['test' => 'value']);
        DB::connection('mongodb')->table('newcollection_two')->insert(['test' => 'value']);

        $tables = Schema::getTables();
        $this->assertIsArray($tables);
        $this->assertGreaterThanOrEqual(2, count($tables));
        $found = false;
        foreach ($tables as $table) {
            $this->assertArrayHasKey('name', $table);
            $this->assertArrayHasKey('size', $table);

            if ($table['name'] === 'newcollection') {
                $this->assertEquals(8192, $table['size']);
                $found = true;
            }
        }

        if (! $found) {
            $this->fail('Collection "newcollection" not found');
        }
    }

    public function testGetTableListing()
    {
        DB::connection('mongodb')->table('newcollection')->insert(['test' => 'value']);
        DB::connection('mongodb')->table('newcollection_two')->insert(['test' => 'value']);

        $tables = Schema::getTableListing();

        $this->assertIsArray($tables);
        $this->assertGreaterThanOrEqual(2, count($tables));
        $this->assertContains('newcollection', $tables);
        $this->assertContains('newcollection_two', $tables);
    }

    public function testGetColumns()
    {
        $collection = DB::connection('mongodb')->table('newcollection');
        $collection->insert(['text' => 'value', 'mixed' => ['key' => 'value']]);
        $collection->insert(['date' => new UTCDateTime(), 'binary' => new Binary('binary'), 'mixed' => true]);

        $columns = Schema::getColumns('newcollection');
        $this->assertIsArray($columns);
        $this->assertCount(5, $columns);

        $columns = collect($columns)->keyBy('name');

        $columns->each(function ($column) {
            $this->assertIsString($column['name']);
            $this->assertEquals($column['type'], $column['type_name']);
            $this->assertNull($column['collation']);
            $this->assertIsBool($column['nullable']);
            $this->assertNull($column['default']);
            $this->assertFalse($column['auto_increment']);
            $this->assertIsString($column['comment']);
        });

        $this->assertNull($columns->get('_id'), '_id is renamed to id');
        $this->assertEquals('objectId', $columns->get('id')['type']);
        $this->assertEquals('objectId', $columns->get('id')['generation']['type']);
        $this->assertNull($columns->get('text')['generation']);
        $this->assertEquals('string', $columns->get('text')['type']);
        $this->assertEquals('date', $columns->get('date')['type']);
        $this->assertEquals('binData', $columns->get('binary')['type']);
        $this->assertEquals('bool, object', $columns->get('mixed')['type']);
        $this->assertEquals('2 occurrences', $columns->get('mixed')['comment']);

        // Non-existent collection
        $columns = Schema::getColumns('missing');
        $this->assertSame([], $columns);
    }

    /** @see AtlasSearchTest::testGetIndexes() */
    public function testGetIndexes()
    {
        Schema::create('newcollection', function (Blueprint $collection) {
            $collection->index('mykey1');
            $collection->string('mykey2')->unique('unique_index');
            $collection->string('mykey3')->index();
        });
        $indexes = Schema::getIndexes('newcollection');
        self::assertIsArray($indexes);
        self::assertCount(4, $indexes);

        $expected = [
            [
                'name' => '_id_',
                'columns' => ['_id'],
                'primary' => true,
                'type' => null,
                'unique' => false,
            ],
            [
                'name' => 'mykey1_1',
                'columns' => ['mykey1'],
                'primary' => false,
                'type' => null,
                'unique' => false,
            ],
            [
                'name' => 'unique_index_1',
                'columns' => ['unique_index'],
                'primary' => false,
                'type' => null,
                'unique' => true,
            ],
            [
                'name' => 'mykey3_1',
                'columns' => ['mykey3'],
                'primary' => false,
                'type' => null,
                'unique' => false,
            ],
        ];

        self::assertSame($expected, $indexes);

        // Non-existent collection
        $indexes = Schema::getIndexes('missing');
        $this->assertSame([], $indexes);
    }

    public function testSearchIndex(): void
    {
        $this->skipIfSearchIndexManagementIsNotSupported();

        Schema::create('newcollection', function (Blueprint $collection) {
            $collection->searchIndex([
                'mappings' => [
                    'dynamic' => false,
                    'fields' => [
                        'foo' => ['type' => 'string', 'analyzer' => 'lucene.whitespace'],
                    ],
                ],
            ]);
        });

        $index = $this->getSearchIndex('newcollection', 'default');
        self::assertNotNull($index);

        self::assertSame('default', $index['name']);
        self::assertSame('search', $index['type']);
        self::assertFalse($index['latestDefinition']['mappings']['dynamic']);
        self::assertSame('lucene.whitespace', $index['latestDefinition']['mappings']['fields']['foo']['analyzer']);
    }

    public function testVectorSearchIndex()
    {
        $this->skipIfSearchIndexManagementIsNotSupported();

        Schema::create('newcollection', function (Blueprint $collection) {
            $collection->vectorSearchIndex([
                'fields' => [
                    ['type' => 'vector', 'path' => 'foo', 'numDimensions' => 128, 'similarity' => 'euclidean', 'quantization' => 'none'],
                ],
            ], 'vector');
        });

        $index = $this->getSearchIndex('newcollection', 'vector');
        self::assertNotNull($index);

        self::assertSame('vector', $index['name']);
        self::assertSame('vectorSearch', $index['type']);
        self::assertSame('vector', $index['latestDefinition']['fields'][0]['type']);
    }

    protected function assertIndexExists(string $collection, string $name): IndexInfo
    {
        $index = $this->getIndex($collection, $name);

        self::assertNotNull($index, sprintf('Index "%s.%s" does not exist.', $collection, $name));

        return $index;
    }

    protected function assertIndexNotExists(string $collection, string $name): void
    {
        $index = $this->getIndex($collection, $name);

        self::assertNull($index, sprintf('Index "%s.%s" exists.', $collection, $name));
    }

    protected function getIndex(string $collection, string $name): ?IndexInfo
    {
        $collection = $this->getConnection('mongodb')->getCollection($collection);
        assert($collection instanceof Collection);

        foreach ($collection->listIndexes() as $index) {
            if ($index->getName() === $name) {
                return $index;
            }
        }

        return null;
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
