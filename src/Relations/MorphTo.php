<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo as EloquentMorphTo;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 * @extends EloquentMorphTo<TRelatedModel, TDeclaringModel>
 */
class MorphTo extends EloquentMorphTo
{
    /** @inheritdoc */
    public function addConstraints()
    {
        if (static::$constraints) {
            // For belongs to relationships, which are essentially the inverse of has one
            // or has many relationships, we need to actually query on the primary key
            // of the related models matching on the foreign key that's on a parent.
            $this->query->where(
                $this->ownerKey ?? $this->getForeignKeyName(),
                '=',
                $this->getForeignKeyFrom($this->parent),
            );
        }
    }

    /** @inheritdoc */
    protected function getResultsByType($type)
    {
        $instance = $this->createModelByType($type);

        $key = $this->ownerKey ?? $instance->getKeyName();

        $query = $instance->newQuery();

        return $query->whereIn($key, $this->gatherKeysByType($type, $instance->getKeyType()))->get();
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
