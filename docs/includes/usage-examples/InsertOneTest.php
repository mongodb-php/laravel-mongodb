<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Tests\TestCase;

class InsertOneTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInsertOne(): void
    {
        require_once __DIR__ . '/Movie.php';

        Movie::truncate();

        // begin-eloquent-insert-one
        $movie = Movie::create([
            'title' => 'Marriage Story',
            'year' => 2019,
            'runtime' => 136,
        ]);

        echo $movie->toJson();
        // end-eloquent-insert-one

        // begin-qb-insert-one
        $success = DB::table('movies')
            ->insert([
                'title' => 'Marriage Story',
                'year' => 2019,
                'runtime' => 136,
            ]);

        echo 'Insert operation success: ' . ($success ? 'yes' : 'no');
        // end-qb-insert-one

        $this->assertInstanceOf(Movie::class, $movie);
        $this->assertSame($movie->title, 'Marriage Story');
    }
}
