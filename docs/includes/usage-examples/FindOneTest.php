<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Tests\TestCase;

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

        // begin-qb-find-one
        $movie = DB::table('movies')
          ->where('directors', 'Rob Reiner')
          ->orderBy('_id')
          ->first();

        echo $movie['title'];
        // end-qb-find-one

        $this->assertSame($movie['title'], 'The Shawshank Redemption');
        $this->expectOutputString('{"_id":"679cdb4834e26dc5370de462","title":"The Shawshank Redemption","directors":["Frank Darabont","Rob Reiner"]}The Shawshank Redemption');
    }
}
