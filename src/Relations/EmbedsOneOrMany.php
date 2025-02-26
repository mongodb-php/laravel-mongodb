<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Expression;
use MongoDB\Driver\Exception\LogicException;
use MongoDB\Laravel\Eloquent\Model as DocumentModel;
use Throwable;

use function array_merge;
use function assert;
use function count;
use function is_array;
use function str_starts_with;
use function throw_if;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 * @template TResult
 * @extends Relation<TRelatedModel, TDeclaringModel, TResult>
 */
abstract class EmbedsOneOrMany extends Relation
{
    /**
     * The local key of the parent model.
     *
     * @var string
     */
    protected $localKey;

    /**
     * The foreign key of the parent model.
     *
     * @var string
     */
    protected $foreignKey;

    /**
     * The "name" of the relationship.
     *
     * @var string
     */
    protected $relation;

    /**
     * Create a new embeds many relationship instance.
     */
    public function __construct(Builder $query, Model $parent, Model $related, string $localKey, string $foreignKey, string $relation)
    {
        if (! DocumentModel::isDocumentModel($parent)) {
            throw new LogicException('Parent model must be a document model.');
        }

        if (! DocumentModel::isDocumentModel($related)) {
            throw new LogicException('Related model must be a document model.');
        }

        parent::__construct($query, $parent);

        $this->related    = $related;
        $this->localKey   = $localKey;
        $this->foreignKey = $foreignKey;
        $this->relation   = $relation;

        // If this is a nested relation, we need to get the parent query instead.
        $parentRelation = $this->getParentRelation();
        if ($parentRelation) {
            $this->query = $parentRelation->getQuery();
        }
    }

    /** @inheritdoc */
    public function addConstraints()
    {
        if (static::$constraints) {
            $this->query->where($this->getQualifiedParentKeyName(), '=', $this->getParentKey());
        }
    }

    /** @inheritdoc */
    public function addEagerConstraints(array $models)
    {
        // There are no eager loading constraints.
    }

    /** @inheritdoc */
    public function match(array $models, Collection $results, $relation)
    {
        foreach ($models as $model) {
            $results = $model->$relation()->getResults();

            $model->setParentRelation($this);

            $model->setRelation($relation, $results);
        }

        return $models;
    }

    /**
     * Shorthand to get the results of the relationship.
     *
     * @param  array $columns
     *
     * @return Collection
     */
    public function get($columns = ['*'])
    {
        return $this->getResults();
    }

    /**
     * Get the number of embedded models.
     *
     * @param Expression|string $columns
     *
     * @throws LogicException|Throwable
     *
     * @note The $column parameter is not used to count embedded models.
     */
    public function count($columns = '*'): int
    {
        throw_if($columns !== '*', new LogicException('The columns parameter should not be used.'));

        return count($this->getEmbedded());
    }

    /**
     * Attach a model instance to the parent model.
     *
     * @return Model|bool
     */
    public function save(Model $model)
    {
        $model->setParentRelation($this);

        return $model->save() ? $model : false;
    }

    /**
     * Attach a collection of models to the parent instance.
     *
     * @param  Collection|array $models
     *
     * @return Collection|array
     */
    public function saveMany($models)
    {
        foreach ($models as $model) {
            $this->save($model);
        }

        return $models;
    }

    /**
     * Create a new instance of the related model.
     *
     * @return Model
     */
    public function create(array $attributes = [])
    {
        // Here we will set the raw attributes to avoid hitting the "fill" method so
        // that we do not have to worry about a mass accessor rules blocking sets
        // on the models. Otherwise, some of these attributes will not get set.
        $instance = $this->related->newInstance($attributes);

        $instance->setParentRelation($this);

        $instance->save();

        return $instance;
    }

    /**
     * Create an array of new instances of the related model.
     *
     * @return array
     */
    public function createMany(array $records)
    {
        $instances = [];

        foreach ($records as $record) {
            $instances[] = $this->create($record);
        }

        return $instances;
    }

    /**
     * Transform single ID, single Model or array of Models into an array of IDs.
     *
     * @param  mixed $ids
     *
     * @return array
     */
    protected function getIdsArrayFrom($ids)
    {
        if ($ids instanceof \Illuminate\Support\Collection) {
            $ids = $ids->all();
        }

        if (! is_array($ids)) {
            $ids = [$ids];
        }

        foreach ($ids as &$id) {
            if ($id instanceof Model) {
                $id = $id->getKey();
            }
        }

        return $ids;
    }

    /** @inheritdoc */
    protected function getEmbedded()
    {
        // Get raw attributes to skip relations and accessors.
        $attributes = $this->parent->getAttributes();

        // Get embedded models form parent attributes.
        return isset($attributes[$this->localKey]) ? (array) $attributes[$this->localKey] : null;
    }

    /** @inheritdoc */
    protected function setEmbedded($records)
    {
        // Assign models to parent attributes array.
        $attributes                  = $this->parent->getAttributes();
        $attributes[$this->localKey] = $records;

        // Set raw attributes to skip mutators.
        $this->parent->setRawAttributes($attributes);

        // Set the relation on the parent.
        return $this->parent->setRelation($this->relation, $records === null ? null : $this->getResults());
    }

    /**
     * Get the foreign key value for the relation.
     *
     * @param  mixed $id
     *
     * @return mixed
     */
    protected function getForeignKeyValue($id)
    {
        if ($id instanceof Model) {
            $id = $id->getKey();
        }

        // Convert the id to MongoId if necessary.
        return $this->toBase()->convertKey($id);
    }

    /**
     * Convert an array of records to a Collection.
     *
     * @return Collection
     */
    protected function toCollection(array $records = [])
    {
        $models = [];

        foreach ($records as $attributes) {
            $models[] = $this->toModel($attributes);
        }

        if (count($models) > 0) {
            $models = $this->eagerLoadRelations($models);
        }

        return $this->related->newCollection($models);
    }

    /**
     * Create a related model instanced.
     *
     * @param mixed $attributes
     *
     * @return Model | null
     */
    protected function toModel(mixed $attributes = []): Model|null
    {
        if ($attributes === null) {
            return null;
        }

        $connection = $this->related->getConnection();

        $model = $this->related->newFromBuilder(
            (array) $attributes,
            $connection?->getName(),
        );

        $model->setParentRelation($this);

        $model->setRelation($this->foreignKey, $this->parent);

        // If you remove this, you will get segmentation faults!
        $model->setHidden(array_merge($model->getHidden(), [$this->foreignKey]));

        return $model;
    }

    /**
     * Get the relation instance of the parent.
     *
     * @return Relation
     */
    protected function getParentRelation()
    {
        return $this->parent->getParentRelation();
    }

    /** @inheritdoc */
    public function getQuery()
    {
        // Because we are sharing this relation instance to models, we need
        // to make sure we use separate query instances.
        return clone $this->query;
    }

    /** @inheritdoc */
    public function toBase()
    {
        // Because we are sharing this relation instance to models, we need
        // to make sure we use separate query instances.
        return clone $this->query->getQuery();
    }

    /**
     * Check if this relation is nested in another relation.
     *
     * @return bool
     */
    protected function isNested()
    {
        return $this->getParentRelation() !== null;
    }

    /**
     * Get the fully qualified local key name.
     *
     * @param  string $glue
     *
     * @return string
     */
    protected function getPathHierarchy($glue = '.')
    {
        $parentRelation = $this->getParentRelation();
        if ($parentRelation) {
            return $parentRelation->getPathHierarchy($glue) . $glue . $this->localKey;
        }

        return $this->localKey;
    }

    /** @inheritdoc */
    public function getQualifiedParentKeyName()
    {
        $parentRelation = $this->getParentRelation();
        if ($parentRelation) {
            return $parentRelation->getPathHierarchy() . '.' . $this->parent->getKeyName();
        }

        return $this->parent->getKeyName();
    }

    /**
     * Get the primary key value of the parent.
     *
     * @return string
     */
    protected function getParentKey()
    {
        return $this->parent->getKey();
    }

    /**
     * Return update values.
     *
     * @param array  $array
     * @param string $prepend
     *
     * @return array
     */
    public static function getUpdateValues($array, $prepend = '')
    {
        $results = [];

        foreach ($array as $key => $value) {
            if (str_starts_with($key, '$')) {
                assert(is_array($value), 'Update operator value must be an array.');
                $results[$key] = static::getUpdateValues($value, $prepend);
            } else {
                $results[$prepend . $key] = $value;
            }
        }

        return $results;
    }

    /**
     * Get the foreign key for the relationship.
     *
     * @return string
     */
    public function getQualifiedForeignKeyName()
    {
        return $this->foreignKey;
    }

    /**
     * Get the name of the "where in" method for eager loading.
     *
     * @param EloquentModel $model
     * @param  string        $key
     *
     * @return string
     */
    protected function whereInMethod(EloquentModel $model, $key)
    {
        return 'whereIn';
    }
}
