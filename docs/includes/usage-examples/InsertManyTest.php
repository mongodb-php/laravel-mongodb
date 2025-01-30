<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Movie;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Laravel\Tests\TestCase;

class InsertManyTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInsertMany(): void
    {
        require_once __DIR__ . '/Movie.php';

        Movie::truncate();

        // begin-eloquent-insert-many
        $success = Movie::insert([
            [
                'title' => 'Anatomy of a Fall',
                'release_date' => new UTCDateTime(new DateTimeImmutable('2023-08-23')),
            ],
            [
                'title' => 'The Boy and the Heron',
                'release_date' => new UTCDateTime(new DateTimeImmutable('2023-12-08')),
            ],
            [
                'title' => 'Passages',
                'release_date' => new UTCDateTime(new DateTimeImmutable('2023-06-28')),
            ],
        ]);

        echo 'Insert operation success: ' . ($success ? 'yes' : 'no');
        // end-eloquent-insert-many

        // begin-qb-insert-many
        $success = DB::table('movies')
            ->insert([
                [
                    'title' => 'Anatomy of a Fall',
                    'release_date' => new UTCDateTime(new DateTimeImmutable('2023-08-23')),
                ],
                [
                    'title' => 'The Boy and the Heron',
                    'release_date' => new UTCDateTime(new DateTimeImmutable('2023-12-08')),
                ],
                [
                    'title' => 'Passages',
                    'release_date' => new UTCDateTime(new DateTimeImmutable('2023-06-28')),
                ],
            ]);

        echo 'Insert operation success: ' . ($success ? 'yes' : 'no');
        // end-qb-insert-many

        $this->assertTrue($success);
        $this->expectOutputString('Insert operation success: yes');
    }
}
