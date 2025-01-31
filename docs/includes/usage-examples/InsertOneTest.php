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
    public function testEloquentInsertOne(): void
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

        $this->assertInstanceOf(Movie::class, $movie);
        $this->assertSame($movie->title, 'Marriage Story');
        $this->expectOutputRegex('/^{"title":"Marriage Story","year":2019,"runtime":136,"updated_at":".{27}","created_at":".{27}","_id":"[a-z0-9]{24}"}$/');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testQBInsertOne(): void
    {
        require_once __DIR__ . '/Movie.php';

        Movie::truncate();

        // begin-qb-insert-one
        $success = DB::table('movies')
            ->insert([
                'title' => 'Marriage Story',
                'year' => 2019,
                'runtime' => 136,
            ]);

        echo 'Insert operation success: ' . ($success ? 'yes' : 'no');
        // end-qb-insert-one

        $this->expectOutputString('Insert operation success: yes');
    }
}
