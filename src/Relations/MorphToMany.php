<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany as EloquentMorphToMany;
use Illuminate\Support\Arr;
use MongoDB\BSON\ObjectId;

use function array_diff;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_reduce;
use function array_values;
use function collect;
use function count;
use function in_array;
use function is_array;
use function is_numeric;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 * @extends EloquentMorphToMany<TRelatedModel, TDeclaringModel>
 */
class MorphToMany extends EloquentMorphToMany
{
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

    /** @inheritdoc */
    public function addEagerConstraints(array $models)
    {
        // To load relation's data, we act normally on MorphToMany relation,
        // But on MorphedByMany relation, we collect related ids from pivot column
        // and add to a whereIn condition
        if ($this->getInverse()) {
            $ids = $this->getKeys($models, $this->table);
            $ids = $this->extractIds($ids[0] ?? []);
            $this->query->whereIn($this->relatedKey, $ids);
        } else {
            parent::addEagerConstraints($models);

            $this->query->where($this->qualifyPivotColumn($this->morphType), $this->morphClass);
        }
    }

    /**
     * Set the where clause for the relation query.
     *
     * @return $this
     */
    protected function setWhere()
    {
        if ($this->getInverse()) {
            if (\MongoDB\Laravel\Eloquent\Model::isDocumentModel($this->parent)) {
                $ids = $this->extractIds((array) $this->parent->{$this->table});

                $this->query->whereIn($this->relatedKey, $ids);
            } else {
                $this->query
                    ->whereIn($this->foreignPivotKey, (array) $this->parent->{$this->parentKey});
            }
        } else {
            match (\MongoDB\Laravel\Eloquent\Model::isDocumentModel($this->parent)) {
                true => $this->query->whereIn($this->relatedKey, (array) $this->parent->{$this->relatedPivotKey}),
                false => $this->query
                    ->whereIn($this->getQualifiedForeignPivotKeyName(), (array) $this->parent->{$this->parentKey}),
            };
        }

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
        if ($this->getInverse()) {
            $current = match (\MongoDB\Laravel\Eloquent\Model::isDocumentModel($this->parent)) {
                true => $this->parent->{$this->table} ?: [],
                false => $this->parent->{$this->relationName} ?: [],
            };

            if ($current instanceof Collection) {
                $current = collect($this->parseIds($current))->flatten()->toArray();
            } else {
                $current = $this->extractIds($current);
            }
        } else {
            $current = match (\MongoDB\Laravel\Eloquent\Model::isDocumentModel($this->parent)) {
                true => $this->parent->{$this->relatedPivotKey} ?: [],
                false => $this->parent->{$this->relationName} ?: [],
            };

            if ($current instanceof Collection) {
                $current = $this->parseIds($current);
            }
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

            $changes['detached'] = array_map(function ($v) {
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
    public function updateExistingPivot($id, array $attributes, $touch = true): void
    {
        // Do nothing, we have no pivot table.
    }

    /** @inheritdoc */
    public function attach($id, array $attributes = [], $touch = true)
    {
        if ($id instanceof Model) {
            $model = $id;

            $id = $this->parseId($model);

            if ($this->getInverse()) {
                // Attach the new ids to the parent model.
                if (\MongoDB\Laravel\Eloquent\Model::isDocumentModel($this->parent)) {
                    $this->parent->push($this->table, [
                        [
                            $this->relatedPivotKey => $model->{$this->relatedKey},
                            $this->morphType => $model->getMorphClass(),
                        ],
                    ], true);
                } else {
                    $this->addIdToParentRelationData($id);
                }

                // Attach the new parent id to the related model.
                $model->push($this->foreignPivotKey, (array) $this->parent->{$this->parentKey}, true);
            } else {
                // Attach the new parent id to the related model.
                $model->push($this->table, [
                    [
                        $this->foreignPivotKey => $this->parent->{$this->parentKey},
                        $this->morphType => $this->parent instanceof Model ? $this->parent->getMorphClass() : null,
                    ],
                ], true);

                // Attach the new ids to the parent model.
                if (\MongoDB\Laravel\Eloquent\Model::isDocumentModel($this->parent)) {
                    $this->parent->push($this->relatedPivotKey, (array) $id, true);
                } else {
                    $this->addIdToParentRelationData($id);
                }
            }
        } else {
            if ($id instanceof Collection) {
                $id = $this->parseIds($id);
            }

            $id = (array) $id;

            $query = $this->newRelatedQuery();
            $query->whereIn($this->relatedKey, $id);

            if ($this->getInverse()) {
                // Attach the new parent id to the related model.
                $query->push($this->foreignPivotKey, $this->parent->{$this->parentKey});

                // Attach the new ids to the parent model.
                if (\MongoDB\Laravel\Eloquent\Model::isDocumentModel($this->parent)) {
                    foreach ($id as $item) {
                        $this->parent->push($this->table, [
                            [
                                $this->relatedPivotKey => $item,
                                $this->morphType => $this->related instanceof Model ? $this->related->getMorphClass() : null,
                            ],
                        ], true);
                    }
                } else {
                    foreach ($id as $item) {
                        $this->addIdToParentRelationData($item);
                    }
                }
            } else {
                // Attach the new parent id to the related model.
                $query->push($this->table, [
                    [
                        $this->foreignPivotKey => $this->parent->{$this->parentKey},
                        $this->morphType => $this->parent instanceof Model ? $this->parent->getMorphClass() : null,
                    ],
                ], true);

                // Attach the new ids to the parent model.
                if (\MongoDB\Laravel\Eloquent\Model::isDocumentModel($this->parent)) {
                    $this->parent->push($this->relatedPivotKey, $id, true);
                } else {
                    foreach ($id as $item) {
                        $this->addIdToParentRelationData($item);
                    }
                }
            }
        }

        if ($touch) {
            $this->touchIfTouching();
        }
    }

    /** @inheritdoc */
    public function detach($ids = [], $touch = true)
    {
        if ($ids instanceof Model) {
            $ids = $this->parseIds($ids);
        }

        $query = $this->newRelatedQuery();

        // If associated IDs were passed to the method we will only delete those
        // associations, otherwise all the association ties will be broken.
        // We'll return the numbers of affected rows when we do the deletes.
        $ids = (array) $ids;

        // Detach all ids from the parent model.
        if ($this->getInverse()) {
            // Remove the relation from the parent.
            $data = [];
            foreach ($ids as $item) {
                $data = [
                    ...$data,
                    [
                        $this->relatedPivotKey => $item,
                        $this->morphType => $this->related->getMorphClass(),
                    ],
                ];
            }

            if (\MongoDB\Laravel\Eloquent\Model::isDocumentModel($this->parent)) {
                $this->parent->pull($this->table, $data);
            } else {
                $value = $this->parent->{$this->relationName}
                    ->filter(fn ($rel) => ! in_array($rel->{$this->relatedKey}, $this->extractIds($data)));
                $this->parent->setRelation($this->relationName, $value);
            }

            // Prepare the query to select all related objects.
            if (count($ids) > 0) {
                $query->whereIn($this->relatedKey, $ids);
            }

            // Remove the relation from the related.
            $query->pull($this->foreignPivotKey, $this->parent->{$this->parentKey});
        } else {
            // Remove the relation from the parent.
            if (\MongoDB\Laravel\Eloquent\Model::isDocumentModel($this->parent)) {
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

            // Remove the relation to the related.
            $query->pull($this->table, [
                [
                    $this->foreignPivotKey => $this->parent->{$this->parentKey},
                    $this->morphType => $this->parent->getMorphClass(),
                ],
            ]);
        }

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
            if ($this->getInverse()) {
                foreach ($result->$foreign as $item) {
                    $dictionary[$item][] = $result;
                }
            } else {
                // Collect $foreign value from pivot column of result model
                $items = $this->extractIds($result->{$this->table} ?? [], $foreign);
                foreach ($items as $item) {
                    $dictionary[$item][] = $result;
                }
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
     * @return \Illuminate\Database\Query\Builder
     */
    public function newRelatedQuery()
    {
        return $this->related->newQuery();
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

    /**
     * Extract ids from given pivot table data
     *
     * @param array       $data
     * @param string|null $relatedPivotKey
     *
     * @return mixed
     */
    public function extractIds(array $data, ?string $relatedPivotKey = null)
    {
        $relatedPivotKey = $relatedPivotKey ?: $this->relatedPivotKey;
        return array_reduce($data, function ($carry, $item) use ($relatedPivotKey) {
            if (is_array($item) && array_key_exists($relatedPivotKey, $item)) {
                $carry[] = $item[$relatedPivotKey];
            }

            return $carry;
        }, []);
    }

    /**
     * Add the given id to the relation's data of the current parent instance.
     * It helps to keep up-to-date the sql model instances in hybrid relationships.
     *
     * @param ObjectId|string|int $id
     *
     * @return void
     */
    private function addIdToParentRelationData($id)
    {
        $instance = new $this->related();
        $instance->forceFill([$this->relatedKey => $id]);
        $relationData = $this->parent->{$this->relationName}->push($instance)->unique($this->relatedKey);
        $this->parent->setRelation($this->relationName, $relationData);
    }
}
