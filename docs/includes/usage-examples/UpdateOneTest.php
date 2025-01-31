<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Movie;
use MongoDB\Laravel\Tests\TestCase;

class UpdateOneTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testUpdateOne(): void
    {
        require_once __DIR__ . '/Movie.php';

        Movie::truncate();
        Movie::insert([
            [
                'title' => 'Carol',
                'imdb' => [
                    'rating' => 7.2,
                    'votes' => 125000,
                ],
            ],
        ]);

        // begin-eloquent-update-one
        $updates = Movie::where('title', 'Carol')
            ->orderBy('_id')
            ->first()
            ->update([
                'imdb' => [
                    'rating' => 7.3,
                    'votes' => 142000,
                ],
            ]);

        echo 'Updated documents: ' . $updates;
        // end-eloquent-update-one

        $this->assertTrue($updates);
        $this->expectOutputString('Updated documents: 1');
    }
}
