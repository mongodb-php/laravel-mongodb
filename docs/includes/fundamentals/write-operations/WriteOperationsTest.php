<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Concert;
use Carbon\Carbon;
use MongoDB\Laravel\Tests\TestCase;

use function count;

class WriteOperationsTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testModelInsert(): void
    {
        // <optionally, add code here to clean the database/collection>

        require_once __DIR__ . '/Concert.php';

        Concert::truncate();

        // begin model insert one
        $concert = new Concert();
        $concert->performer = 'Mitsuko Uchida';
        $concert->venue = 'Carnegie Hall';
        $concert->performanceDate = Carbon::create(2024, 4, 1, 20, 0, 0, 'EST');
        $concert->save();
        // end model insert one

        // begin inserted id
        $insertedId = $concert->id;
        // end inserted id


        print_r($insertedId);
        $this->assertNotNull($concert);

        $result = Concert::first();
        $this->assertInstanceOf(Concert::class, $result);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testModelInsertMassAssign(): void
    {
        // <optionally, add code here to clean the database/collection>

        require_once __DIR__ . '/Concert.php';

        Concert::truncate();

        // begin model insert one mass assign
        $insertResult = Concert::create([
            'performer' => 'The Rolling Stones',
            'venue' => 'Soldier Field',
            'performanceDate' => Carbon::create(2024, 6, 30, 20, 0, 0, 'CDT'),
        ]);
        // end model insert one mass assign

        $this->assertNotNull($insertResult);

        $result = Concert::first();
        $this->assertInstanceOf(Concert::class, $result);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testModelInsertMany(): void
    {
        // <optionally, add code here to clean the database/collection>

        require_once __DIR__ . '/Concert.php';

        Concert::truncate();

        // begin model insert many
        $data = [
            [
                'performer' => 'Brad Mehldau',
                'venue' => 'Philharmonie de Paris',
                'performanceDate' => Carbon::create(2025, 2, 12, 20, 0, 0, 'CET'),
            ],
            [
                'performer' => 'Billy Joel',
                'venue' => 'Madison Square Garden',
                'performanceDate' => Carbon::create(2024, 7, 25, 19, 0, 0, 'EST'),
            ],
        ];

        Concert::insert($data);
        // end model insert many

        $results = Concert::get();

        $this->assertEquals(2, count($results));
    }
}
