<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Support\Arr;
use MongoDB\Laravel\Eloquent\Model as DocumentModel;

use function array_diff;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function assert;
use function count;
use function in_array;
use function is_numeric;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 * @extends EloquentBelongsToMany<TRelatedModel, TDeclaringModel>
 */
class BelongsToMany extends EloquentBelongsToMany
{
    /**
     * Get the key for comparing against the parent key in "has" query.
     *
     * @return string
     */
    public function getHasCompareKey()
    {
        return $this->getForeignKey();
    }

    /** @inheritdoc */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        return $query;
    }

    /** @inheritdoc */
    protected function hydratePivotRelation(array $models)
    {
        // Do nothing.
    }

    /**
     * Set the select clause for the relation query.
     *
     * @return array
     */
    protected function getSelectColumns(array $columns = ['*'])
    {
        return $columns;
    }

    /** @inheritdoc */
    protected function shouldSelect(array $columns = ['*'])
    {
        return $columns;
    }

    /** @inheritdoc */
    public function addConstraints()
    {
        if (static::$constraints) {
            $this->setWhere();
        }
    }

    /**
     * Set the where clause for the relation query.
     *
     * @return $this
     */
    protected function setWhere()
    {
        $foreign = $this->getForeignKey();

        $this->query->where($foreign, '=', $this->parent->{$this->parentKey});

        return $this;
    }

    /** @inheritdoc */
    public function save(Model $model, array $pivotAttributes = [], $touch = true)
    {
        $model->save(['touch' => false]);

        $this->attach($model, $pivotAttributes, $touch);

        return $model;
    }

    /** @inheritdoc */
    public function create(array $attributes = [], array $joining = [], $touch = true)
    {
        $instance = $this->related->newInstance($attributes);

        // Once we save the related model, we need to attach it to the base model via
        // through intermediate table so we'll use the existing "attach" method to
        // accomplish this which will insert the record and any more attributes.
        $instance->save(['touch' => false]);

        $this->attach($instance, $joining, $touch);

        return $instance;
    }

    /** @inheritdoc */
    public function sync($ids, $detaching = true)
    {
        $changes = [
            'attached' => [],
            'detached' => [],
            'updated' => [],
        ];

        if ($ids instanceof Collection) {
            $ids = $this->parseIds($ids);
        } elseif ($ids instanceof Model) {
            $ids = $this->parseIds($ids);
        }

        // First we need to attach any of the associated models that are not currently
        // in this joining table. We'll spin through the given IDs, checking to see
        // if they exist in the array of current ones, and if not we will insert.
        $current = match (\MongoDB\Laravel\Eloquent\Model::isDocumentModel($this->parent)) {
            true => $this->parent->{$this->relatedPivotKey} ?: [],
            false => $this->parent->{$this->relationName} ?: [],
        };

        if ($current instanceof Collection) {
            $current = $this->parseIds($current);
        }

        $records = $this->formatRecordsList($ids);

        $current = Arr::wrap($current);

        $detach = array_diff($current, array_keys($records));

        // We need to make sure we pass a clean array, so that it is not interpreted
        // as an associative array.
        $detach = array_values($detach);

        // Next, we will take the differences of the currents and given IDs and detach
        // all of the entities that exist in the "current" array but are not in the
        // the array of the IDs given to the method which will complete the sync.
        if ($detaching && count($detach) > 0) {
            $this->detach($detach);

            $changes['detached'] = (array) array_map(function ($v) {
                return is_numeric($v) ? (int) $v : (string) $v;
            }, $detach);
        }

        // Now we are finally ready to attach the new records. Note that we'll disable
        // touching until after the entire operation is complete so we don't fire a
        // ton of touch operations until we are totally done syncing the records.
        $changes = array_merge(
            $changes,
            $this->attachNew($records, $current, false),
        );

        if (count($changes['attached']) || count($changes['updated'])) {
            $this->touchIfTouching();
        }

        return $changes;
    }

    /** @inheritdoc */
    public function updateExistingPivot($id, array $attributes, $touch = true)
    {
        // Do nothing, we have no pivot table.
        return $this;
    }

    /** @inheritdoc */
    public function attach($id, array $attributes = [], $touch = true)
    {
        if ($id instanceof Model) {
            $model = $id;

            $id = $this->parseId($model);

            // Attach the new parent id to the related model.
            $model->push($this->foreignPivotKey, $this->parent->{$this->parentKey}, true);
        } else {
            if ($id instanceof Collection) {
                $id = $this->parseIds($id);
            }

            $query = $this->newRelatedQuery();

            $query->whereIn($this->relatedKey, (array) $id);

            // Attach the new parent id to the related model.
            $query->push($this->foreignPivotKey, $this->parent->{$this->parentKey}, true);
        }

        // Attach the new ids to the parent model.
        if (\MongoDB\Laravel\Eloquent\Model::isDocumentModel($this->parent)) {
            $this->parent->push($this->relatedPivotKey, (array) $id, true);
        } else {
            $instance = new $this->related();
            $instance->forceFill([$this->relatedKey => $id]);
            $relationData = $this->parent->{$this->relationName}->push($instance)->unique($this->relatedKey);
            $this->parent->setRelation($this->relationName, $relationData);
        }

        if (! $touch) {
            return;
        }

        $this->touchIfTouching();
    }

    /** @inheritdoc */
    public function detach($ids = [], $touch = true)
    {
        if ($ids instanceof Model) {
            $ids = $this->parseIds($ids);
        }

        $query = $this->newRelatedQuery();

        // If associated IDs were passed to the method we will only delete those
        // associations, otherwise all of the association ties will be broken.
        // We'll return the numbers of affected rows when we do the deletes.
        $ids = (array) $ids;

        // Detach all ids from the parent model.
        if (DocumentModel::isDocumentModel($this->parent)) {
            $this->parent->pull($this->relatedPivotKey, $ids);
        } else {
            $value = $this->parent->{$this->relationName}
                ->filter(fn ($rel) => ! in_array($rel->{$this->relatedKey}, $ids));
            $this->parent->setRelation($this->relationName, $value);
        }

        // Prepare the query to select all related objects.
        if (count($ids) > 0) {
            $query->whereIn($this->relatedKey, $ids);
        }

        // Remove the relation to the parent.
        assert($this->parent instanceof Model);
        assert($query instanceof \MongoDB\Laravel\Eloquent\Builder);
        $query->pull($this->foreignPivotKey, $this->parent->{$this->parentKey});

        if ($touch) {
            $this->touchIfTouching();
        }

        return count($ids);
    }

    /** @inheritdoc */
    protected function buildDictionary(Collection $results)
    {
        $foreign = $this->foreignPivotKey;

        // First we will build a dictionary of child models keyed by the foreign key
        // of the relation so that we will easily and quickly match them to their
        // parents without having a possibly slow inner loops for every models.
        $dictionary = [];

        foreach ($results as $result) {
            foreach ($result->$foreign as $item) {
                $dictionary[$item][] = $result;
            }
        }

        return $dictionary;
    }

    /** @inheritdoc */
    public function newPivotQuery()
    {
        return $this->newRelatedQuery();
    }

    /**
     * Create a new query builder for the related model.
     *
     * @return Builder|Model
     */
    public function newRelatedQuery()
    {
        return $this->related->newQuery();
    }

    /**
     * Get the fully qualified foreign key for the relation.
     *
     * @return string
     */
    public function getForeignKey()
    {
        return $this->foreignPivotKey;
    }

    /** @inheritdoc */
    public function getQualifiedForeignPivotKeyName()
    {
        return $this->foreignPivotKey;
    }

    /** @inheritdoc */
    public function getQualifiedRelatedPivotKeyName()
    {
        return $this->relatedPivotKey;
    }

    /**
     * Get the name of the "where in" method for eager loading.
     *
     * @param string $key
     *
     * @return string
     */
    protected function whereInMethod(Model $model, $key)
    {
        return 'whereIn';
    }
}
