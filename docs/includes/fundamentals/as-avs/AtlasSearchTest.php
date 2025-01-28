<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Tests\TestCase;
use MongoDB\Builder\Search;

class AtlasSearchTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/Movie.php';

        parent::setUp();

        $moviesCollection = DB::connection('mongodb')->getCollection('movies');
        $moviesCollection->drop();
        $moviesCollection->createSearchIndex([
            'mappings' => ['dynamic' => true],
        ], ['name' => 'simple_search']);

        Movie::insert([
            ['title' => 'Dreaming of Jakarta', 'year' => 1990],
            ['title' => 'See You in My Dreams', 'year' => 1996],
            ['title' => 'On the Run', 'year' => 2004],
            ['title' => 'Jakob the Liar', 'year' => 1999],
            ['title' => 'Emily Calling Jake', 'year' => 2001],
        ]);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSimpleSearch(): void
    {
        // start-search-query
        $movies = Movie::search(
            sort: ['title' => 1],
            operator: Search::text('title', 'dream'),
        )->get();
        // end-search-query

        $this->assertNotNull($movies);
        $this->assertCount(2, $movies);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function autocompleteSearchTest(): void
    {
        // start-auto-query
        $movies = Movie::autocomplete('title', 'jak')
            ->get();
        // end-auto-query

        $this->assertNotNull($movies);
        $this->assertCount(3, $movies);
    }
}
