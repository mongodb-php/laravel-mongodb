<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Tests\TestCase;

class DeleteOneTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDeleteOne(): void
    {
        require_once __DIR__ . '/Movie.php';

        Movie::truncate();
        Movie::insert([
            [
                'title' => 'Quiz Show',
                'runtime' => 133,
            ],
        ]);

        // begin-eloquent-delete-one
        $deleted = Movie::where('title', 'Quiz Show')
            ->orderBy('_id')
            ->limit(1)
            ->delete();

        echo 'Deleted documents: ' . $deleted;
        // end-eloquent-delete-one

        // begin-qb-delete-one
        $deleted = DB::table('movies')
            ->where('title', 'Quiz Show')
            ->orderBy('_id')
            ->limit(1)
            ->delete();

        echo 'Deleted documents: ' . $deleted;
        // end-qb-delete-one

        $this->assertEquals(1, $deleted);
        $this->expectOutputString('Deleted documents: 1');
    }
}
