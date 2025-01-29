<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use MongoDB\Builder\Query;
use MongoDB\Builder\Search;
use MongoDB\Laravel\Tests\TestCase;

use function array_map;
use function mt_getrandmax;
use function rand;
use function range;
use function srand;

class AtlasSearchTest extends TestCase
{
    private array $vectors;

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

        Movie::insert($this->addVector([
            ['title' => 'A', 'plot' => 'A shy teenager discovers confidence and new friendships during a transformative summer camp experience.'],
            ['title' => 'B', 'plot' => 'A detective teams up with a hacker to unravel a global conspiracy threatening personal freedoms.'],
            ['title' => 'C', 'plot' => 'High school friends navigate love, identity, and unexpected challenges before graduating together.'],
            ['title' => 'D', 'plot' => 'Stranded on a distant planet, astronauts must repair their ship before supplies run out.'],
        ]));
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

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function vectorSearchTest(): void
    {
        $results = Book::vectorSearch(
            index: 'vector',
            path: 'vector4',
            queryVector: $this->vectors[0],
            limit: 3,
            numCandidates: 10,
            filter: Query::query(
                title: Query::ne('A'),
            ),
        );

        $this->assertNotNull($results);
        $this->assertSame('C', $results->first()->title);
    }

    /** Generate random vectors using fixed seed to make tests deterministic */
    private function addVector(array $items): array
    {
        srand(1);
        foreach ($items as &$item) {
            $this->vectors[] = $item['vector4'] = array_map(fn () => rand() / mt_getrandmax(), range(0, 3));
        }

        return $items;
    }
}
