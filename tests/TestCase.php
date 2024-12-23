<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests;

use Illuminate\Foundation\Application;
use MongoDB\Driver\Exception\ServerException;
use MongoDB\Laravel\MongoDBServiceProvider;
use MongoDB\Laravel\Tests\Models\User;
use MongoDB\Laravel\Validation\ValidationServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

class TestCase extends OrchestraTestCase
{
    /**
     * Get package providers.
     *
     * @param  Application $app
     */
    protected function getPackageProviders($app): array
    {
        return [
            MongoDBServiceProvider::class,
            ValidationServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  Application $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        // reset base path to point to our package's src directory
        //$app['path.base'] = __DIR__ . '/../src';

        $config = require 'config/database.php';

        $app['config']->set('app.key', 'ZsZewWyUJ5FsKp9lMwv4tYbNlegQilM7');

        $app['config']->set('database.default', 'mongodb');
        $app['config']->set('database.connections.sqlite', $config['connections']['sqlite']);
        $app['config']->set('database.connections.mongodb', $config['connections']['mongodb']);
        $app['config']->set('database.connections.mongodb2', $config['connections']['mongodb']);

        $app['config']->set('auth.model', User::class);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('cache.driver', 'array');

        $app['config']->set('cache.stores.mongodb', [
            'driver' => 'mongodb',
            'connection' => 'mongodb',
            'collection' => 'foo_cache',
        ]);

        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'mongodb',
            'table' => 'jobs',
            'queue' => 'default',
            'expire' => 60,
        ]);
        $app['config']->set('queue.failed.database', 'mongodb2');
        $app['config']->set('queue.failed.driver', 'mongodb');
    }

    public function skipIfSearchIndexManagementIsNotSupported(): void
    {
        try {
            $this->getConnection('mongodb')->getCollection('test')->listSearchIndexes(['name' => 'just_for_testing']);
        } catch (ServerException $e) {
            switch ($e->getCode()) {
                // MongoDB 6: Unrecognized pipeline stage name: '$listSearchIndexes'
                case 40324:
                // MongoDB 7: PlanExecutor error during aggregation :: caused by :: Search index commands are only supported with Atlas.
                case 115:
                // MongoDB 7: $listSearchIndexes stage is only allowed on MongoDB Atlas
                case 6047401:
                // MongoDB 8: Using Atlas Search Database Commands and the $listSearchIndexes aggregation stage requires additional configuration.
                case 31082:
                    self::markTestSkipped('Search index management is not supported on this server');
            }

            throw $e;
        }
    }
}
