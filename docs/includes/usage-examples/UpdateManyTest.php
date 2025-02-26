<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Tests\TestCase;

class UpdateManyTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testUpdateMany(): void
    {
        require_once __DIR__ . '/Movie.php';

        Movie::truncate();
        Movie::insert([
            [
                'title' => 'Hollywood',
                'imdb' => [
                    'rating' => 9.1,
                    'votes' => 511,
                ],
            ],
            [
                'title' => 'The Shawshank Redemption',
                'imdb' => [
                    'rating' => 9.3,
                    'votes' => 1513145,
                ],
            ],
        ]);

        // begin-eloquent-update-many
        $updates = Movie::where('imdb.rating', '>', 9.0)
            ->update(['acclaimed' => true]);

        echo 'Updated documents: ' . $updates;
        // end-eloquent-update-many

        $this->assertEquals(2, $updates);

        Movie::insert([
            [
                'title' => 'ABCD',
                'imdb' => [
                    'rating' => 9.5,
                    'votes' => 1,
                ],
            ],
            [
                'title' => 'Testing',
                'imdb' => [
                    'rating' => 9.3,
                    'votes' => 1,
                ],
            ],
        ]);

        // begin-qb-update-many
        $updates = DB::table('movies')
            ->where('imdb.rating', '>', 9.0)
            ->update(['acclaimed' => true]);

        echo 'Updated documents: ' . $updates;
        // end-qb-update-many

        $this->assertEquals(2, $updates);
        $this->expectOutputString('Updated documents: 2Updated documents: 2');
    }
}
