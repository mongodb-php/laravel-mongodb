<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Movie;
use MongoDB\Laravel\Tests\TestCase;

class DistinctTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDistinct(): void
    {
        require_once __DIR__ . '/Movie.php';

        Movie::truncate();
        Movie::insert([
            [
                'title' => 'Marie Antoinette',
                'directors' => ['Sofia Coppola'],
                'imdb' => [
                    'rating' => 6.4,
                    'votes' => 74350,
                ],
            ],
            [
                'title' => 'Somewhere',
                'directors' => ['Sofia Coppola'],
                'imdb' => [
                    'rating' => 6.4,
                    'votes' => 33753,
                ],
            ],
            [
                'title' => 'Lost in Translation',
                'directors' => ['Sofia Coppola'],
                'imdb' => [
                    'rating' => 7.8,
                    'votes' => 298747,
                ],
            ],
        ]);

        // begin-distinct
        $ratings = Movie::where('directors', 'Sofia Coppola')
            ->select('imdb.rating')
            ->distinct()
            ->get();

        echo $ratings;
        // end-distinct

        $this->expectOutputString('[[6.4],[7.8]]');
    }
}
