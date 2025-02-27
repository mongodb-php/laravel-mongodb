<?php

namespace MongoDB\Laravel\Schema;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint as BaseBlueprint;

use function property_exists;

/**
 * The $connection property and constructor param were added in Laravel 12
 * We keep the untyped $connection property for older version of Laravel to maintain compatibility
 * and not break projects that would extend the MongoDB Blueprint class.
 *
 * @see https://github.com/laravel/framework/commit/f29df4740d724f1c36385c9989569e3feb9422df#diff-68f714a9f1b751481b993414d3f1300ad55bcef12084ec0eb8f47f350033c24bR107
 */
if (! property_exists(BaseBlueprint::class, 'connection')) {
    /** @internal For compatibility with Laravel 10 and 11 */
    trait BlueprintLaravelCompatibility
    {
        /**
         * The MongoDB connection object for this blueprint.
         *
         * @var Connection
         */
        protected $connection;

        public function __construct(Connection $connection, string $collection, ?Closure $callback = null)
        {
            parent::__construct($collection, $callback);

            $this->connection = $connection;
            $this->collection = $connection->getCollection($collection);
        }
    }
} else {
    /** @internal For compatibility with Laravel 12+ */
    trait BlueprintLaravelCompatibility
    {
        public function __construct(Connection $connection, string $collection, ?Closure $callback = null)
        {
            parent::__construct($connection, $collection, $callback);

            $this->collection = $connection->getCollection($collection);
        }
    }
}
