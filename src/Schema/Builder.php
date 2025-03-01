<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Schema;

use Closure;
use MongoDB\Collection;
use MongoDB\Driver\Exception\ServerException;
use MongoDB\Laravel\Connection;
use MongoDB\Model\CollectionInfo;
use MongoDB\Model\IndexInfo;

use function array_column;
use function array_fill_keys;
use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function assert;
use function count;
use function current;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function iterator_to_array;
use function sort;
use function sprintf;
use function str_ends_with;
use function substr;
use function usort;

/** @property Connection $connection */
class Builder extends \Illuminate\Database\Schema\Builder
{
    /**
     * Check if column exists in the collection schema.
     *
     * @param string $table
     * @param string $column
     */
    public function hasColumn($table, $column): bool
    {
        return $this->hasColumns($table, [$column]);
    }

    /**
     * Check if columns exists in the collection schema.
     *
     * @param string   $table
     * @param string[] $columns
     */
    public function hasColumns($table, array $columns): bool
    {
        // The field "id" (alias of "_id") always exists in MongoDB documents
        $columns = array_filter($columns, fn (string $column): bool => ! in_array($column, ['_id', 'id'], true));

        // Any subfield named "*.id" is an alias of "*._id"
        $columns = array_map(fn (string $column): string => str_ends_with($column, '.id') ? substr($column, 0, -3) . '._id' : $column, $columns);

        if ($columns === []) {
            return true;
        }

        $collection = $this->connection->table($table);

        return $collection
            ->where(array_fill_keys($columns, ['$exists' => true]))
            ->project(['_id' => 1])
            ->exists();
    }

    /**
     * Determine if the given collection exists.
     *
     * @param string $name
     *
     * @return bool
     */
    public function hasCollection($name)
    {
        $db = $this->connection->getDatabase();

        $collections = iterator_to_array($db->listCollections([
            'filter' => ['name' => $name],
        ]), false);

        return count($collections) !== 0;
    }

    /** @inheritdoc */
    public function hasTable($table)
    {
        return $this->hasCollection($table);
    }

    /** @inheritdoc */
    public function table($table, Closure $callback)
    {
        $blueprint = $this->createBlueprint($table);

        if ($callback) {
            $callback($blueprint);
        }
    }

    /** @inheritdoc */
    public function create($table, ?Closure $callback = null, array $options = [])
    {
        $blueprint = $this->createBlueprint($table);

        $blueprint->create($options);

        if ($callback) {
            $callback($blueprint);
        }
    }

    /** @inheritdoc */
    public function dropIfExists($table)
    {
        if ($this->hasCollection($table)) {
            $this->drop($table);
        }
    }

    /** @inheritdoc */
    public function drop($table)
    {
        $blueprint = $this->createBlueprint($table);

        $blueprint->drop();
    }

    /** @inheritdoc */
    public function dropAllTables()
    {
        foreach ($this->getAllCollections() as $collection) {
            $this->drop($collection);
        }
    }

    /** @param string|null $schema Database name */
    public function getTables($schema = null)
    {
        $db = $this->connection->getDatabase($schema);
        $collections = [];

        foreach ($db->listCollectionNames() as $collectionName) {
            $stats = $db->selectCollection($collectionName)->aggregate([
                ['$collStats' => ['storageStats' => ['scale' => 1]]],
                ['$project' => ['storageStats.totalSize' => 1]],
            ])->toArray();

            $collections[] = [
                'name' => $collectionName,
                'schema' => $db->getDatabaseName(),
                'schema_qualified_name' => $db->getDatabaseName() . '.' . $collectionName,
                'size' => $stats[0]?->storageStats?->totalSize ?? null,
                'comment' => null,
                'collation' => null,
                'engine' => null,
            ];
        }

        usort($collections, function ($a, $b) {
            return $a['name'] <=> $b['name'];
        });

        return $collections;
    }

    /**
     * @param string|null $schema
     * @param bool        $schemaQualified If a schema is provided, prefix the collection names with the schema name
     *
     * @return array
     */
    public function getTableListing($schema = null, $schemaQualified = false)
    {
        $collections = [];

        if ($schema === null || is_string($schema)) {
            $collections[$schema ?? 0] = iterator_to_array($this->connection->getDatabase($schema)->listCollectionNames());
        } elseif (is_array($schema)) {
            foreach ($schema as $db) {
                $collections[$db] = iterator_to_array($this->connection->getDatabase($db)->listCollectionNames());
            }
        }

        if ($schema && $schemaQualified) {
            $collections = array_map(fn ($db, $collections) => array_map(static fn ($collection) => $db . '.' . $collection, $collections), array_keys($collections), $collections);
        }

        $collections = array_merge(...array_values($collections));

        sort($collections);

        return $collections;
    }

    public function getColumns($table)
    {
        $stats = $this->connection->getDatabase()->selectCollection($table)->aggregate([
            // Sample 1,000 documents to get a representative sample of the collection
            ['$sample' => ['size' => 1_000]],
            // Convert each document to an array of fields
            ['$project' => ['fields' => ['$objectToArray' => '$$ROOT']]],
            // Unwind to get one document per field
            ['$unwind' => '$fields'],
            // Group by field name, count the number of occurrences and get the types
            [
                '$group' => [
                    '_id' => '$fields.k',
                    'total' => ['$sum' => 1],
                    'types' => ['$addToSet' => ['$type' => '$fields.v']],
                ],
            ],
            // Get the most seen field names
            ['$sort' => ['total' => -1]],
            // Limit to 1,000 fields
            ['$limit' => 1_000],
            // Sort by field name
            ['$sort' => ['_id' => 1]],
        ], [
            'typeMap' => ['array' => 'array'],
            'allowDiskUse' => true,
        ])->toArray();

        $columns = [];
        foreach ($stats as $stat) {
            sort($stat->types);
            $type = implode(', ', $stat->types);
            $name = $stat->_id;
            if ($name === '_id') {
                $name = 'id';
            }

            $columns[] = [
                'name' => $name,
                'type_name' => $type,
                'type' => $type,
                'collation' => null,
                'nullable' => $name !== 'id',
                'default' => null,
                'auto_increment' => false,
                'comment' => sprintf('%d occurrences', $stat->total),
                'generation' => $name === 'id' ? ['type' => 'objectId', 'expression' => null] : null,
            ];
        }

        return $columns;
    }

    public function getIndexes($table)
    {
        $collection = $this->connection->getDatabase()->selectCollection($table);
        assert($collection instanceof Collection);
        $indexList = [];

        $indexes = $collection->listIndexes();
        foreach ($indexes as $index) {
            assert($index instanceof IndexInfo);
            $indexList[] = [
                'name' => $index->getName(),
                'columns' => array_keys($index->getKey()),
                'primary' => $index->getKey() === ['_id' => 1],
                'type' => match (true) {
                    $index->isText() => 'text',
                    $index->is2dSphere() => '2dsphere',
                    $index->isTtl() => 'ttl',
                    default => null,
                },
                'unique' => $index->isUnique(),
            ];
        }

        try {
            $indexes = $collection->listSearchIndexes(['typeMap' => ['root' => 'array', 'array' => 'array', 'document' => 'array']]);
            foreach ($indexes as $index) {
                // Status 'DOES_NOT_EXIST' means the index has been dropped but is still in the process of being removed
                if ($index['status'] === 'DOES_NOT_EXIST') {
                    continue;
                }

                $indexList[] = [
                    'name' => $index['name'],
                    'columns' => match ($index['type']) {
                        'search' => array_merge(
                            $index['latestDefinition']['mappings']['dynamic'] ? ['dynamic'] : [],
                            array_keys($index['latestDefinition']['mappings']['fields'] ?? []),
                        ),
                        'vectorSearch' => array_column($index['latestDefinition']['fields'], 'path'),
                    },
                    'type' => $index['type'],
                    'primary' => false,
                    'unique' => false,
                ];
            }
        } catch (ServerException $exception) {
            if (! self::isAtlasSearchNotSupportedException($exception)) {
                throw $exception;
            }
        }

        return $indexList;
    }

    public function getForeignKeys($table)
    {
        return [];
    }

    /** @inheritdoc */
    protected function createBlueprint($table, ?Closure $callback = null)
    {
        return new Blueprint($this->connection, $table);
    }

    /**
     * Get collection.
     *
     * @param string $name
     *
     * @return bool|CollectionInfo
     */
    public function getCollection($name)
    {
        $db = $this->connection->getDatabase();

        $collections = iterator_to_array($db->listCollections([
            'filter' => ['name' => $name],
        ]), false);

        return count($collections) ? current($collections) : false;
    }

    /**
     * Get all of the collections names for the database.
     *
     * @return array
     */
    protected function getAllCollections()
    {
        $collections = [];
        foreach ($this->connection->getDatabase()->listCollections() as $collection) {
            $collections[] = $collection->getName();
        }

        return $collections;
    }

    /** @internal */
    public static function isAtlasSearchNotSupportedException(ServerException $e): bool
    {
        return in_array($e->getCode(), [
            59,      // MongoDB 4 to 6, 7-community: no such command: 'createSearchIndexes'
            40324,   // MongoDB 4 to 6: Unrecognized pipeline stage name: '$listSearchIndexes'
            115,     // MongoDB 7-ent: Search index commands are only supported with Atlas.
            6047401, // MongoDB 7: $listSearchIndexes stage is only allowed on MongoDB Atlas
            31082,   // MongoDB 8: Using Atlas Search Database Commands and the $listSearchIndexes aggregation stage requires additional configuration.
        ], true);
    }
}
