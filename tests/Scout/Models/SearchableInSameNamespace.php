<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests\Scout\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;
use MongoDB\Laravel\Eloquent\DocumentModel;

class SearchableInSameNamespace extends Model
{
    use DocumentModel;
    use Searchable;

    protected $keyType = 'string';
    protected $connection = 'mongodb';
    protected $fillable = ['name'];

    /**
     * Using the same collection as the model collection as Scout index
     * is prohibited to prevent erasing the data.
     *
     * @see Searchable::searchableAs()
     */
    public function indexableAs(): string
    {
        return $this->getTable();
    }
}
