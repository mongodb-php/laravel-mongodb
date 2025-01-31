<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Tests\TestCase;

class CountTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testCount(): void
    {
        require_once __DIR__ . '/Movie.php';

        Movie::truncate();
        Movie::insert([
            [
                'title' => 'Young Mr. Lincoln',
                'genres' => ['Biography', 'Drama'],
            ],
            [
                'title' => 'Million Dollar Mermaid',
                'genres' => ['Biography', 'Drama', 'Musical'],
            ],
        ]);

        // begin-eloquent-count
        $count = Movie::where('genres', 'Biography')
            ->count();

        echo 'Number of documents: ' . $count;
        // end-eloquent-count

        $this->assertEquals(2, $count);

        // begin-qb-count
        $count = DB::table('movies')
            ->where('genres', 'Biography')
            ->count();

        echo 'Number of documents: ' . $count;
        // end-qb-count

        $this->assertEquals(2, $count);
        $this->expectOutputString('Number of documents: 2Number of documents: 2');
    }
}
