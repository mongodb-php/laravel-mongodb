<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasRelationships;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;
use MongoDB\Laravel\Helpers\EloquentBuilder;
use MongoDB\Laravel\Relations\BelongsTo;
use MongoDB\Laravel\Relations\BelongsToMany;
use MongoDB\Laravel\Relations\HasMany;
use MongoDB\Laravel\Relations\HasOne;
use MongoDB\Laravel\Relations\MorphMany;
use MongoDB\Laravel\Relations\MorphTo;
use MongoDB\Laravel\Relations\MorphToMany;

use function array_pop;
use function debug_backtrace;
use function implode;
use function preg_split;

use const DEBUG_BACKTRACE_IGNORE_ARGS;
use const PREG_SPLIT_DELIM_CAPTURE;

/**
 * Cross-database relationships between SQL and MongoDB.
 * Use this trait in SQL models to define relationships with MongoDB models.
 */
trait HybridRelations
{
    /**
     * Define a one-to-one relationship.
     *
     * @see HasRelationships::hasOne()
     *
     * @param class-string $related
     * @param string|null  $foreignKey
     * @param string|null  $localKey
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function hasOne($related, $foreignKey = null, $localKey = null)
    {
        // Check if it is a relation with an original model.
        if (! Model::isDocumentModel($related)) {
            return parent::hasOne($related, $foreignKey, $localKey);
        }

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $instance = new $related();

        $localKey = $localKey ?: $this->getKeyName();

        return new HasOne($instance->newQuery(), $this, $foreignKey, $localKey);
    }

    /**
     * Define a polymorphic one-to-one relationship.
     *
     * @see HasRelationships::morphOne()
     *
     * @param class-string $related
     * @param string       $name
     * @param string|null  $type
     * @param string|null  $id
     * @param string|null  $localKey
     *
     * @return MorphOne
     */
    public function morphOne($related, $name, $type = null, $id = null, $localKey = null)
    {
        // Check if it is a relation with an original model.
        if (! Model::isDocumentModel($related)) {
            return parent::morphOne($related, $name, $type, $id, $localKey);
        }

        $instance = new $related();

        [$type, $id] = $this->getMorphs($name, $type, $id);

        $localKey = $localKey ?: $this->getKeyName();

        return new MorphOne($instance->newQuery(), $this, $type, $id, $localKey);
    }

    /**
     * Define a one-to-many relationship.
     *
     * @see HasRelationships::hasMany()
     *
     * @param class-string $related
     * @param string|null  $foreignKey
     * @param string|null  $localKey
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function hasMany($related, $foreignKey = null, $localKey = null)
    {
        // Check if it is a relation with an original model.
        if (! Model::isDocumentModel($related)) {
            return parent::hasMany($related, $foreignKey, $localKey);
        }

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $instance = new $related();

        $localKey = $localKey ?: $this->getKeyName();

        return new HasMany($instance->newQuery(), $this, $foreignKey, $localKey);
    }

    /**
     * Define a polymorphic one-to-many relationship.
     *
     * @see HasRelationships::morphMany()
     *
     * @param class-string $related
     * @param string       $name
     * @param string|null  $type
     * @param string|null  $id
     * @param string|null  $localKey
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function morphMany($related, $name, $type = null, $id = null, $localKey = null)
    {
        // Check if it is a relation with an original model.
        if (! Model::isDocumentModel($related)) {
            return parent::morphMany($related, $name, $type, $id, $localKey);
        }

        $instance = new $related();

        // Here we will gather up the morph type and ID for the relationship so that we
        // can properly query the intermediate table of a relation. Finally, we will
        // get the table and create the relationship instances for the developers.
        [$type, $id] = $this->getMorphs($name, $type, $id);

        $table = $instance->getTable();

        $localKey = $localKey ?: $this->getKeyName();

        return new MorphMany($instance->newQuery(), $this, $type, $id, $localKey);
    }

    /**
     * Define an inverse one-to-one or many relationship.
     *
     * @see HasRelationships::belongsTo()
     *
     * @param class-string $related
     * @param string|null  $foreignKey
     * @param string|null  $ownerKey
     * @param string|null  $relation
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function belongsTo($related, $foreignKey = null, $ownerKey = null, $relation = null)
    {
        // If no relation name was given, we will use this debug backtrace to extract
        // the calling method's name and use that as the relationship name as most
        // of the time this will be what we desire to use for the relationships.
        if ($relation === null) {
            $relation = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];
        }

        // Check if it is a relation with an original model.
        if (! Model::isDocumentModel($related)) {
            return parent::belongsTo($related, $foreignKey, $ownerKey, $relation);
        }

        // If no foreign key was supplied, we can use a backtrace to guess the proper
        // foreign key name by using the name of the relationship function, which
        // when combined with an "_id" should conventionally match the columns.
        if ($foreignKey === null) {
            $foreignKey = Str::snake($relation) . '_id';
        }

        $instance = new $related();

        // Once we have the foreign key names, we'll just create a new Eloquent query
        // for the related models and returns the relationship instance which will
        // actually be responsible for retrieving and hydrating every relations.
        $query = $instance->newQuery();

        $ownerKey = $ownerKey ?: $instance->getKeyName();

        return new BelongsTo($query, $this, $foreignKey, $ownerKey, $relation);
    }

    /**
     * Define a polymorphic, inverse one-to-one or many relationship.
     *
     * @see HasRelationships::morphTo()
     *
     * @param string      $name
     * @param string|null $type
     * @param string|null $id
     * @param string|null $ownerKey
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function morphTo($name = null, $type = null, $id = null, $ownerKey = null)
    {
        // If no name is provided, we will use the backtrace to get the function name
        // since that is most likely the name of the polymorphic interface. We can
        // use that to get both the class and foreign key that will be utilized.
        if ($name === null) {
            $name = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];
        }

        [$type, $id] = $this->getMorphs(Str::snake($name), $type, $id);

        // If the type value is null it is probably safe to assume we're eager loading
        // the relationship. When that is the case we will pass in a dummy query as
        // there are multiple types in the morph and we can't use single queries.
        $class = $this->$type;
        if ($class === null) {
            return new MorphTo(
                $this->newQuery(),
                $this,
                $id,
                $ownerKey,
                $type,
                $name,
            );
        }

        // If we are not eager loading the relationship we will essentially treat this
        // as a belongs-to style relationship since morph-to extends that class and
        // we will pass in the appropriate values so that it behaves as expected.
        $class = $this->getActualClassNameForMorph($class);

        $instance = new $class();

        $ownerKey ??= $instance->getKeyName();

        // Check if it is a relation with an original model.
        if (! Model::isDocumentModel($instance)) {
            return parent::morphTo($name, $type, $id, $ownerKey);
        }

        return new MorphTo(
            $instance->newQuery(),
            $this,
            $id,
            $ownerKey,
            $type,
            $name,
        );
    }

    /**
     * Define a many-to-many relationship.
     *
     * @see HasRelationships::belongsToMany()
     *
     * @param class-string $related
     * @param string|null  $collection
     * @param string|null  $foreignPivotKey
     * @param string|null  $relatedPivotKey
     * @param string|null  $parentKey
     * @param string|null  $relatedKey
     * @param string|null  $relation
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function belongsToMany(
        $related,
        $collection = null,
        $foreignPivotKey = null,
        $relatedPivotKey = null,
        $parentKey = null,
        $relatedKey = null,
        $relation = null,
    ) {
        // If no relationship name was passed, we will pull backtraces to get the
        // name of the calling function. We will use that function name as the
        // title of this relation since that is a great convention to apply.
        if ($relation === null) {
            $relation = $this->guessBelongsToManyRelation();
        }

        // Check if it is a relation with an original model.
        if (! Model::isDocumentModel($related)) {
            return parent::belongsToMany(
                $related,
                $collection,
                $foreignPivotKey,
                $relatedPivotKey,
                $parentKey,
                $relatedKey,
                $relation,
            );
        }

        // First, we'll need to determine the foreign key and "other key" for the
        // relationship. Once we have determined the keys we'll make the query
        // instances as well as the relationship instances we need for this.
        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey() . 's';

        $instance = new $related();

        $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey() . 's';

        // If no table name was provided, we can guess it by concatenating the two
        // models using underscores in alphabetical order. The two model names
        // are transformed to snake case from their default CamelCase also.
        if ($collection === null) {
            $collection = $instance->getTable();
        }

        // Now we're ready to create a new query builder for the related model and
        // the relationship instances for the relation. The relations will set
        // appropriate query constraint and entirely manages the hydrations.
        $query = $instance->newQuery();

        return new BelongsToMany(
            $query,
            $this,
            $collection,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey ?: $this->getKeyName(),
            $relatedKey ?: $instance->getKeyName(),
            $relation,
        );
    }

    /**
     * Define a morph-to-many relationship.
     *
     * @param class-string $related
     * @param string       $name
     * @param string|null  $table
     * @param string|null  $foreignPivotKey
     * @param string|null  $relatedPivotKey
     * @param string|null  $parentKey
     * @param string|null  $relatedKey
     * @param string|null  $relation
     * @param bool         $inverse
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function morphToMany(
        $related,
        $name,
        $table = null,
        $foreignPivotKey = null,
        $relatedPivotKey = null,
        $parentKey = null,
        $relatedKey = null,
        $relation = null,
        $inverse = false,
    ) {
        // If no relationship name was passed, we will pull backtraces to get the
        // name of the calling function. We will use that function name as the
        // title of this relation since that is a great convention to apply.
        if ($relation === null) {
            $relation = $this->guessBelongsToManyRelation();
        }

        // Check if it is a relation with an original model.
        if (! Model::isDocumentModel($related)) {
            return parent::morphToMany(
                $related,
                $name,
                $table,
                $foreignPivotKey,
                $relatedPivotKey,
                $parentKey,
                $relatedKey,
                $relation,
                $inverse,
            );
        }

        $instance = new $related();

        $foreignPivotKey = $foreignPivotKey ?: $name . '_id';
        $relatedPivotKey = $relatedPivotKey ?:  Str::plural($instance->getForeignKey());

        // Now we're ready to create a new query builder for the related model and
        // the relationship instances for this relation. This relation will set
        // appropriate query constraints then entirely manage the hydration.
        if (! $table) {
            $words = preg_split('/(_)/u', $name, -1, PREG_SPLIT_DELIM_CAPTURE);
            $lastWord = array_pop($words);
            $table = implode('', $words) . Str::plural($lastWord);
        }

        return new MorphToMany(
            $instance->newQuery(),
            $this,
            $name,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey ?: $this->getKeyName(),
            $relatedKey ?: $instance->getKeyName(),
            $relation,
            $inverse,
        );
    }

    /**
     * Define a polymorphic, inverse many-to-many relationship.
     *
     * @param class-string $related
     * @param string       $name
     * @param string|null  $table
     * @param string|null  $foreignPivotKey
     * @param string|null  $relatedPivotKey
     * @param string|null  $parentKey
     * @param string|null  $relatedKey
     * @param string|null  $relation
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function morphedByMany(
        $related,
        $name,
        $table = null,
        $foreignPivotKey = null,
        $relatedPivotKey = null,
        $parentKey = null,
        $relatedKey = null,
        $relation = null,
    ) {
        // If the related model is an instance of eloquent model class, leave pivot keys
        // as default. It's necessary for supporting hybrid relationship
        if (Model::isDocumentModel($related)) {
            // For the inverse of the polymorphic many-to-many relations, we will change
            // the way we determine the foreign and other keys, as it is the opposite
            // of the morph-to-many method since we're figuring out these inverses.
            $foreignPivotKey = $foreignPivotKey ?: Str::plural($this->getForeignKey());

            $relatedPivotKey = $relatedPivotKey ?: $name . '_id';
        }

        return $this->morphToMany(
            $related,
            $name,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relatedKey,
            true,
        );
    }

    /** @inheritdoc */
    public function newEloquentBuilder($query)
    {
        if (Model::isDocumentModel($this)) {
            return new Builder($query);
        }

        return new EloquentBuilder($query);
    }
}
