<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Tests\TestCase;

class DeleteManyTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDeleteMany(): void
    {
        require_once __DIR__ . '/Movie.php';

        Movie::truncate();
        Movie::insert([
            [
                'title' => 'Train Pulling into a Station',
                'year' => 1896,
            ],
            [
                'title' => 'The Ball Game',
                'year' => 1898,
            ],
        ]);

        // begin-eloquent-delete-many
        $deleted = Movie::where('year', '<=', 1910)
            ->delete();

        echo 'Deleted documents: ' . $deleted;
        // end-eloquent-delete-many

        $this->assertEquals(2, $deleted);

        Movie::insert([
            [
                'title' => 'Train Pulling into a Station',
                'year' => 1896,
            ],
            [
                'title' => 'The Ball Game',
                'year' => 1898,
            ],
        ]);

        // begin-qb-delete-many
        $deleted = DB::table('movies')
            ->where('year', '<=', 1910)
            ->delete();

        echo 'Deleted documents: ' . $deleted;
        // end-qb-delete-many

        $this->assertEquals(2, $deleted);
        $this->expectOutputString('Deleted documents: 2Deleted documents: 2');
    }
}
