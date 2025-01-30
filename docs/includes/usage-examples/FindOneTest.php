<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Tests\TestCase;

use function print_r;

class FindOneTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFindOne(): void
    {
        require_once __DIR__ . '/Movie.php';

        Movie::truncate();
        Movie::insert([
            ['title' => 'The Shawshank Redemption', 'directors' => ['Frank Darabont', 'Rob Reiner']],
        ]);

        // begin-eloquent-find-one
        $movie = Movie::where('directors', 'Rob Reiner')
          ->orderBy('_id')
          ->first();

        echo $movie->toJson();
        // end-eloquent-find-one

        $this->assertInstanceOf(Movie::class, $movie);
        $this->assertSame($movie->title, 'The Shawshank Redemption');

        // begin-qb-find-one
        $movie = DB::table('movies')
          ->where('directors', 'Rob Reiner')
          ->orderBy('_id')
          ->first();

        echo print_r($movie);
        // end-qb-find-one
    }
}
