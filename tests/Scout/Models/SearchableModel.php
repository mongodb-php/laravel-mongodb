<?php

namespace MongoDB\Laravel\Tests\Scout\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class SearchableModel extends Model
{
    use Searchable;
    use SoftDeletes;

    protected $connection = 'sqlite';
    protected $fillable = ['id', 'name', 'date'];

    /** @see Searchable::searchableAs() */
    public function searchableAs(): string
    {
        return 'collection_searchable';
    }

    /** @see Searchable::indexableAs() */
    public function indexableAs(): string
    {
        return 'collection_indexable';
    }

    /**
     * Overriding the `getScoutKey` method to ensure the custom key is used for indexing
     * and searching the model.
     *
     * @see Searchable::getScoutKey()
     */
    public function getScoutKey(): string
    {
        return $this->getAttribute($this->getScoutKeyName()) ?: 'key_' . $this->getKey();
    }

    /**
     * This method must be overridden when the `getScoutKey` method is also overridden,
     * to support model serialization for async indexing jobs.
     *
     * @see Searchable::getScoutKeyName()
     */
    public function getScoutKeyName(): string
    {
        return 'scout_key';
    }
}
