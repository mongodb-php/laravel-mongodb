<?php

namespace MongoDB\Laravel\Scout;

use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Searchable;
use LogicException;
use MongoDB\BSON\Serializable;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection as MongoDBCollection;
use MongoDB\Database;
use MongoDB\Driver\CursorInterface;
use MongoDB\Exception\RuntimeException as MongoDBRuntimeException;
use MongoDB\Laravel\Connection;
use Override;
use stdClass;
use Traversable;
use TypeError;

use function array_column;
use function array_flip;
use function array_map;
use function array_merge;
use function assert;
use function call_user_func;
use function class_uses_recursive;
use function get_debug_type;
use function hrtime;
use function in_array;
use function is_array;
use function is_int;
use function is_iterable;
use function is_string;
use function iterator_to_array;
use function method_exists;
use function sleep;
use function sprintf;
use function time;

/**
 * In the context of this Laravel Scout engine, a "search index" refers to
 * a MongoDB Collection with a Search Index.
 */
final class ScoutEngine extends Engine
{
    /** Name of the Atlas Search index. */
    private const INDEX_NAME = 'scout';

    // Atlas Search index management operations are asynchronous.
    // They usually take less than 5 minutes to complete.
    private const WAIT_TIMEOUT_SEC = 300;

    private const DEFAULT_DEFINITION = [
        'mappings' => [
            'dynamic' => true,
        ],
    ];

    private const TYPEMAP = ['root' => 'object', 'document' => 'bson', 'array' => 'bson'];

    /** @param array<string, array> $indexDefinitions */
    public function __construct(
        private Database $database,
        private bool $softDelete,
        private array $indexDefinitions = [],
    ) {
    }

    /**
     * Update the given model in the index.
     *
     * @see Engine::update()
     *
     * @param EloquentCollection $models
     *
     * @throws MongoDBRuntimeException
     */
    #[Override]
    public function update($models)
    {
        assert($models instanceof EloquentCollection, new TypeError(sprintf('Argument #1 ($models) must be of type %s, %s given', EloquentCollection::class, get_debug_type($models))));

        if ($models->isEmpty()) {
            return;
        }

        if ($this->softDelete && $this->usesSoftDelete($models)) {
            $models->each->pushSoftDeleteMetadata();
        }

        $bulk = [];
        foreach ($models as $model) {
            assert($model instanceof Model && method_exists($model, 'toSearchableArray'), new LogicException(sprintf('Model "%s" must use "%s" trait', $model::class, Searchable::class)));

            $searchableData = $model->toSearchableArray();
            $searchableData = self::serialize($searchableData);

            // Skip/remove the model if it doesn't provide any searchable data
            if (! $searchableData) {
                $bulk[] = [
                    'deleteOne' => [
                        ['_id' => $model->getScoutKey()],
                    ],
                ];

                continue;
            }

            unset($searchableData['_id']);

            $searchableData = array_merge($searchableData, $model->scoutMetadata());

            /** Convert the __soft_deleted set by {@see Searchable::pushSoftDeleteMetadata()}
             * into a boolean for efficient storage and indexing. */
            if (isset($searchableData['__soft_deleted'])) {
                $searchableData['__soft_deleted'] = (bool) $searchableData['__soft_deleted'];
            }

            $bulk[] = [
                'updateOne' => [
                    ['_id' => $model->getScoutKey()],
                    [
                        // The _id field is added automatically when the document is inserted
                        // Update all other fields
                        '$set' => $searchableData,
                    ],
                    ['upsert' => true],
                ],
            ];
        }

        $this->getIndexableCollection($models)->bulkWrite($bulk);
    }

    /**
     * Remove the given model from the index.
     *
     * @see Engine::delete()
     *
     * @param EloquentCollection $models
     */
    #[Override]
    public function delete($models): void
    {
        assert($models instanceof EloquentCollection, new TypeError(sprintf('Argument #1 ($models) must be of type %s, %s given', Collection::class, get_debug_type($models))));

        if ($models->isEmpty()) {
            return;
        }

        $collection = $this->getIndexableCollection($models);
        $ids = $models->map(fn (Model $model) => $model->getScoutKey())->all();
        $collection->deleteMany(['_id' => ['$in' => $ids]]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @see Engine::search()
     *
     * @return array
     */
    #[Override]
    public function search(Builder $builder)
    {
        return $this->performSearch($builder);
    }

    /**
     * Perform the given search on the engine with pagination.
     *
     * @see Engine::paginate()
     *
     * @param int $perPage
     * @param int $page
     *
     * @return array
     */
    #[Override]
    public function paginate(Builder $builder, $perPage, $page)
    {
        assert(is_int($perPage), new TypeError(sprintf('Argument #2 ($perPage) must be of type int, %s given', get_debug_type($perPage))));
        assert(is_int($page), new TypeError(sprintf('Argument #3 ($page) must be of type int, %s given', get_debug_type($page))));

        $builder = clone $builder;
        $builder->take($perPage);

        return $this->performSearch($builder, $perPage * ($page - 1));
    }

    /**
     * Perform the given search on the engine.
     */
    private function performSearch(Builder $builder, ?int $offset = null): array
    {
        $collection = $this->getSearchableCollection($builder->model);

        if ($builder->callback) {
            $cursor = call_user_func(
                $builder->callback,
                $collection,
                $builder->query,
                $offset,
            );
            assert($cursor instanceof CursorInterface, new LogicException(sprintf('The search builder closure must return a MongoDB cursor, %s returned', get_debug_type($cursor))));
            $cursor->setTypeMap(self::TYPEMAP);

            return $cursor->toArray();
        }

        // Using compound to combine search operators
        // https://www.mongodb.com/docs/atlas/atlas-search/compound/#options
        // "should" specifies conditions that contribute to the relevance score
        // at least one of them must match,
        // - "text" search for the text including fuzzy matching
        // - "wildcard" allows special characters like * and ?, similar to LIKE in SQL
        // These are the only search operators to accept wildcard path.
        $compound = [
            'should' => [
                [
                    'text' => [
                        'query' => $builder->query,
                        'path' => ['wildcard' => '*'],
                        'fuzzy' => ['maxEdits' => 2],
                        'score' => ['boost' => ['value' => 5]],
                    ],
                ],
                [
                    'wildcard' => [
                        'query' => $builder->query . '*',
                        'path' => ['wildcard' => '*'],
                        'allowAnalyzedField' => true,
                    ],
                ],
            ],
            'minimumShouldMatch' => 1,
        ];

        // "filter" specifies conditions on exact values to match
        // "mustNot" specifies conditions on exact values that must not match
        // They don't contribute to the relevance score
        foreach ($builder->wheres as $field => $value) {
            if ($field === '__soft_deleted') {
                $value = (bool) $value;
            }

            $compound['filter'][] = ['equals' => ['path' => $field, 'value' => $value]];
        }

        foreach ($builder->whereIns as $field => $value) {
            $compound['filter'][] = ['in' => ['path' => $field, 'value' => $value]];
        }

        foreach ($builder->whereNotIns as $field => $value) {
            $compound['mustNot'][] = ['in' => ['path' => $field, 'value' => $value]];
        }

        // Sort by field value only if specified
        $sort = [];
        foreach ($builder->orders as $order) {
            $sort[$order['column']] = $order['direction'] === 'asc' ? 1 : -1;
        }

        $pipeline = [
            [
                '$search' => [
                    'index' => self::INDEX_NAME,
                    'compound' => $compound,
                    'count' => ['type' => 'lowerBound'],
                    ...($sort ? ['sort' => $sort] : []),
                ],
            ],
            [
                '$addFields' => [
                    // Metadata field with the total count of documents
                    '__count' => '$$SEARCH_META.count.lowerBound',
                ],
            ],
        ];

        if ($offset) {
            $pipeline[] = ['$skip' => $offset];
        }

        if ($builder->limit) {
            $pipeline[] = ['$limit' => $builder->limit];
        }

        $cursor = $collection->aggregate($pipeline);
        $cursor->setTypeMap(self::TYPEMAP);

        return $cursor->toArray();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @see Engine::mapIds()
     *
     * @param list<array|object> $results
     */
    #[Override]
    public function mapIds($results): Collection
    {
        assert(is_array($results), new TypeError(sprintf('Argument #1 ($results) must be of type array, %s given', get_debug_type($results))));

        return new Collection(array_column($results, '_id'));
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @see Engine::map()
     *
     * @param Builder $builder
     * @param array   $results
     * @param Model   $model
     *
     * @return Collection
     */
    #[Override]
    public function map(Builder $builder, $results, $model): Collection
    {
        return $this->performMap($builder, $results, $model, false);
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     *
     * @see Engine::lazyMap()
     *
     * @param Builder $builder
     * @param array   $results
     * @param Model   $model
     *
     * @return LazyCollection
     */
    #[Override]
    public function lazyMap(Builder $builder, $results, $model): LazyCollection
    {
        return $this->performMap($builder, $results, $model, true);
    }

    /** @return ($lazy is true ? LazyCollection : Collection)<mixed> */
    private function performMap(Builder $builder, array $results, Model $model, bool $lazy): Collection|LazyCollection
    {
        if (! $results) {
            $collection = $model->newCollection();

            return $lazy ? LazyCollection::make($collection) : $collection;
        }

        $objectIds = array_column($results, '_id');
        $objectIdPositions = array_flip($objectIds);

        return $model->queryScoutModelsByIds($builder, $objectIds)
            ->{$lazy ? 'cursor' : 'get'}()
            ->filter(function ($model) use ($objectIds) {
                return in_array($model->getScoutKey(), $objectIds);
            })
            ->map(function ($model) use ($results, $objectIdPositions) {
                $result = $results[$objectIdPositions[$model->getScoutKey()]] ?? [];

                foreach ($result as $key => $value) {
                    if ($key[0] === '_' && $key !== '_id') {
                        $model->withScoutMetadata($key, $value);
                    }
                }

                return $model;
            })
            ->sortBy(function ($model) use ($objectIdPositions) {
                return $objectIdPositions[$model->getScoutKey()];
            })
            ->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     * This is an estimate if the count is larger than 1000.
     *
     * @see Engine::getTotalCount()
     * @see https://www.mongodb.com/docs/atlas/atlas-search/counting/
     *
     * @param stdClass[] $results
     */
    #[Override]
    public function getTotalCount($results): int
    {
        if (! $results) {
            return 0;
        }

        // __count field is added by the aggregation pipeline in performSearch()
        // using the count.lowerBound in the $search stage
        return $results[0]->__count;
    }

    /**
     * Flush all records from the engine.
     *
     * @see Engine::flush()
     *
     * @param Model $model
     */
    #[Override]
    public function flush($model): void
    {
        assert($model instanceof Model, new TypeError(sprintf('Argument #1 ($model) must be of type %s, %s given', Model::class, get_debug_type($model))));

        $collection = $this->getIndexableCollection($model);

        $collection->deleteMany([]);
    }

    /**
     * Create the MongoDB Atlas Search index.
     *
     * Accepted options:
     *  - wait: bool, default true. Wait for the index to be created.
     *
     * @see Engine::createIndex()
     *
     * @param string            $name    Collection name
     * @param array{wait?:bool} $options
     */
    #[Override]
    public function createIndex($name, array $options = []): void
    {
        assert(is_string($name), new TypeError(sprintf('Argument #1 ($name) must be of type string, %s given', get_debug_type($name))));

        $definition = $this->indexDefinitions[$name] ?? self::DEFAULT_DEFINITION;
        if (! isset($definition['mappings'])) {
            throw new InvalidArgumentException(sprintf('Invalid search index definition for collection "%s", the "mappings" key is required. Find documentation at https://www.mongodb.com/docs/manual/reference/command/createSearchIndexes/#search-index-definition-syntax', $name));
        }

        // Ensure the collection exists before creating the search index
        $this->database->createCollection($name);

        $collection = $this->database->selectCollection($name);
        $collection->createSearchIndex($definition, ['name' => self::INDEX_NAME]);

        if ($options['wait'] ?? true) {
            $this->wait(function () use ($collection) {
                $indexes = $collection->listSearchIndexes([
                    'name' => self::INDEX_NAME,
                    'typeMap' => ['root' => 'bson'],
                ]);

                return $indexes->current() && $indexes->current()->status === 'READY';
            });
        }
    }

    /**
     * Delete a "search index", i.e. a MongoDB collection.
     *
     * @see Engine::deleteIndex()
     */
    #[Override]
    public function deleteIndex($name): void
    {
        assert(is_string($name), new TypeError(sprintf('Argument #1 ($name) must be of type string, %s given', get_debug_type($name))));

        $this->database->dropCollection($name);
    }

    /** Get the MongoDB collection used to search for the provided model */
    private function getSearchableCollection(Model|EloquentCollection $model): MongoDBCollection
    {
        if ($model instanceof EloquentCollection) {
            $model = $model->first();
        }

        assert(method_exists($model, 'searchableAs'), sprintf('Model "%s" must use "%s" trait', $model::class, Searchable::class));

        return $this->database->selectCollection($model->searchableAs());
    }

    /** Get the MongoDB collection used to index the provided model */
    private function getIndexableCollection(Model|EloquentCollection $model): MongoDBCollection
    {
        if ($model instanceof EloquentCollection) {
            $model = $model->first();
        }

        assert($model instanceof Model);
        assert(method_exists($model, 'indexableAs'), sprintf('Model "%s" must use "%s" trait', $model::class, Searchable::class));

        if (
            $model->getConnection() instanceof Connection
            && $model->getConnection()->getDatabaseName() === $this->database->getDatabaseName()
            && $model->getTable() === $model->indexableAs()
        ) {
            throw new LogicException(sprintf('The MongoDB Scout collection "%s.%s" must use a different collection from the collection name of the model "%s". Set the "scout.prefix" configuration or use a distinct MongoDB database', $this->database->getDatabaseName(), $model->indexableAs(), $model::class));
        }

        return $this->database->selectCollection($model->indexableAs());
    }

    private static function serialize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return new UTCDateTime($value);
        }

        if ($value instanceof Serializable || ! is_iterable($value)) {
            return $value;
        }

        // Convert Laravel Collections and other Iterators to arrays
        if ($value instanceof Traversable) {
            $value = iterator_to_array($value);
        }

        // Recursively serialize arrays
        return array_map(self::serialize(...), $value);
    }

    private function usesSoftDelete(Model|EloquentCollection $model): bool
    {
        if ($model instanceof EloquentCollection) {
            $model = $model->first();
        }

        return in_array(SoftDeletes::class, class_uses_recursive($model));
    }

    /**
     * Wait for the callback to return true, use it for asynchronous
     * Atlas Search index management operations.
     */
    private function wait(Closure $callback): void
    {
        // Fallback to time() if hrtime() is not supported
        $timeout = (hrtime()[0] ?? time()) + self::WAIT_TIMEOUT_SEC;
        while ((hrtime()[0] ?? time()) < $timeout) {
            if ($callback()) {
                return;
            }

            sleep(1);
        }

        throw new MongoDBRuntimeException(sprintf('Atlas search index operation time out after %s seconds', self::WAIT_TIMEOUT_SEC));
    }
}
