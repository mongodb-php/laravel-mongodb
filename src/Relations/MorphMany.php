<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 * @extends EloquentMorphMany<TRelatedModel, TDeclaringModel>
 */
class MorphMany extends EloquentMorphMany
{
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
