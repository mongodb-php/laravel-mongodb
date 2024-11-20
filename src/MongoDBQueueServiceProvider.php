<?php

declare(strict_types=1);

namespace MongoDB\Laravel;

use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Queue\QueueServiceProvider;
use MongoDB\Laravel\Queue\Failed\MongoFailedJobProvider;

use function array_key_exists;
use function trigger_error;

use const E_USER_DEPRECATED;

class MongoDBQueueServiceProvider extends QueueServiceProvider
{
    /**
     * Register the failed job services.
     *
     * @return void
     */
    protected function registerFailedJobServices()
    {
        $this->app->singleton('queue.failer', function ($app) {
            $config = $app['config']['queue.failed'];

            if (array_key_exists('driver', $config) && ($config['driver'] === null || $config['driver'] === 'null')) {
                return new NullFailedJobProvider();
            }

            if (isset($config['driver']) && $config['driver'] === 'mongodb') {
                return $this->mongoFailedJobProvider($config);
            }

            if (isset($config['driver']) && $config['driver'] === 'dynamodb') {
                return $this->dynamoFailedJobProvider($config);
            }

            if (isset($config['driver']) && $config['driver'] === 'database-uuids') {
                return $this->databaseUuidFailedJobProvider($config);
            }

            if (isset($config['table'])) {
                return $this->databaseFailedJobProvider($config);
            }

            return new NullFailedJobProvider();
        });
    }

    /**
     * Create a new MongoDB failed job provider.
     */
    protected function mongoFailedJobProvider(array $config): MongoFailedJobProvider
    {
        if (! isset($config['table']) && isset($config['collection'])) {
            trigger_error('Since mongodb/laravel-mongodb 4.4: Using "collection" option for the queue is deprecated. Use "table" instead.', E_USER_DEPRECATED);
            $config['table'] = $config['collection'];
        }

        return new MongoFailedJobProvider(
            $this->app['db'],
            $config['database'] ?? null,
            $config['table'] ?? 'failed_jobs',
        );
    }
}
