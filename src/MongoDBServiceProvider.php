<?php

declare(strict_types=1);

namespace MongoDB\Laravel;

use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Application;
use Illuminate\Session\SessionManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Scout\EngineManager;
use League\Flysystem\Filesystem;
use League\Flysystem\GridFS\GridFSAdapter;
use League\Flysystem\ReadOnly\ReadOnlyFilesystemAdapter;
use MongoDB\GridFS\Bucket;
use MongoDB\Laravel\Cache\MongoStore;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Queue\MongoConnector;
use MongoDB\Laravel\Scout\ScoutEngine;
use RuntimeException;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\MongoDbSessionHandler;

use function assert;
use function class_exists;
use function get_debug_type;
use function is_string;
use function sprintf;

class MongoDBServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     */
    public function boot()
    {
        Model::setConnectionResolver($this->app['db']);

        Model::setEventDispatcher($this->app['events']);
    }

    /**
     * Register the service provider.
     */
    public function register()
    {
        // Add database driver.
        $this->app->resolving('db', function ($db) {
            $db->extend('mongodb', function ($config, $name) {
                $config['name'] = $name;

                return new Connection($config);
            });
        });

        // Session handler for MongoDB
        $this->app->resolving(SessionManager::class, function (SessionManager $sessionManager) {
            $sessionManager->extend('mongodb', function (Application $app) {
                $connectionName = $app->config->get('session.connection') ?: 'mongodb';
                $connection = $app->make('db')->connection($connectionName);

                assert($connection instanceof Connection, new InvalidArgumentException(sprintf('The database connection "%s" used for the session does not use the "mongodb" driver.', $connectionName)));

                return new MongoDbSessionHandler(
                    $connection->getClient(),
                    $app->config->get('session.options', []) + [
                        'database' => $connection->getDatabaseName(),
                        'collection' => $app->config->get('session.table') ?: 'sessions',
                        'ttl' => $app->config->get('session.lifetime'),
                    ],
                );
            });
        });

        // Add cache and lock drivers.
        $this->app->resolving('cache', function (CacheManager $cache) {
            $cache->extend('mongodb', function (Application $app, array $config): Repository {
                // The closure is bound to the CacheManager
                assert($this instanceof CacheManager);

                $store = new MongoStore(
                    $app['db']->connection($config['connection'] ?? null),
                    $config['collection'] ?? 'cache',
                    $this->getPrefix($config),
                    $app['db']->connection($config['lock_connection'] ?? $config['connection'] ?? null),
                    $config['lock_collection'] ?? ($config['collection'] ?? 'cache') . '_locks',
                    $config['lock_lottery'] ?? [2, 100],
                    $config['lock_timeout'] ?? 86400,
                );

                return $this->repository($store, $config);
            });
        });

        // Add connector for queue support.
        $this->app->resolving('queue', function ($queue) {
            $queue->addConnector('mongodb', function () {
                return new MongoConnector($this->app['db']);
            });
        });

        $this->registerFlysystemAdapter();
        $this->registerScoutEngine();
    }

    private function registerFlysystemAdapter(): void
    {
        // GridFS adapter for filesystem
        $this->app->resolving('filesystem', static function (FilesystemManager $filesystemManager) {
            $filesystemManager->extend('gridfs', static function (Application $app, array $config) {
                if (! class_exists(GridFSAdapter::class)) {
                    throw new RuntimeException('GridFS adapter for Flysystem is missing. Try running "composer require league/flysystem-gridfs"');
                }

                $bucket = $config['bucket'] ?? null;

                if ($bucket instanceof Closure) {
                    // Get the bucket from a factory function
                    $bucket = $bucket($app, $config);
                } elseif (is_string($bucket) && $app->has($bucket)) {
                    // Get the bucket from a service
                    $bucket = $app->get($bucket);
                } elseif (is_string($bucket) || $bucket === null) {
                    // Get the bucket from the database connection
                    $connection = $app['db']->connection($config['connection']);
                    if (! $connection instanceof Connection) {
                        throw new InvalidArgumentException(sprintf('The database connection "%s" does not use the "mongodb" driver.', $config['connection'] ?? $app['config']['database.default']));
                    }

                    $bucket = $connection->getClient()
                        ->getDatabase($config['database'] ?? $connection->getDatabaseName())
                        ->selectGridFSBucket(['bucketName' => $config['bucket'] ?? 'fs', 'disableMD5' => true]);
                }

                if (! $bucket instanceof Bucket) {
                    throw new InvalidArgumentException(sprintf('Unexpected value for GridFS "bucket" configuration. Expecting "%s". Got "%s"', Bucket::class, get_debug_type($bucket)));
                }

                $adapter = new GridFSAdapter($bucket, $config['prefix'] ?? '');

                /** @see FilesystemManager::createFlysystem() */
                if ($config['read-only'] ?? false) {
                    if (! class_exists(ReadOnlyFilesystemAdapter::class)) {
                        throw new RuntimeException('Read-only Adapter for Flysystem is missing. Try running "composer require league/flysystem-read-only"');
                    }

                    $adapter = new ReadOnlyFilesystemAdapter($adapter);
                }

                /** Prevent using backslash on Windows in {@see FilesystemAdapter::__construct()} */
                $config['directory_separator'] = '/';

                return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
            });
        });
    }

    private function registerScoutEngine(): void
    {
        $this->app->resolving(EngineManager::class, function (EngineManager $engineManager) {
            $engineManager->extend('mongodb', function (Container $app) {
                $connectionName = $app->get('config')->get('scout.mongodb.connection', 'mongodb');
                $connection = $app->get('db')->connection($connectionName);
                $softDelete = (bool) $app->get('config')->get('scout.soft_delete', false);
                $indexDefinitions = $app->get('config')->get('scout.mongodb.index-definitions', []);

                assert($connection instanceof Connection, new InvalidArgumentException(sprintf('The connection "%s" is not a MongoDB connection.', $connectionName)));

                return new ScoutEngine($connection->getDatabase(), $softDelete, $indexDefinitions);
            });

            return $engineManager;
        });
    }
}
