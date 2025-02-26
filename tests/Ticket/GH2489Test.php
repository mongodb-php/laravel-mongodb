<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests\Ticket;

use MongoDB\Laravel\Tests\Models\Location;
use MongoDB\Laravel\Tests\TestCase;

/** @see https://github.com/mongodb/laravel-mongodb/issues/2783 */
class GH2489Test extends TestCase
{
    public function tearDown(): void
    {
        Location::truncate();

        parent::tearDown();
    }

    public function testQuerySubdocumentsUsingWhereInId()
    {
        Location::insert([
            [
                'name' => 'Location 1',
                'images' => [
                    ['_id' => 1, 'uri' => 'image1.jpg'],
                    ['_id' => 2, 'uri' => 'image2.jpg'],
                ],
            ],
            [
                'name' => 'Location 2',
                'images' => [
                    ['_id' => 3, 'uri' => 'image3.jpg'],
                    ['_id' => 4, 'uri' => 'image4.jpg'],
                ],
            ],
        ]);

        // With _id
        $results = Location::whereIn('images._id', [1])->get();

        $this->assertCount(1, $results);
        $this->assertSame('Location 1', $results->first()->name);

        // With id
        $results = Location::whereIn('images.id', [1])->get();

        $this->assertCount(1, $results);
        $this->assertSame('Location 1', $results->first()->name);
    }
}
