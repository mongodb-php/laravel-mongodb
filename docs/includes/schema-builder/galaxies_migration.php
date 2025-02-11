<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        // begin-create-search-indexes
        Schema::create('galaxies', function (Blueprint $collection) {
            $collection->searchIndex([
                'mappings' => [
                    'dynamic' => true,
                ],
            ], 'dynamic_index');
            $collection->searchIndex([
                'mappings' => [
                    'fields' => [
                        'name' => [
                            ['type' => 'string', 'analyzer' => 'lucene.english'],
                            ['type' => 'autocomplete', 'analyzer' => 'lucene.english'],
                            ['type' => 'token'],
                        ],
                    ],
                ],
            ], 'auto_index');
        });
        // end-create-search-indexes

        // start-create-vs-index
        Schema::create('galaxies', function (Blueprint $collection) {
            $collection->vectorSearchIndex([
                'fields' => [
                    [
                        'type' => 'vector',
                        'numDimensions' => 4,
                        'path' => 'vector4',
                        'similarity' => 'cosine',
                    ],
                ],
            ], 'vs_index');
        });
        // end-create-vs-index
    }

    public function down(): void
    {
        // begin-drop-search-index
        Schema::table('galaxies', function (Blueprint $collection) {
            $collection->dropSearchIndex('auto_index');
        });
        // end-drop-search-index
    }
};
