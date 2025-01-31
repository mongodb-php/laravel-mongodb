<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Tests\TestCase;

class FindManyTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFindMany(): void
    {
        require_once __DIR__ . '/Movie.php';

        Movie::truncate();
        Movie::insert([
            [
                'title' => 'Centennial',
                'runtime' => 1256,
            ],
            [
                'title' => 'Baseball',
                'runtime' => 1140,
            ],
            [
                'title' => 'Basketball',
                'runtime' => 600,
            ],
        ]);

        // begin-eloquent-find
        $movies = Movie::where('runtime', '>', 900)
            ->orderBy('_id')
            ->get();
        // end-eloquent-find

        $this->assertEquals(2, $movies->count());

        // begin-qb-find
        $movies = DB::table('movies')
            ->where('runtime', '>', 900)
            ->orderBy('_id')
            ->get();
        // end-qb-find

        $this->assertEquals(2, $movies->count());
    }
}
