<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Query;

use ArgumentCountError;
use BadMethodCallException;
use Carbon\CarbonPeriod;
use Closure;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use LogicException;
use MongoDB\BSON\Binary;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Builder\Search;
use MongoDB\Builder\Stage\FluentFactoryTrait;
use MongoDB\Builder\Type\QueryInterface;
use MongoDB\Builder\Type\SearchOperatorInterface;
use MongoDB\Driver\Cursor;
use MongoDB\Driver\ReadPreference;
use Override;
use RuntimeException;
use stdClass;
use TypeError;

use function array_fill_keys;
use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_map;
use function array_merge;
use function array_values;
use function assert;
use function blank;
use function call_user_func;
use function call_user_func_array;
use function count;
use function ctype_xdigit;
use function date_default_timezone_get;
use function dd;
use function dump;
use function end;
use function explode;
use function func_get_args;
use function func_num_args;
use function get_debug_type;
use function get_object_vars;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_callable;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function md5;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function property_exists;
use function serialize;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trait_exists;
use function var_export;

class Builder extends BaseBuilder
{
    private const REGEX_DELIMITERS = ['/', '#', '~'];

    /**
     * The database collection.
     *
     * @var \MongoDB\Collection
     */
    protected $collection;

    /**
     * The column projections.
     *
     * @var array
     */
    public $projections = [];

    /**
     * The maximum amount of seconds to allow the query to run.
     *
     * @var int|float
     */
    public $timeout;

    /**
     * The cursor hint value.
     *
     * @var int
     */
    public $hint;

    private ReadPreference $readPreference;

    /**
     * Custom options to add to the query.
     *
     * @var array
     */
    public $options = [];

    /**
     * All of the available clause operators.
     *
     * @var array
     */
    public $operators = [
        '=',
        '<',
        '>',
        '<=',
        '>=',
        '<>',
        '!=',
        'like',
        'not like',
        'between',
        'ilike',
        '&',
        '|',
        '^',
        '<<',
        '>>',
        'rlike',
        'regexp',
        'not regexp',
        'exists',
        'type',
        'mod',
        'where',
        'all',
        'size',
        'regex',
        'not regex',
        'text',
        'slice',
        'elemmatch',
        'geowithin',
        'geointersects',
        'near',
        'nearsphere',
        'geometry',
        'maxdistance',
        'center',
        'centersphere',
        'box',
        'polygon',
        'uniquedocs',
    ];

    /**
     * Operator conversion.
     *
     * @var array
     */
    protected $conversion = [
        '=' => 'eq',
        '!=' => 'ne',
        '<>' => 'ne',
        '<' => 'lt',
        '<=' => 'lte',
        '>' => 'gt',
        '>=' => 'gte',
        'regexp' => 'regex',
        'not regexp' => 'not regex',
        'ilike' => 'like',
        'elemmatch' => 'elemMatch',
        'geointersects' => 'geoIntersects',
        'geowithin' => 'geoWithin',
        'nearsphere' => 'nearSphere',
        'maxdistance' => 'maxDistance',
        'centersphere' => 'centerSphere',
        'uniquedocs' => 'uniqueDocs',
    ];

    /**
     * Set the projections.
     *
     * @param  array $columns
     *
     * @return $this
     */
    public function project($columns)
    {
        $this->projections = is_array($columns) ? $columns : func_get_args();

        return $this;
    }

    /**
     * The maximum amount of seconds to allow the query to run.
     *
     * @param  int|float $seconds
     *
     * @return $this
     */
    public function timeout($seconds)
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Set the cursor hint.
     *
     * @param  mixed $index
     *
     * @return $this
     */
    public function hint($index)
    {
        $this->hint = $index;

        return $this;
    }

    /** @inheritdoc */
    public function find($id, $columns = [])
    {
        return $this->where('_id', '=', $this->convertKey($id))->first($columns);
    }

    /** @inheritdoc */
    public function value($column)
    {
        $result = (array) $this->first([$column]);

        return Arr::get($result, $column);
    }

    /** @inheritdoc */
    public function get($columns = [])
    {
        return $this->getFresh($columns);
    }

    /** @inheritdoc */
    public function cursor($columns = [])
    {
        $result = $this->getFresh($columns, true);
        if ($result instanceof LazyCollection) {
            return $result;
        }

        throw new RuntimeException('Query not compatible with cursor');
    }

    /**
     * Die and dump the current MongoDB query
     *
     * @return never-return
     */
    #[Override]
    public function dd()
    {
        dd($this->toMql());
    }

    /**
     * Dump the current MongoDB query
     *
     * @param mixed ...$args
     *
     * @return $this
     */
    #[Override]
    public function dump(mixed ...$args)
    {
        dump($this->toMql(), ...$args);

        return $this;
    }

    /**
     * Return the MongoDB query to be run in the form of an element array like ['method' => [arguments]].
     *
     * Example: ['find' => [['name' => 'John Doe'], ['projection' => ['birthday' => 1]]]]
     *
     * @return array<string, mixed[]>
     */
    public function toMql(): array
    {
        $columns = $this->columns ?? [];

        // Drop all columns if * is present, MongoDB does not work this way.
        if (in_array('*', $columns)) {
            $columns = [];
        }

        $wheres = $this->compileWheres();
        $wheres = $this->aliasIdForQuery($wheres);

        // Use MongoDB's aggregation framework when using grouping or aggregation functions.
        if ($this->groups || $this->aggregate) {
            $group   = [];
            $unwinds = [];
            $set = [];

            // Add grouping columns to the $group part of the aggregation pipeline.
            if ($this->groups) {
                foreach ($this->groups as $column) {
                    $group['_id'][$column] = '$' . $column;

                    // When grouping, also add the $last operator to each grouped field,
                    // this mimics SQL's behaviour a bit.
                    $group[$column] = ['$last' => '$' . $column];
                }
            }

            // Add the last value of each column when there is no aggregate function.
            if ($this->groups && ! $this->aggregate) {
                foreach ($columns as $column) {
                    $key = str_replace('.', '_', $column);

                    $group[$key] = ['$last' => '$' . $column];
                }
            }

            // Add aggregation functions to the $group part of the aggregation pipeline,
            // these may override previous aggregations.
            if ($this->aggregate) {
                $function = $this->aggregate['function'];

                foreach ($this->aggregate['columns'] as $column) {
                    // Add unwind if a subdocument array should be aggregated
                    // column: subarray.price => {$unwind: '$subarray'}
                    $splitColumns = explode('.*.', $column);
                    if (count($splitColumns) === 2) {
                        $unwinds[] = $splitColumns[0];
                        $column    = implode('.', $splitColumns);
                    }

                    $aggregations = blank($this->aggregate['columns']) ? [] : $this->aggregate['columns'];

                    if ($column === '*' && $function === 'count' && ! $this->groups) {
                        $options = $this->inheritConnectionOptions($this->options);

                        return ['countDocuments' => [$wheres, $options]];
                    }

                    // "aggregate" is the name of the field that will hold the aggregated value.
                    if ($function === 'count') {
                        if ($column === '*' || $aggregations === []) {
                            // Translate count into sum.
                            $group['aggregate'] = ['$sum' => 1];
                        } else {
                            // Count the number of distinct values.
                            $group['aggregate'] = ['$addToSet' => '$' . $column];
                            $set['aggregate'] = ['$size' => '$aggregate'];
                        }
                    } else {
                        $group['aggregate'] = ['$' . $function => '$' . $column];
                    }
                }
            }

            // The _id field is mandatory when using grouping.
            if ($group && empty($group['_id'])) {
                $group['_id'] = null;
            }

            // Build the aggregation pipeline.
            $pipeline = [];
            if ($wheres) {
                $pipeline[] = ['$match' => $wheres];
            }

            // apply unwinds for subdocument array aggregation
            foreach ($unwinds as $unwind) {
                $pipeline[] = ['$unwind' => '$' . $unwind];
            }

            if ($group) {
                $pipeline[] = ['$group' => $group];
            }

            if ($set) {
                $pipeline[] = ['$set' => $set];
            }

            // Apply order and limit
            if ($this->orders) {
                $pipeline[] = ['$sort' => $this->aliasIdForQuery($this->orders)];
            }

            if ($this->offset) {
                $pipeline[] = ['$skip' => $this->offset];
            }

            if ($this->limit) {
                $pipeline[] = ['$limit' => $this->limit];
            }

            if ($this->projections) {
                $pipeline[] = ['$project' => $this->projections];
            }

            $options = [
                'typeMap' => ['root' => 'object', 'document' => 'array'],
            ];

            // Add custom query options
            if (count($this->options)) {
                $options = array_merge($options, $this->options);
            }

            $options = $this->inheritConnectionOptions($options);

            return ['aggregate' => [$pipeline, $options]];
        }

        // Distinct query
        if ($this->distinct) {
            // Return distinct results directly
            $column = $columns[0] ?? '_id';

            $options = $this->inheritConnectionOptions();

            return ['distinct' => [$column, $wheres, $options]];
        }

        // Normal query
        // Convert select columns to simple projections.
        $projection = $this->aliasIdForQuery(array_fill_keys($columns, true));

        // Add custom projections.
        if ($this->projections) {
            $projection = array_merge($projection, $this->projections);
        }

        $options = [];

        // Apply order, offset, limit and projection
        if ($this->timeout) {
            $options['maxTimeMS'] = (int) ($this->timeout * 1000);
        }

        if ($this->orders) {
            $options['sort'] = $this->aliasIdForQuery($this->orders);
        }

        if ($this->offset) {
            $options['skip'] = $this->offset;
        }

        if ($this->limit) {
            $options['limit'] = $this->limit;
        }

        if ($this->hint) {
            $options['hint'] = $this->hint;
        }

        if ($projection) {
            $options['projection'] = $projection;
        }

        $options['typeMap'] = ['root' => 'object', 'document' => 'array'];

        // Add custom query options
        if (count($this->options)) {
            $options = array_merge($options, $this->options);
        }

        $options = $this->inheritConnectionOptions($options);

        return ['find' => [$wheres, $options]];
    }

    /**
     * Execute the query as a fresh "select" statement.
     *
     * @param  array $columns
     * @param  bool  $returnLazy
     *
     * @return array|static[]|Collection|LazyCollection
     */
    public function getFresh($columns = [], $returnLazy = false)
    {
        // If no columns have been specified for the select statement, we will set them
        // here to either the passed columns, or the standard default of retrieving
        // all of the columns on the table using the "wildcard" column character.
        if ($this->columns === null) {
            $this->columns = $columns;
        }

        // Drop all columns if * is present, MongoDB does not work this way.
        if (in_array('*', $this->columns)) {
            $this->columns = [];
        }

        $command = $this->toMql();
        assert(count($command) >= 1, 'At least one method call is required to execute a query');

        $result = $this->collection;
        foreach ($command as $method => $arguments) {
            $result = call_user_func_array([$result, $method], $arguments);
        }

        // countDocuments method returns int, wrap it to the format expected by the framework
        if (is_int($result)) {
            $result = [
                [
                    '_id'       => null,
                    'aggregate' => $result,
                ],
            ];
        }

        if ($returnLazy) {
            return LazyCollection::make(function () use ($result) {
                foreach ($result as $item) {
                    yield $this->aliasIdForResult($item);
                }
            });
        }

        if ($result instanceof Cursor) {
            $result = $result->toArray();
        }

        foreach ($result as &$document) {
            if (is_array($document) || is_object($document)) {
                $document = $this->aliasIdForResult($document);
            }
        }

        return new Collection($result);
    }

    /**
     * Generate the unique cache key for the current query.
     *
     * @return string
     */
    public function generateCacheKey()
    {
        $key = [
            'connection' => $this->collection->getDatabaseName(),
            'collection' => $this->collection->getCollectionName(),
            'wheres' => $this->wheres,
            'columns' => $this->columns,
            'groups' => $this->groups,
            'orders' => $this->orders,
            'offset' => $this->offset,
            'limit' => $this->limit,
            'aggregate' => $this->aggregate,
        ];

        return md5(serialize(array_values($key)));
    }

    /** @return ($function is null ? AggregationBuilder : mixed) */
    public function aggregate($function = null, $columns = ['*'])
    {
        assert(is_array($columns), new TypeError(sprintf('Argument #2 ($columns) must be of type array, %s given', get_debug_type($columns))));

        if ($function === null) {
            if (! trait_exists(FluentFactoryTrait::class)) {
                // This error will be unreachable when the mongodb/builder package will be merged into mongodb/mongodb
                throw new BadMethodCallException('Aggregation builder requires package mongodb/builder 0.2+');
            }

            if ($columns !== ['*']) {
                throw new InvalidArgumentException('Columns cannot be specified to create an aggregation builder. Add a $project stage instead.');
            }

            if ($this->wheres) {
                throw new BadMethodCallException('Aggregation builder does not support previous query-builder instructions. Use a $match stage instead.');
            }

            return new AggregationBuilder($this->collection, $this->options);
        }

        $this->aggregate = [
            'function' => $function,
            'columns' => $columns,
        ];

        $previousColumns = $this->columns;

        // We will also back up the select bindings since the select clause will be
        // removed when performing the aggregate function. Once the query is run
        // we will add the bindings back onto this query so they can get used.
        $previousSelectBindings = $this->bindings['select'];

        $this->bindings['select'] = [];

        $results = $this->get($columns);

        // Once we have executed the query, we will reset the aggregate property so
        // that more select queries can be executed against the database without
        // the aggregate value getting in the way when the grammar builds it.
        $this->aggregate          = null;
        $this->columns            = $previousColumns;
        $this->bindings['select'] = $previousSelectBindings;

        // When the aggregation is per group, we return the results as is.
        if ($this->groups) {
            return $results->map(function (object $result) {
                unset($result->id);

                return $result;
            });
        }

        if (isset($results[0])) {
            $result = (array) $results[0];

            return $result['aggregate'];
        }
    }

    /**
     * {@inheritDoc}
     *
     * @see \Illuminate\Database\Query\Builder::aggregateByGroup()
     */
    public function aggregateByGroup(string $function, array $columns = ['*'])
    {
        if (count($columns) > 1) {
            throw new InvalidArgumentException('Aggregating by group requires zero or one columns.');
        }

        return $this->aggregate($function, $columns);
    }

    /** @inheritdoc */
    public function exists()
    {
        return $this->first(['id']) !== null;
    }

    /** @inheritdoc */
    public function distinct($column = false)
    {
        $this->distinct = true;

        if ($column) {
            $this->columns = [$column];
        }

        return $this;
    }

    /**
     * @param int|string|array $direction
     *
     * @inheritdoc
     */
    public function orderBy($column, $direction = 'asc')
    {
        if (is_string($direction)) {
            $direction = match ($direction) {
                'asc', 'ASC' => 1,
                'desc', 'DESC' => -1,
                default => throw new InvalidArgumentException('Order direction must be "asc" or "desc".'),
            };
        }

        $column = (string) $column;
        if ($column === 'natural') {
            $this->orders['$natural'] = $direction;
        } else {
            $this->orders[$column] = $direction;
        }

        return $this;
    }

    /** @inheritdoc */
    public function whereBetween($column, iterable $values, $boolean = 'and', $not = false)
    {
        $type = 'between';

        if ($values instanceof Collection) {
            $values = $values->all();
        }

        if (is_array($values) && (! array_is_list($values) || count($values) !== 2)) {
            throw new InvalidArgumentException('Between $values must be a list with exactly two elements: [min, max]');
        }

        $this->wheres[] = [
            'column'  => $column,
            'type'    => $type,
            'boolean' => $boolean,
            'values'  => $values,
            'not'     => $not,
        ];

        return $this;
    }

    /** @inheritdoc */
    public function insert(array $values)
    {
        // Allow empty insert batch for consistency with Eloquent SQL
        if ($values === []) {
            return true;
        }

        // Since every insert gets treated like a batch insert, we will have to detect
        // if the user is inserting a single document or an array of documents.
        $batch = true;

        foreach ($values as $value) {
            // As soon as we find a value that is not an array we assume the user is
            // inserting a single document.
            if (! is_array($value)) {
                $batch = false;
                break;
            }
        }

        if (! $batch) {
            $values = [$values];
        }

        $values = $this->aliasIdForQuery($values);

        $options = $this->inheritConnectionOptions();

        $result = $this->collection->insertMany($values, $options);

        return $result->isAcknowledged();
    }

    /** @inheritdoc */
    public function insertGetId(array $values, $sequence = null)
    {
        $options = $this->inheritConnectionOptions();

        $values = $this->aliasIdForQuery($values);

        $result = $this->collection->insertOne($values, $options);

        if (! $result->isAcknowledged()) {
            return null;
        }

        return match ($sequence) {
            '_id', 'id', null => $result->getInsertedId(),
            default => $values[$sequence],
        };
    }

    /** @inheritdoc */
    public function update(array $values, array $options = [])
    {
        // Use $set as default operator for field names that are not in an operator
        foreach ($values as $key => $value) {
            if (is_string($key) && str_starts_with($key, '$')) {
                continue;
            }

            $values['$set'][$key] = $value;
            unset($values[$key]);
        }

        // Since "id" is an alias for "_id", we prevent updating it
        foreach ($values as $fields) {
            if (array_key_exists('id', $fields)) {
                throw new InvalidArgumentException('Cannot update "id" field.');
            }
        }

        return $this->performUpdate($values, $options);
    }

    /** @inheritdoc */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        if ($values === []) {
            return 0;
        }

        // Single document provided
        if (! array_is_list($values)) {
            $values = [$values];
        }

        $this->applyBeforeQueryCallbacks();

        $options = $this->inheritConnectionOptions();
        $uniqueBy = array_fill_keys((array) $uniqueBy, 1);

        // If no update fields are specified, all fields are updated
        if ($update !== null) {
            $update = array_fill_keys((array) $update, 1);
        }

        $bulk = [];

        foreach ($values as $value) {
            $filter = $operation = [];
            foreach ($value as $key => $val) {
                if (isset($uniqueBy[$key])) {
                    $filter[$key] = $val;
                }

                if ($update === null || array_key_exists($key, $update)) {
                    $operation['$set'][$key] = $val;
                } else {
                    $operation['$setOnInsert'][$key] = $val;
                }
            }

            $bulk[] = ['updateOne' => [$filter, $operation, ['upsert' => true]]];
        }

        $result = $this->collection->bulkWrite($bulk, $options);

        return $result->getInsertedCount() + $result->getUpsertedCount() + $result->getModifiedCount();
    }

    /** @inheritdoc */
    public function increment($column, $amount = 1, array $extra = [], array $options = [])
    {
        $query = ['$inc' => [(string) $column => $amount]];

        if (! empty($extra)) {
            $query['$set'] = $extra;
        }

        // Protect
        $this->where(function ($query) use ($column) {
            $query->where($column, 'exists', false);

            $query->orWhereNotNull($column);
        });

        $options = $this->inheritConnectionOptions($options);

        return $this->performUpdate($query, $options);
    }

    public function incrementEach(array $columns, array $extra = [], array $options = [])
    {
        $stage['$addFields'] = $extra;

        // Not using $inc for each column, because it would fail if one column is null.
        foreach ($columns as $column => $amount) {
            $stage['$addFields'][$column] = [
                '$add' => [$amount, ['$ifNull' => ['$' . $column, 0]]],
            ];
        }

        $options = $this->inheritConnectionOptions($options);

        return $this->performUpdate([$stage], $options);
    }

    /** @inheritdoc */
    public function decrement($column, $amount = 1, array $extra = [], array $options = [])
    {
        return $this->increment($column, -1 * $amount, $extra, $options);
    }

    /** @inheritdoc */
    public function decrementEach(array $columns, array $extra = [], array $options = [])
    {
        $decrement = [];

        foreach ($columns as $column => $amount) {
            $decrement[$column] = -1 * $amount;
        }

        return $this->incrementEach($decrement, $extra, $options);
    }

    /** @inheritdoc */
    public function pluck($column, $key = null)
    {
        $results = $this->get($key === null ? [$column] : [$column, $key]);

        $p = Arr::pluck($results, $column, $key);

        return new Collection($p);
    }

    /** @inheritdoc */
    public function delete($id = null)
    {
        // If an ID is passed to the method, we will set the where clause to check
        // the ID to allow developers to simply and quickly remove a single row
        // from their database without manually specifying the where clauses.
        if ($id !== null) {
            $this->where('_id', '=', $id);
        }

        $wheres  = $this->compileWheres();
        $wheres  = $this->aliasIdForQuery($wheres);
        $options = $this->inheritConnectionOptions();

        /**
         * Ignore the limit if it is set to more than 1, as it is not supported by the deleteMany method.
         * Required for {@see DatabaseFailedJobProvider::prune()}
         */
        if ($this->limit === 1) {
            $result = $this->collection->deleteOne($wheres, $options);
        } else {
            $result = $this->collection->deleteMany($wheres, $options);
        }

        if ($result->isAcknowledged()) {
            return $result->getDeletedCount();
        }

        return 0;
    }

    /** @inheritdoc */
    public function from($collection, $as = null)
    {
        if ($collection) {
            $this->collection = $this->connection->getCollection($collection);
        }

        return parent::from($collection);
    }

    public function truncate(): bool
    {
        $options = $this->inheritConnectionOptions();
        $result  = $this->collection->deleteMany([], $options);

        return $result->isAcknowledged();
    }

    /**
     * Get an array with the values of a given column.
     *
     * @deprecated Use pluck instead.
     *
     * @param  string $column
     * @param  string $key
     *
     * @return Collection
     */
    public function lists($column, $key = null)
    {
        return $this->pluck($column, $key);
    }

    /** @inheritdoc */
    public function raw($value = null)
    {
        // Execute the closure on the mongodb collection
        if ($value instanceof Closure) {
            return call_user_func($value, $this->collection);
        }

        // Create an expression for the given value
        if ($value !== null) {
            return new Expression($value);
        }

        // Quick access to the mongodb collection
        return $this->collection;
    }

    /**
     * Append one or more values to an array.
     *
     * @param  string|array $column
     * @param  mixed        $value
     * @param  bool         $unique
     *
     * @return int
     */
    public function push($column, $value = null, $unique = false)
    {
        // Use the addToSet operator in case we only want unique items.
        $operator = $unique ? '$addToSet' : '$push';

        // Check if we are pushing multiple values.
        $batch = is_array($value) && array_is_list($value);

        if (is_array($column)) {
            if ($value !== null) {
                throw new InvalidArgumentException(sprintf('2nd argument of %s() must be "null" when 1st argument is an array. Got "%s" instead.', __METHOD__, get_debug_type($value)));
            }

            $query = [$operator => $column];
        } elseif ($batch) {
            $query = [$operator => [(string) $column => ['$each' => $value]]];
        } else {
            $query = [$operator => [(string) $column => $value]];
        }

        return $this->performUpdate($query);
    }

    /**
     * Remove one or more values from an array.
     *
     * @param  string|array $column
     * @param  mixed        $value
     *
     * @return int
     */
    public function pull($column, $value = null)
    {
        // Check if we passed an associative array.
        $batch = is_array($value) && array_is_list($value);

        // If we are pulling multiple values, we need to use $pullAll.
        $operator = $batch ? '$pullAll' : '$pull';

        if (is_array($column)) {
            $query = [$operator => $column];
        } else {
            $query = [$operator => [$column => $value]];
        }

        return $this->performUpdate($query);
    }

    /**
     * Remove one or more fields.
     *
     * @param  string|string[] $columns
     *
     * @return int
     */
    public function drop($columns)
    {
        if (! is_array($columns)) {
            $columns = [$columns];
        }

        $fields = [];

        foreach ($columns as $column) {
            $fields[$column] = 1;
        }

        $query = ['$unset' => $fields];

        return $this->performUpdate($query);
    }

    /**
     * @return static
     *
     * @inheritdoc
     */
    public function newQuery()
    {
        return new static($this->connection, $this->grammar, $this->processor);
    }

    public function runPaginationCountQuery($columns = ['*'])
    {
        if ($this->distinct) {
            throw new BadMethodCallException('Distinct queries cannot be used for pagination. Use GroupBy instead');
        }

        if ($this->groups || $this->havings) {
            $without = $this->unions ? ['orders', 'limit', 'offset'] : ['columns', 'orders', 'limit', 'offset'];

            $mql = $this->cloneWithout($without)
                ->cloneWithoutBindings($this->unions ? ['order'] : ['select', 'order'])
                ->toMql();

            // Adds the $count stage to the pipeline
            $mql['aggregate'][0][] = ['$count' => 'aggregate'];

            return $this->collection->aggregate($mql['aggregate'][0], $mql['aggregate'][1])->toArray();
        }

        return parent::runPaginationCountQuery($columns);
    }

    /**
     * Perform an update query.
     *
     * @return int
     */
    protected function performUpdate(array $update, array $options = [])
    {
        // Update multiple items by default.
        if (! array_key_exists('multiple', $options)) {
            $options['multiple'] = true;
        }

        $update = $this->aliasIdForQuery($update);

        $options = $this->inheritConnectionOptions($options);

        $wheres = $this->compileWheres();
        $wheres = $this->aliasIdForQuery($wheres);
        $result = $this->collection->updateMany($wheres, $update, $options);
        if ($result->isAcknowledged()) {
            return $result->getModifiedCount() ?: $result->getUpsertedCount();
        }

        return 0;
    }

    /**
     * Convert a key to ObjectID if needed.
     *
     * @param  mixed $id
     *
     * @return mixed
     */
    public function convertKey($id)
    {
        if (is_string($id) && strlen($id) === 24 && ctype_xdigit($id)) {
            return new ObjectID($id);
        }

        if (is_string($id) && strlen($id) === 16 && preg_match('~[^\x20-\x7E\t\r\n]~', $id) > 0) {
            return new Binary($id, Binary::TYPE_UUID);
        }

        return $id;
    }

    /**
     * Add a basic where clause to the query.
     *
     * If 1 argument, the signature is: where(array|Closure $where)
     * If 2 arguments, the signature is: where(string $column, mixed $value)
     * If 3 arguments, the signature is: where(string $colum, string $operator, mixed $value)
     *
     * @param  Closure|string|array $column
     * @param  mixed                $operator
     * @param  mixed                $value
     * @param  string               $boolean
     *
     * @return $this
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        $params = func_get_args();

        // Remove the leading $ from operators.
        if (func_num_args() >= 3) {
            $operator = &$params[1];

            if (is_string($operator) && str_starts_with($operator, '$')) {
                $operator = substr($operator, 1);
            }
        }

        if (func_num_args() === 1 && ! is_array($column) && ! is_callable($column)) {
            throw new ArgumentCountError(sprintf('Too few arguments to function %s(%s), 1 passed and at least 2 expected when the 1st is not an array or a callable', __METHOD__, var_export($column, true)));
        }

        if (is_float($column) || is_bool($column) || $column === null) {
            throw new InvalidArgumentException(sprintf('First argument of %s must be a field path as "string". Got "%s"', __METHOD__, get_debug_type($column)));
        }

        return parent::where(...$params);
    }

    /**
     * Compile the where array.
     *
     * @return array
     */
    protected function compileWheres(): array
    {
        // The wheres to compile.
        $wheres = $this->wheres ?: [];

        // We will add all compiled wheres to this array.
        $compiled = [];

        foreach ($wheres as $i => &$where) {
            // Make sure the operator is in lowercase.
            if (isset($where['operator'])) {
                $where['operator'] = strtolower($where['operator']);

                // Convert aliased operators
                if (isset($this->conversion[$where['operator']])) {
                    $where['operator'] = $this->conversion[$where['operator']];
                }
            }

            // Convert column name to string to use as array key
            if (isset($where['column'])) {
                $where['column'] = (string) $where['column'];

                // Compatibility with Eloquent queries that uses "id" instead of MongoDB's _id
                if ($where['column'] === 'id') {
                    $where['column'] = '_id';
                }

                // Convert id's.
                if ($where['column'] === '_id' || str_ends_with($where['column'], '._id')) {
                    if (isset($where['values'])) {
                        // Multiple values.
                        $where['values'] = array_map($this->convertKey(...), $where['values']);
                    } elseif (isset($where['value'])) {
                        // Single value.
                        $where['value'] = $this->convertKey($where['value']);
                    }
                }
            }

            // Convert CarbonPeriod to DateTime interval.
            if (isset($where['values']) && $where['values'] instanceof CarbonPeriod) {
                $where['values'] = [
                    $where['values']->getStartDate(),
                    $where['values']->getEndDate(),
                ];
            }

            // In a sequence of "where" clauses, the logical operator of the
            // first "where" is determined by the 2nd "where".
            // $where['boolean'] = "and", "or", "and not" or "or not"
            if (
                $i === 0 && count($wheres) > 1
                && str_starts_with($where['boolean'], 'and')
                && str_starts_with($wheres[$i + 1]['boolean'], 'or')
            ) {
                $where['boolean'] = 'or' . (str_ends_with($where['boolean'], 'not') ? ' not' : '');
            }

            // We use different methods to compile different wheres.
            $method = 'compileWhere' . $where['type'];
            $result = $this->{$method}($where);

            // Negate the expression
            if (str_ends_with($where['boolean'], 'not')) {
                $result = ['$nor' => [$result]];
            }

            // Wrap the where with an $or operator.
            if (str_starts_with($where['boolean'], 'or')) {
                $result = ['$or' => [$result]];
                // phpcs:ignore Squiz.ControlStructures.ControlSignature.SpaceAfterCloseBrace
            }

            // If there are multiple wheres, we will wrap it with $and. This is needed
            // to make nested wheres work.
            elseif (count($wheres) > 1) {
                $result = ['$and' => [$result]];
            }

            // Merge the compiled where with the others.
            // array_merge_recursive can't be used here because it converts int keys to sequential int.
            foreach ($result as $key => $value) {
                if (in_array($key, ['$and', '$or', '$nor'])) {
                    $compiled[$key] = array_merge($compiled[$key] ?? [], $value);
                } else {
                    $compiled[$key] = $value;
                }
            }
        }

        return $compiled;
    }

    protected function compileWhereBasic(array $where): array
    {
        $column   = $where['column'];
        $operator = $where['operator'];
        $value    = $where['value'];

        // Replace like or not like with a Regex instance.
        if (in_array($operator, ['like', 'not like'])) {
            $regex = preg_replace(
                [
                    // Unescaped % are converted to .*
                    // Group consecutive %
                    '#(^|[^\\\])%+#',
                    // Unescaped _ are converted to .
                    // Use positive lookahead to replace consecutive _
                    '#(?<=^|[^\\\\])_#',
                    // Escaped \% or \_ are unescaped
                    '#\\\\\\\(%|_)#',
                ],
                ['$1.*', '$1.', '$1'],
                // Escape any regex reserved characters, so they are matched
                // All backslashes are converted to \\, which are needed in matching regexes.
                preg_quote($value),
            );
            $flags = $where['caseSensitive'] ?? false ? '' : 'i';
            $value = new Regex('^' . $regex . '$', $flags);

            // For inverse like operations, we can just use the $not operator with the Regex
            $operator = $operator === 'like' ? '=' : 'not';
            // phpcs:ignore Squiz.ControlStructures.ControlSignature.SpaceAfterCloseBrace
        }

        // Manipulate regex operations.
        elseif (in_array($operator, ['regex', 'not regex'])) {
            // Automatically convert regular expression strings to Regex objects.
            if (is_string($value)) {
                // Detect the delimiter and validate the preg pattern
                $delimiter = substr($value, 0, 1);
                if (! in_array($delimiter, self::REGEX_DELIMITERS)) {
                    throw new LogicException(sprintf('Missing expected starting delimiter in regular expression "%s", supported delimiters are: %s', $value, implode(' ', self::REGEX_DELIMITERS)));
                }

                $e = explode($delimiter, $value);
                // We don't try to detect if the last delimiter is escaped. This would be an invalid regex.
                if (count($e) < 3) {
                    throw new LogicException(sprintf('Missing expected ending delimiter "%s" in regular expression "%s"', $delimiter, $value));
                }

                // Flags are after the last delimiter
                $flags = end($e);
                // Extract the regex string between the delimiters
                $regstr = substr($value, 1, -1 - strlen($flags));
                $value  = new Regex($regstr, $flags);
            }

            // For inverse regex operations, we can just use the $not operator with the Regex
            $operator = $operator === 'regex' ? '=' : 'not';
        }

        if (! isset($operator) || $operator === '=' || $operator === 'eq') {
            $query = [$column => $value];
        } else {
            $query = [$column => ['$' . $operator => $value]];
        }

        return $query;
    }

    protected function compileWhereNested(array $where): mixed
    {
        return $where['query']->compileWheres();
    }

    protected function compileWhereIn(array $where): array
    {
        return [$where['column'] => ['$in' => array_values($where['values'])]];
    }

    protected function compileWhereNotIn(array $where): array
    {
        return [$where['column'] => ['$nin' => array_values($where['values'])]];
    }

    protected function compileWhereLike(array $where): array
    {
        $where['operator'] = $where['not'] ? 'not like' : 'like';

        return $this->compileWhereBasic($where);
    }

    protected function compileWhereNull(array $where): array
    {
        $where['operator'] = '=';
        $where['value']    = null;

        return $this->compileWhereBasic($where);
    }

    protected function compileWhereNotNull(array $where): array
    {
        $where['operator'] = 'ne';
        $where['value']    = null;

        return $this->compileWhereBasic($where);
    }

    protected function compileWhereBetween(array $where): array
    {
        $column = $where['column'];
        $not    = $where['not'];
        $values = $where['values'];

        if ($not) {
            return [
                '$or' => [
                    [
                        $column => [
                            '$lte' => $values[0],
                        ],
                    ],
                    [
                        $column => [
                            '$gte' => $values[1],
                        ],
                    ],
                ],
            ];
        }

        return [
            $column => [
                '$gte' => $values[0],
                '$lte' => $values[1],
            ],
        ];
    }

    protected function compileWhereDate(array $where): array
    {
        $startOfDay = new UTCDateTime(Carbon::parse($where['value'])->startOfDay());
        $endOfDay   = new UTCDateTime(Carbon::parse($where['value'])->endOfDay());

        return match ($where['operator']) {
            'eq', '=' => [
                $where['column'] => [
                    '$gte' => $startOfDay,
                    '$lte' => $endOfDay,
                ],
            ],
            'ne' => [
                $where['column'] => [
                    '$not' => [
                        '$gte' => $startOfDay,
                        '$lte' => $endOfDay,
                    ],
                ],
            ],
            'lt', 'gte' => [
                $where['column'] => ['$' . $where['operator'] => $startOfDay],
            ],
            'gt', 'lte' => [
                $where['column'] => ['$' . $where['operator'] => $endOfDay],
            ],
        };
    }

    protected function compileWhereMonth(array $where): array
    {
        return [
            '$expr' => [
                '$' . $where['operator'] => [
                    [
                        '$month' => '$' . $where['column'],
                    ],
                    (int) $where['value'],
                ],
            ],
        ];
    }

    protected function compileWhereDay(array $where): array
    {
        return [
            '$expr' => [
                '$' . $where['operator'] => [
                    [
                        '$dayOfMonth' => '$' . $where['column'],
                    ],
                    (int) $where['value'],
                ],
            ],
        ];
    }

    protected function compileWhereYear(array $where): array
    {
        return [
            '$expr' => [
                '$' . $where['operator'] => [
                    [
                        '$year' => '$' . $where['column'],
                    ],
                    (int) $where['value'],
                ],
            ],
        ];
    }

    protected function compileWhereTime(array $where): array
    {
        if (! is_string($where['value']) || ! preg_match('/^[0-2][0-9](:[0-6][0-9](:[0-6][0-9])?)?$/', $where['value'], $matches)) {
            throw new InvalidArgumentException(sprintf('Invalid time format, expected HH:MM:SS, HH:MM or HH, got "%s"', is_string($where['value']) ? $where['value'] : get_debug_type($where['value'])));
        }

        $format = match (count($matches)) {
            1 => '%H',
            2 => '%H:%M',
            3 => '%H:%M:%S',
        };

        return [
            '$expr' => [
                '$' . $where['operator'] => [
                    [
                        '$dateToString' => ['date' => '$' . $where['column'], 'format' => $format],
                    ],
                    $where['value'],
                ],
            ],
        ];
    }

    protected function compileWhereRaw(array $where): mixed
    {
        return $where['sql'];
    }

    protected function compileWhereSub(array $where): mixed
    {
        $where['value'] = $where['query']->compileWheres();

        return $this->compileWhereBasic($where);
    }

    /**
     * Set custom options for the query.
     *
     * @return $this
     */
    public function options(array $options)
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Set the read preference for the query
     *
     * @see https://www.php.net/manual/en/class.mongodb-driver-readpreference.php
     *
     * @param  string $mode
     * @param  array  $tagSets
     * @param  array  $options
     *
     * @return $this
     */
    public function readPreference(string $mode, ?array $tagSets = null, ?array $options = null): static
    {
        $this->readPreference = new ReadPreference($mode, $tagSets, $options);

        return $this;
    }

    /**
     * Performs a full-text search of the field or fields in an Atlas collection.
     * NOTE: $search is only available for MongoDB Atlas clusters, and is not available for self-managed deployments.
     *
     * @see https://www.mongodb.com/docs/atlas/atlas-search/aggregation-stages/search/
     *
     * @return Collection<object|array>
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
        // Forward named arguments to the search stage, skip null values
        $args = array_filter([
            'operator' => $operator,
            'index' => $index,
            'highlight' => $highlight,
            'concurrent' => $concurrent,
            'count' => $count,
            'searchAfter' => $searchAfter,
            'searchBefore' => $searchBefore,
            'scoreDetails' => $scoreDetails,
            'sort' => $sort,
            'returnStoredSource' => $returnStoredSource,
            'tracking' => $tracking,
        ], fn ($arg) => $arg !== null);

        return $this->aggregate()->search(...$args)->get();
    }

    /**
     * Performs a semantic search on data in your Atlas Vector Search index.
     * NOTE: $vectorSearch is only available for MongoDB Atlas clusters, and is not available for self-managed deployments.
     *
     * @see https://www.mongodb.com/docs/atlas/atlas-vector-search/vector-search-stage/
     *
     * @return Collection<object|array>
     */
    public function vectorSearch(
        string $index,
        string $path,
        array $queryVector,
        int $limit,
        bool $exact = false,
        QueryInterface|array|null $filter = null,
        int|null $numCandidates = null,
    ): Collection {
        // Forward named arguments to the vectorSearch stage, skip null values
        $args = array_filter([
            'index' => $index,
            'limit' => $limit,
            'path' => $path,
            'queryVector' => $queryVector,
            'exact' => $exact,
            'filter' => $filter,
            'numCandidates' => $numCandidates,
        ], fn ($arg) => $arg !== null);

        return $this->aggregate()
            ->vectorSearch(...$args)
            ->addFields(vectorSearchScore: ['$meta' => 'vectorSearchScore'])
            ->get();
    }

    /**
     * Performs an autocomplete search of the field using an Atlas Search index.
     * NOTE: $search is only available for MongoDB Atlas clusters, and is not available for self-managed deployments.
     * You must create an Atlas Search index with an autocomplete configuration before you can use this stage.
     *
     * @see https://www.mongodb.com/docs/atlas/atlas-search/autocomplete/
     *
     * @return Collection<string>
     */
    public function autocomplete(string $path, string $query, bool|array $fuzzy = false, string $tokenOrder = 'any'): Collection
    {
        $args = ['path' => $path, 'query' => $query, 'tokenOrder' => $tokenOrder];
        if ($fuzzy === true) {
            $args['fuzzy'] = ['maxEdits' => 2];
        } elseif ($fuzzy !== false) {
            $args['fuzzy'] = $fuzzy;
        }

        return $this->aggregate()->search(
            Search::autocomplete(...$args),
        )->get()->pluck($path);
    }

    /**
     * Apply the connection's session to options if it's not already specified.
     */
    private function inheritConnectionOptions(array $options = []): array
    {
        if (! isset($options['session'])) {
            $session = $this->connection->getSession();
            if ($session) {
                $options['session'] = $session;
            }
        }

        if (! isset($options['readPreference']) && isset($this->readPreference)) {
            $options['readPreference'] = $this->readPreference;
        }

        return $options;
    }

    /** @inheritdoc */
    public function __call($method, $parameters)
    {
        if ($method === 'unset') {
            return $this->drop(...$parameters);
        }

        return parent::__call($method, $parameters);
    }

    /** @internal This method is not supported by MongoDB. */
    public function toSql()
    {
        throw new BadMethodCallException('This method is not supported by MongoDB. Try "toMql()" instead.');
    }

    /** @internal This method is not supported by MongoDB. */
    public function toRawSql()
    {
        throw new BadMethodCallException('This method is not supported by MongoDB. Try "toMql()" instead.');
    }

    /** @internal This method is not supported by MongoDB. */
    public function whereColumn($first, $operator = null, $second = null, $boolean = 'and')
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function whereFullText($columns, $value, array $options = [], $boolean = 'and')
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function groupByRaw($sql, array $bindings = [])
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function orderByRaw($sql, $bindings = [])
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function unionAll($query)
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function union($query, $all = false)
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function having($column, $operator = null, $value = null, $boolean = 'and')
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function havingRaw($sql, array $bindings = [], $boolean = 'and')
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function havingBetween($column, iterable $values, $boolean = 'and', $not = false)
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function whereIntegerInRaw($column, $values, $boolean = 'and', $not = false)
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function orWhereIntegerInRaw($column, $values)
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function whereIntegerNotInRaw($column, $values, $boolean = 'and')
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    /** @internal This method is not supported by MongoDB. */
    public function orWhereIntegerNotInRaw($column, $values, $boolean = 'and')
    {
        throw new BadMethodCallException('This method is not supported by MongoDB');
    }

    private function aliasIdForQuery(array $values): array
    {
        if (array_key_exists('id', $values)) {
            if (array_key_exists('_id', $values) && $values['id'] !== $values['_id']) {
                throw new InvalidArgumentException('Cannot have both "id" and "_id" fields.');
            }

            $values['_id'] = $values['id'];
            unset($values['id']);
        }

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            // "->" arrow notation for subfields is an alias for "." dot notation
            if (str_contains($key, '->')) {
                $newkey = str_replace('->', '.', $key);
                if (array_key_exists($newkey, $values) && $value !== $values[$newkey]) {
                    throw new InvalidArgumentException(sprintf('Cannot have both "%s" and "%s" fields.', $key, $newkey));
                }

                $values[$newkey] = $value;
                unset($values[$key]);
                $key = $newkey;
            }

            // ".id" subfield are alias for "._id"
            if (str_ends_with($key, '.id')) {
                $newkey = substr($key, 0, -3) . '._id';
                if (array_key_exists($newkey, $values) && $value !== $values[$newkey]) {
                    throw new InvalidArgumentException(sprintf('Cannot have both "%s" and "%s" fields.', $key, $newkey));
                }

                $values[substr($key, 0, -3) . '._id'] = $value;
                unset($values[$key]);
            }
        }

        foreach ($values as &$value) {
            if (is_array($value)) {
                $value = $this->aliasIdForQuery($value);
            } elseif ($value instanceof DateTimeInterface) {
                $value = new UTCDateTime($value);
            }
        }

        return $values;
    }

    /**
     * @internal
     *
     * @psalm-param T $values
     *
     * @psalm-return T
     *
     * @template T of array|object
     */
    public function aliasIdForResult(array|object $values): array|object
    {
        if (is_array($values)) {
            if (array_key_exists('_id', $values) && ! array_key_exists('id', $values)) {
                $values['id'] = $values['_id'];
                unset($values['_id']);
            }

            foreach ($values as $key => $value) {
                if ($value instanceof UTCDateTime) {
                    $values[$key] = Date::instance($value->toDateTime())
                        ->setTimezone(new DateTimeZone(date_default_timezone_get()));
                } elseif (is_array($value) || is_object($value)) {
                    $values[$key] = $this->aliasIdForResult($value);
                }
            }
        }

        if ($values instanceof stdClass) {
            if (property_exists($values, '_id') && ! property_exists($values, 'id')) {
                $values->id = $values->_id;
                unset($values->_id);
            }

            foreach (get_object_vars($values) as $key => $value) {
                if ($value instanceof UTCDateTime) {
                    $values->{$key} = Date::instance($value->toDateTime())
                        ->setTimezone(new DateTimeZone(date_default_timezone_get()));
                } elseif (is_array($value) || is_object($value)) {
                    $values->{$key} = $this->aliasIdForResult($value);
                }
            }
        }

        return $values;
    }
}
