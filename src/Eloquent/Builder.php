<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Eloquent;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use MongoDB\BSON\Document;
use MongoDB\Builder\Type\QueryInterface;
use MongoDB\Builder\Type\SearchOperatorInterface;
use MongoDB\Driver\CursorInterface;
use MongoDB\Driver\Exception\WriteException;
use MongoDB\Laravel\Connection;
use MongoDB\Laravel\Helpers\QueriesRelationships;
use MongoDB\Laravel\Query\AggregationBuilder;
use MongoDB\Model\BSONDocument;

use function array_key_exists;
use function array_merge;
use function collect;
use function is_array;
use function is_object;
use function iterator_to_array;
use function property_exists;

/**
 * @method \MongoDB\Laravel\Query\Builder toBase()
 * @template TModel of Model
 */
class Builder extends EloquentBuilder
{
    private const DUPLICATE_KEY_ERROR = 11000;
    use QueriesRelationships;

    /**
     * The methods that should be returned from query builder.
     *
     * @var array
     */
    protected $passthru = [
        'average',
        'avg',
        'count',
        'dd',
        'doesntexist',
        'dump',
        'exists',
        'getbindings',
        'getconnection',
        'getgrammar',
        'insert',
        'insertgetid',
        'insertorignore',
        'insertusing',
        'max',
        'min',
        'autocomplete',
        'pluck',
        'pull',
        'push',
        'raw',
        'sum',
        'tomql',
    ];

    /**
     * @return ($function is null ? AggregationBuilder : self)
     *
     * @inheritdoc
     */
    public function aggregate($function = null, $columns = ['*'])
    {
        $result = $this->toBase()->aggregate($function, $columns);

        return $result ?: $this;
    }

    /**
     * Performs a full-text search of the field or fields in an Atlas collection.
     *
     * @see https://www.mongodb.com/docs/atlas/atlas-search/aggregation-stages/search/
     *
     * @return Collection<int, TModel>
     */
    public function search(
        SearchOperatorInterface|array $operator,
        ?string $index = null,
        ?array $highlight = null,
        ?bool $concurrent = null,
        ?string $count = null,
        ?string $searchAfter = null,
        ?string $searchBefore = null,
        ?bool $scoreDetails = null,
        ?array $sort = null,
        ?bool $returnStoredSource = null,
        ?array $tracking = null,
    ): Collection {
        $results = $this->toBase()->search($operator, $index, $highlight, $concurrent, $count, $searchAfter, $searchBefore, $scoreDetails, $sort, $returnStoredSource, $tracking);

        return $this->model->hydrate($results->all());
    }

    /**
     * Performs a semantic search on data in your Atlas Vector Search index.
     * NOTE: $vectorSearch is only available for MongoDB Atlas clusters, and is not available for self-managed deployments.
     *
     * @see https://www.mongodb.com/docs/atlas/atlas-vector-search/vector-search-stage/
     *
     * @return Collection<int, TModel>
     */
    public function vectorSearch(
        string $index,
        string $path,
        array $queryVector,
        int $limit,
        bool $exact = false,
        QueryInterface|array $filter = [],
        int|null $numCandidates = null,
    ): Collection {
        $results = $this->toBase()->vectorSearch($index, $path, $queryVector, $limit, $exact, $filter, $numCandidates);

        return $this->model->hydrate($results->all());
    }

    /** @inheritdoc */
    public function update(array $values, array $options = [])
    {
        // Intercept operations on embedded models and delegate logic
        // to the parent relation instance.
        $relation = $this->model->getParentRelation();
        if ($relation) {
            $relation->performUpdate($this->model, $values);

            return 1;
        }

        return $this->toBase()->update($this->addUpdatedAtColumn($values), $options);
    }

    /** @inheritdoc */
    public function insert(array $values)
    {
        // Intercept operations on embedded models and delegate logic
        // to the parent relation instance.
        $relation = $this->model->getParentRelation();
        if ($relation) {
            $relation->performInsert($this->model, $values);

            return true;
        }

        return parent::insert($values);
    }

    /** @inheritdoc */
    public function insertGetId(array $values, $sequence = null)
    {
        // Intercept operations on embedded models and delegate logic
        // to the parent relation instance.
        $relation = $this->model->getParentRelation();
        if ($relation) {
            $relation->performInsert($this->model, $values);

            return $this->model->getKey();
        }

        return parent::insertGetId($values, $sequence);
    }

    /** @inheritdoc */
    public function delete()
    {
        // Intercept operations on embedded models and delegate logic
        // to the parent relation instance.
        $relation = $this->model->getParentRelation();
        if ($relation) {
            $relation->performDelete($this->model);

            return $this->model->getKey();
        }

        return parent::delete();
    }

    /** @inheritdoc */
    public function increment($column, $amount = 1, array $extra = [])
    {
        // Intercept operations on embedded models and delegate logic
        // to the parent relation instance.
        $relation = $this->model->getParentRelation();
        if ($relation) {
            $value = $this->model->{$column};

            // When doing increment and decrements, Eloquent will automatically
            // sync the original attributes. We need to change the attribute
            // temporary in order to trigger an update query.
            $this->model->{$column} = null;

            $this->model->syncOriginalAttribute($column);

            return $this->model->update([$column => $value]);
        }

        return parent::increment($column, $amount, $extra);
    }

    /** @inheritdoc */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        // Intercept operations on embedded models and delegate logic
        // to the parent relation instance.
        $relation = $this->model->getParentRelation();
        if ($relation) {
            $value = $this->model->{$column};

            // When doing increment and decrements, Eloquent will automatically
            // sync the original attributes. We need to change the attribute
            // temporary in order to trigger an update query.
            $this->model->{$column} = null;

            $this->model->syncOriginalAttribute($column);

            return $this->model->update([$column => $value]);
        }

        return parent::decrement($column, $amount, $extra);
    }

    /** @inheritdoc */
    public function raw($value = null)
    {
        // Get raw results from the query builder.
        $results = $this->query->raw($value);

        // Convert MongoCursor results to a collection of models.
        if ($results instanceof CursorInterface) {
            $results->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
            $results = $this->query->aliasIdForResult(iterator_to_array($results));

            return $this->model->hydrate($results);
        }

        // Convert MongoDB Document to a single object.
        if (is_object($results) && (property_exists($results, '_id') || property_exists($results, 'id'))) {
            $results = (array) match (true) {
                $results instanceof BSONDocument => $results->getArrayCopy(),
                $results instanceof Document => $results->toPHP(['root' => 'array', 'document' => 'array', 'array' => 'array']),
                default => $results,
            };
        }

        // The result is a single object.
        if (is_array($results) && (array_key_exists('_id', $results) || array_key_exists('id', $results))) {
            $results = $this->query->aliasIdForResult($results);

            return $this->model->newFromBuilder($results);
        }

        return $results;
    }

    public function firstOrCreate(array $attributes = [], array $values = [])
    {
        $instance = (clone $this)->where($attributes)->first();
        if ($instance !== null) {
            return $instance;
        }

        // createOrFirst is not supported in transaction.
        if ($this->getConnection()->getSession()?->isInTransaction()) {
            return $this->create(array_merge($attributes, $values));
        }

        return $this->createOrFirst($attributes, $values);
    }

    public function createOrFirst(array $attributes = [], array $values = [])
    {
        // The duplicate key error would abort the transaction. Using the regular firstOrCreate in that case.
        if ($this->getConnection()->getSession()?->isInTransaction()) {
            return $this->firstOrCreate($attributes, $values);
        }

        try {
            return $this->create(array_merge($attributes, $values));
        } catch (WriteException $e) {
            if ($e->getCode() === self::DUPLICATE_KEY_ERROR) {
                return $this->where($attributes)->first() ?? throw $e;
            }

            throw $e;
        }
    }

    /**
     * Add the "updated at" column to an array of values.
     * TODO Remove if https://github.com/laravel/framework/commit/6484744326531829341e1ff886cc9b628b20d73e
     * will be reverted
     * Issue in laravel/frawework https://github.com/laravel/framework/issues/27791.
     *
     * @return array
     */
    protected function addUpdatedAtColumn(array $values)
    {
        if (! $this->model->usesTimestamps() || $this->model->getUpdatedAtColumn() === null) {
            return $values;
        }

        $column = $this->model->getUpdatedAtColumn();
        $values = array_merge(
            [$column => $this->model->freshTimestampString()],
            $values,
        );

        return $values;
    }

    public function getConnection(): Connection
    {
        return $this->query->getConnection();
    }

    /** @inheritdoc */
    protected function ensureOrderForCursorPagination($shouldReverse = false)
    {
        if (empty($this->query->orders)) {
            $this->enforceOrderBy();
        }

        if ($shouldReverse) {
            $this->query->orders = collect($this->query->orders)
                ->map(static fn (int $direction) => $direction === 1 ? -1 : 1)
                ->toArray();
        }

        return collect($this->query->orders)
            ->map(static fn ($direction, $column) => [
                'column' => $column,
                'direction' => $direction === 1 ? 'asc' : 'desc',
            ])->values();
    }
}
