<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests;

use Generator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Exception\ConnectionTimeoutException;
use MongoDB\Laravel\Connection;
use MongoDB\Laravel\Query\Builder;
use MongoDB\Laravel\Schema\Builder as SchemaBuilder;
use PHPUnit\Framework\Attributes\DataProvider;

use function env;
use function spl_object_hash;

class ConnectionTest extends TestCase
{
    public function testConnection()
    {
        $connection = DB::connection('mongodb');
        $this->assertInstanceOf(Connection::class, $connection);

        $this->assertSame('mongodb', $connection->getDriverName());
        $this->assertSame('MongoDB', $connection->getDriverTitle());
    }

    public function testReconnect()
    {
        $c1 = DB::connection('mongodb');
        $c2 = DB::connection('mongodb');
        $this->assertEquals(spl_object_hash($c1), spl_object_hash($c2));

        $c1 = DB::connection('mongodb');
        DB::purge('mongodb');
        $c2 = DB::connection('mongodb');
        $this->assertNotEquals(spl_object_hash($c1), spl_object_hash($c2));
    }

    public function testDisconnectAndCreateNewConnection()
    {
        $connection = DB::connection('mongodb');
        $this->assertInstanceOf(Connection::class, $connection);
        $client = $connection->getMongoClient();
        $this->assertInstanceOf(Client::class, $client);
        $connection->disconnect();
        $client = $connection->getMongoClient();
        $this->assertNull($client);
        DB::purge('mongodb');
        $connection = DB::connection('mongodb');
        $this->assertInstanceOf(Connection::class, $connection);
        $client = $connection->getMongoClient();
        $this->assertInstanceOf(Client::class, $client);
    }

    public function testDb()
    {
        $connection = DB::connection('mongodb');
        $this->assertInstanceOf(Database::class, $connection->getMongoDB());
        $this->assertInstanceOf(Client::class, $connection->getMongoClient());
    }

    public static function dataConnectionConfig(): Generator
    {
        yield 'Single host' => [
            'expectedUri' => 'mongodb://some-host',
            'expectedDatabaseName' => 'tests',
            'config' => [
                'host' => 'some-host',
                'database' => 'tests',
            ],
        ];

        yield 'Host and port' => [
            'expectedUri' => 'mongodb://some-host:12345',
            'expectedDatabaseName' => 'tests',
            'config' => [
                'host' => 'some-host',
                'port' => 12345,
                'database' => 'tests',
            ],
        ];

        yield 'IPv4' => [
            'expectedUri' => 'mongodb://1.2.3.4',
            'expectedDatabaseName' => 'tests',
            'config' => [
                'host' => '1.2.3.4',
                'database' => 'tests',
            ],
        ];

        yield 'IPv4 and port' => [
            'expectedUri' => 'mongodb://1.2.3.4:1234',
            'expectedDatabaseName' => 'tests',
            'config' => [
                'host' => '1.2.3.4',
                'port' => 1234,
                'database' => 'tests',
            ],
        ];

        yield 'IPv6' => [
            'expectedUri' => 'mongodb://[2001:db8:3333:4444:5555:6666:7777:8888]',
            'expectedDatabaseName' => 'tests',
            'config' => [
                'host' => '2001:db8:3333:4444:5555:6666:7777:8888',
                'database' => 'tests',
            ],
        ];

        yield 'IPv6 and port' => [
            'expectedUri' => 'mongodb://[2001:db8:3333:4444:5555:6666:7777:8888]:1234',
            'expectedDatabaseName' => 'tests',
            'config' => [
                'host' => '2001:db8:3333:4444:5555:6666:7777:8888',
                'port' => 1234,
                'database' => 'tests',
            ],
        ];

        yield 'multiple IPv6' => [
            'expectedUri' => 'mongodb://[::1],[2001:db8::1:0:0:1]',
            'expectedDatabaseName' => 'tests',
            'config' => [
                'host' => ['::1', '2001:db8::1:0:0:1'],
                'port' => null,
                'database' => 'tests',
            ],
        ];

        yield 'Port in host name takes precedence' => [
            'expectedUri' => 'mongodb://some-host:12345',
            'expectedDatabaseName' => 'tests',
            'config' => [
                'host' => 'some-host:12345',
                'port' => 54321,
                'database' => 'tests',
            ],
        ];

        yield 'Multiple hosts' => [
            'expectedUri' => 'mongodb://host-1,host-2',
            'expectedDatabaseName' => 'tests',
            'config' => [
                'host' => ['host-1', 'host-2'],
                'database' => 'tests',
            ],
        ];

        yield 'Multiple hosts with same port' => [
            'expectedUri' => 'mongodb://host-1:12345,host-2:12345',
            'expectedDatabaseName' => 'tests',
            'config' => [
                'host' => ['host-1', 'host-2'],
                'port' => 12345,
                'database' => 'tests',
            ],
        ];

        yield 'Multiple hosts with port' => [
            'expectedUri' => 'mongodb://host-1:12345,host-2:54321',
            'expectedDatabaseName' => 'tests',
            'config' => [
                'host' => ['host-1:12345', 'host-2:54321'],
                'database' => 'tests',
            ],
        ];

        yield 'DSN takes precedence over host/port config' => [
            'expectedUri' => 'mongodb://some-host:12345/auth-database',
            'expectedDatabaseName' => 'tests',
            'config' => [
                'dsn' => 'mongodb://some-host:12345/auth-database',
                'host' => 'wrong-host',
                'port' => 54321,
                'database' => 'tests',
            ],
        ];

        yield 'Database is extracted from DSN if not specified' => [
            'expectedUri' => 'mongodb://some-host:12345/tests',
            'expectedDatabaseName' => 'tests',
            'config' => ['dsn' => 'mongodb://some-host:12345/tests'],
        ];
    }

    #[DataProvider('dataConnectionConfig')]
    public function testConnectionConfig(string $expectedUri, string $expectedDatabaseName, array $config): void
    {
        $connection = new Connection($config);
        $client     = $connection->getMongoClient();

        $this->assertSame($expectedUri, (string) $client);
        $this->assertSame($expectedDatabaseName, $connection->getMongoDB()->getDatabaseName());
        $this->assertSame('foo', $connection->getCollection('foo')->getCollectionName());
        $this->assertSame('foo', $connection->table('foo')->raw()->getCollectionName());
    }

    public function testConnectionWithoutConfiguredDatabase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database is not properly configured.');

        new Connection(['dsn' => 'mongodb://some-host']);
    }

    public function testConnectionWithoutConfiguredDsnOrHost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MongoDB connection configuration requires "dsn" or "host" key.');

        new Connection(['database' => 'hello']);
    }

    public function testCollection()
    {
        $collection = DB::connection('mongodb')->getCollection('unittest');
        $this->assertInstanceOf(Collection::class, $collection);

        $collection = DB::connection('mongodb')->table('unittests');
        $this->assertInstanceOf(Builder::class, $collection);
    }

    public function testPrefix()
    {
        $config = [
            'dsn' => 'mongodb://127.0.0.1/',
            'database' => 'tests',
            'prefix' => 'prefix_',
        ];

        $connection = new Connection($config);

        $this->assertSame('prefix_foo', $connection->getCollection('foo')->getCollectionName());
        $this->assertSame('prefix_foo', $connection->table('foo')->raw()->getCollectionName());
    }

    public function testQueryLog()
    {
        DB::enableQueryLog();

        $this->assertCount(0, DB::getQueryLog());

        DB::table('items')->get();
        $this->assertCount(1, $logs = DB::getQueryLog());
        $this->assertJsonStringEqualsJsonString('{"find":"items","filter":{}}', $logs[0]['query']);

        DB::table('items')->insert(['id' => $id = new ObjectId(), 'name' => 'test']);
        $this->assertCount(2, $logs = DB::getQueryLog());
        $this->assertJsonStringEqualsJsonString('{"insert":"items","ordered":true,"documents":[{"name":"test","_id":{"$oid":"' . $id . '"}}]}', $logs[1]['query']);

        DB::table('items')->count();
        $this->assertCount(3, DB::getQueryLog());

        DB::table('items')->where('name', 'test')->update(['name' => 'test']);
        $this->assertCount(4, DB::getQueryLog());

        DB::table('items')->where('name', 'test')->delete();
        $this->assertCount(5, DB::getQueryLog());

        // Error
        try {
            DB::table('items')->where('name', 'test')->update(
                ['$set' => ['embed' => ['foo' => 'bar']], '$unset' => ['embed' => ['foo']]],
            );
            self::fail('Expected BulkWriteException');
        } catch (BulkWriteException) {
            $this->assertCount(6, DB::getQueryLog());
        }
    }

    public function testQueryLogWithMultipleClients()
    {
        $connection = DB::connection('mongodb');
        $this->assertInstanceOf(Connection::class, $connection);

        // Create a second connection with the same config as the first
        // Make sure to change the name as it's used as a connection identifier
        $config = $connection->getConfig();
        $config['name'] = 'mongodb2';
        $secondConnection = new Connection($config);

        $connection->enableQueryLog();
        $secondConnection->enableQueryLog();

        $this->assertCount(0, $connection->getQueryLog());
        $this->assertCount(0, $secondConnection->getQueryLog());

        $connection->table('items')->get();

        $this->assertCount(1, $connection->getQueryLog());
        $this->assertCount(0, $secondConnection->getQueryLog());

        $secondConnection->table('items')->get();

        $this->assertCount(1, $connection->getQueryLog());
        $this->assertCount(1, $secondConnection->getQueryLog());
    }

    public function testDisableQueryLog()
    {
        // Disabled by default
        DB::table('items')->get();
        $this->assertCount(0, DB::getQueryLog());

        DB::enableQueryLog();
        DB::table('items')->get();
        $this->assertCount(1, DB::getQueryLog());

        // Enable twice should only log once
        DB::enableQueryLog();
        DB::table('items')->get();
        $this->assertCount(2, DB::getQueryLog());

        DB::disableQueryLog();
        DB::table('items')->get();
        $this->assertCount(2, DB::getQueryLog());

        // Disable twice should not log
        DB::disableQueryLog();
        DB::table('items')->get();
        $this->assertCount(2, DB::getQueryLog());
    }

    public function testSchemaBuilder()
    {
        $schema = DB::connection('mongodb')->getSchemaBuilder();
        $this->assertInstanceOf(SchemaBuilder::class, $schema);
    }

    public function testDriverName()
    {
        $driver = DB::connection('mongodb')->getDriverName();
        $this->assertEquals('mongodb', $driver);
    }

    public function testPingMethod()
    {
        $config = [
            'name'     => 'mongodb',
            'driver'   => 'mongodb',
            'dsn'      => env('MONGODB_URI', 'mongodb://127.0.0.1/'),
            'database' => 'unittest',
            'options'  => [
                'connectTimeoutMS'         => 100,
                'serverSelectionTimeoutMS' => 250,
            ],
        ];

        $instance = new Connection($config);
        $instance->ping();

        $this->expectException(ConnectionTimeoutException::class);
        $this->expectExceptionMessage("No suitable servers found (`serverSelectionTryOnce` set): [Failed to resolve 'wrong-host']");

        $config['dsn'] = 'mongodb://wrong-host/';

        $instance = new Connection($config);
        $instance->ping();
    }

    public function testServerVersion()
    {
        $version = DB::connection('mongodb')->getServerVersion();
        $this->assertIsString($version);
    }

    public function testThreadsCount()
    {
        $threads = DB::connection('mongodb')->threadCount();

        $this->assertIsInt($threads);
        $this->assertGreaterThanOrEqual(1, $threads);
    }
}
