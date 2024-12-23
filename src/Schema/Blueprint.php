<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint as SchemaBlueprint;
use MongoDB\Collection;

use function array_flip;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function key;

/**
 * @link https://www.mongodb.com/docs/atlas/atlas-search/define-field-mappings/#std-label-fts-field-mappings
 * @phpstan-type TypeSearchIndexField array{
 *     type: 'boolean'|'date'|'dateFacet'|'objectId'|'stringFacet'|'uuid',
 * } | array{
 *     type: 'autocomplete',
 *     analyzer?: string,
 *     maxGrams?: int,
 *     minGrams?: int,
 *     tokenization?: 'edgeGram'|'rightEdgeGram'|'nGram',
 *     foldDiacritics?: bool,
 * } | array{
 *     type: 'document'|'embeddedDocuments',
 *     dynamic?:bool,
 *     fields: array<string, array<mixed>>,
 * } | array{
 *     type: 'geo',
 *     indexShapes?: bool,
 * } | array{
 *     type: 'number'|'numberFacet',
 *     representation?: 'int64'|'double',
 *     indexIntegers?: bool,
 *     indexDoubles?: bool,
 * } | array{
 *     type: 'token',
 *     normalizer?: 'lowercase'|'none',
 * } | array{
 *     type: 'string',
 *     analyzer?: string,
 *     searchAnalyzer?: string,
 *     indexOptions?: 'docs'|'freqs'|'positions'|'offsets',
 *     store?: bool,
 *     ignoreAbove?: int,
 *     multi?: array<string, array<string, mixed>>,
 *     norms?: 'include'|'omit',
 * }
 * @link https://www.mongodb.com/docs/atlas/atlas-search/analyzers/character-filters/
 * @phpstan-type TypeSearchIndexCharFilter array{
 *      type: 'icuNormalize'|'persian',
 *  } | array{
 *     type: 'htmlStrip',
 *     ignoredTags?: string[],
 * } | array{
 *     type: 'mapping',
 *     mappings?: array<string, string>,
 * }
 * @link https://www.mongodb.com/docs/atlas/atlas-search/analyzers/token-filters/
 * @phpstan-type TypeSearchIndexTokenFilter array{type: string, ...}
 * @link https://www.mongodb.com/docs/atlas/atlas-search/analyzers/custom/
 * @phpstan-type TypeSearchIndexAnalyzer array{
 *     name: string,
 *     charFilters?: TypeSearchIndexCharFilter,
 *     tokenizer: array{type: string},
 *     tokenFilters?: TypeSearchIndexTokenFilter,
 * }
 * @link https://www.mongodb.com/docs/atlas/atlas-search/stored-source-definition/#std-label-fts-stored-source-definition
 * @phpstan-type TypeSearchIndexStoredSource bool | array{
 *     includes: array<string>,
 * } | array{
 *     excludes: array<string>,
 * }
 * @link https://www.mongodb.com/docs/atlas/atlas-search/synonyms/#std-label-synonyms-ref
 * @phpstan-type TypeSearchIndexSynonyms array{
 *     analyzer: string,
 *     name: string,
 *     source?: array{collection: string},
 * }
 * @link https://www.mongodb.com/docs/manual/reference/command/createSearchIndexes/#std-label-search-index-definition-create
 * @phpstan-type TypeSearchIndexDefinition array{
 *     analyzer?: string,
 *     analyzers?: TypeSearchIndexAnalyzer[],
 *     searchAnalyzer?: string,
 *     mappings: array{dynamic: true} | array{dynamic?: bool, fields: array<string, TypeSearchIndexField>},
 *     storedSource?: TypeSearchIndexStoredSource,
 *     synonyms?: TypeSearchIndexSynonyms[],
 * }
 * @link https://www.mongodb.com/docs/atlas/atlas-vector-search/vector-search-type/#atlas-vector-search-index-fields
 * @phpstan-type TypeVectorSearchIndexField array{
 *     type: 'vector',
 *     path: string,
 *     numDimensions: int,
 *     similarity: 'euclidean'|'cosine'|'dotProduct',
 *     quantization?: 'none'|'scalar'|'binary',
 * } | array{
 *     type: 'filter',
 *     path: string,
 * }
 * @link https://www.mongodb.com/docs/atlas/atlas-vector-search/vector-search-type/#atlas-vector-search-index-fields
 * @phpstan-type TypeVectorSearchIndexDefinition array{
 *     fields: array<string, TypeVectorSearchIndexField>,
 * }
 */
class Blueprint extends SchemaBlueprint
{
    /**
     * The MongoConnection object for this blueprint.
     *
     * @var Connection
     */
    protected $connection;

    /**
     * The Collection object for this blueprint.
     *
     * @var Collection
     */
    protected $collection;

    /**
     * Fluent columns.
     *
     * @var array
     */
    protected $columns = [];

    /**
     * Create a new schema blueprint.
     */
    public function __construct(Connection $connection, string $collection)
    {
        parent::__construct($collection);

        $this->connection = $connection;

        $this->collection = $this->connection->getCollection($collection);
    }

    /** @inheritdoc */
    public function index($columns = null, $name = null, $algorithm = null, $options = [])
    {
        $columns = $this->fluent($columns);

        // Columns are passed as a default array.
        if (is_array($columns) && is_int(key($columns))) {
            // Transform the columns to the required array format.
            $transform = [];

            foreach ($columns as $column) {
                $transform[$column] = 1;
            }

            $columns = $transform;
        }

        if ($name !== null) {
            $options['name'] = $name;
        }

        $this->collection->createIndex($columns, $options);

        return $this;
    }

    /** @inheritdoc */
    public function primary($columns = null, $name = null, $algorithm = null, $options = [])
    {
        return $this->unique($columns, $name, $algorithm, $options);
    }

    /** @inheritdoc */
    public function dropIndex($index = null)
    {
        $index = $this->transformColumns($index);

        $this->collection->dropIndex($index);

        return $this;
    }

    /**
     * Indicate that the given index should be dropped, but do not fail if it didn't exist.
     *
     * @param  string|array $indexOrColumns
     *
     * @return Blueprint
     */
    public function dropIndexIfExists($indexOrColumns = null)
    {
        if ($this->hasIndex($indexOrColumns)) {
            $this->dropIndex($indexOrColumns);
        }

        return $this;
    }

    /**
     * Check whether the given index exists.
     *
     * @param  string|array $indexOrColumns
     *
     * @return bool
     */
    public function hasIndex($indexOrColumns = null)
    {
        $indexOrColumns = $this->transformColumns($indexOrColumns);
        foreach ($this->collection->listIndexes() as $index) {
            if (is_array($indexOrColumns) && in_array($index->getName(), $indexOrColumns)) {
                return true;
            }

            if (is_string($indexOrColumns) && $index->getName() === $indexOrColumns) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string|array $indexOrColumns
     *
     * @return string
     */
    protected function transformColumns($indexOrColumns)
    {
        if (is_array($indexOrColumns)) {
            $indexOrColumns = $this->fluent($indexOrColumns);

            // Transform the columns to the index name.
            $transform = [];

            foreach ($indexOrColumns as $key => $value) {
                if (is_int($key)) {
                    // There is no sorting order, use the default.
                    $column  = $value;
                    $sorting = '1';
                } else {
                    // This is a column with sorting order e.g 'my_column' => -1.
                    $column  = $key;
                    $sorting = $value;
                }

                $transform[$column] = $column . '_' . $sorting;
            }

            $indexOrColumns = implode('_', $transform);
        }

        return $indexOrColumns;
    }

    /** @inheritdoc */
    public function unique($columns = null, $name = null, $algorithm = null, $options = [])
    {
        $columns = $this->fluent($columns);

        $options['unique'] = true;

        $this->index($columns, $name, $algorithm, $options);

        return $this;
    }

    /**
     * Specify a sparse index for the collection.
     *
     * @param string|array $columns
     * @param array        $options
     *
     * @return Blueprint
     */
    public function sparse($columns = null, $options = [])
    {
        $columns = $this->fluent($columns);

        $options['sparse'] = true;

        $this->index($columns, null, null, $options);

        return $this;
    }

    /**
     * Specify a geospatial index for the collection.
     *
     * @param string|array $columns
     * @param string       $index
     * @param array        $options
     *
     * @return Blueprint
     */
    public function geospatial($columns = null, $index = '2d', $options = [])
    {
        if ($index === '2d' || $index === '2dsphere') {
            $columns = $this->fluent($columns);

            $columns = array_flip($columns);

            foreach ($columns as $column => $value) {
                $columns[$column] = $index;
            }

            $this->index($columns, null, null, $options);
        }

        return $this;
    }

    /**
     * Specify the number of seconds after which a document should be considered expired based,
     * on the given single-field index containing a date.
     *
     * @param string|array $columns
     * @param int          $seconds
     *
     * @return Blueprint
     */
    public function expire($columns, $seconds)
    {
        $columns = $this->fluent($columns);

        $this->index($columns, null, null, ['expireAfterSeconds' => $seconds]);

        return $this;
    }

    /**
     * Indicate that the collection needs to be created.
     *
     * @param array $options
     *
     * @return void
     */
    public function create($options = [])
    {
        $collection = $this->collection->getCollectionName();

        $db = $this->connection->getMongoDB();

        // Ensure the collection is created.
        $db->createCollection($collection, $options);
    }

    /** @inheritdoc */
    public function drop()
    {
        $this->collection->drop();

        return $this;
    }

    /** @inheritdoc */
    public function renameColumn($from, $to)
    {
        $this->collection->updateMany([$from => ['$exists' => true]], ['$rename' => [$from => $to]]);

        return $this;
    }

    /** @inheritdoc */
    public function addColumn($type, $name, array $parameters = [])
    {
        $this->fluent($name);

        return $this;
    }

    /**
     * Specify a sparse and unique index for the collection.
     *
     * @param string|array $columns
     * @param array        $options
     *
     * @return Blueprint
     *
     * phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
     */
    public function sparse_and_unique($columns = null, $options = [])
    {
        $columns = $this->fluent($columns);

        $options['sparse'] = true;
        $options['unique'] = true;

        $this->index($columns, null, null, $options);

        return $this;
    }

    /**
     * Create an Atlas Search Index.
     *
     * @see https://www.mongodb.com/docs/manual/reference/command/createSearchIndexes/#std-label-search-index-definition-create
     *
     * @phpstan-param TypeSearchIndexDefinition $definition
     */
    public function searchIndex(array $definition, string $name = 'default'): static
    {
        $this->collection->createSearchIndex($definition, ['name' => $name, 'type' => 'search']);

        return $this;
    }

    /**
     * Create an Atlas Vector Search Index.
     *
     * @see https://www.mongodb.com/docs/manual/reference/command/createSearchIndexes/#std-label-vector-search-index-definition-create
     *
     * @phpstan-param TypeVectorSearchIndexDefinition $definition
     */
    public function vectorSearchIndex(array $definition, string $name = 'default'): static
    {
        $this->collection->createSearchIndex($definition, ['name' => $name, 'type' => 'vectorSearch']);

        return $this;
    }

    /**
     * Allow fluent columns.
     *
     * @param string|array $columns
     *
     * @return string|array
     */
    protected function fluent($columns = null)
    {
        if ($columns === null) {
            return $this->columns;
        }

        if (is_string($columns)) {
            return $this->columns = [$columns];
        }

        return $this->columns = $columns;
    }

    /**
     * Allows the use of unsupported schema methods.
     *
     * @param string $method
     * @param array  $parameters
     *
     * @return Blueprint
     */
    public function __call($method, $parameters)
    {
        // Dummy.
        return $this;
    }
}
