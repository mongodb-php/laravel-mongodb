<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests;

use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Tests\Models\Location;

class GeospatialTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Schema::table('locations', function ($collection) {
            $collection->geospatial('location', '2dsphere');
        });

        Location::create([
            'name' => 'Picadilly',
            'location' => [
                'type' => 'LineString',
                'coordinates' => [
                    [
                        -0.1450383,
                        51.5069158,
                    ],
                    [
                        -0.1367563,
                        51.5100913,
                    ],
                    [
                        -0.1304123,
                        51.5112908,
                    ],
                ],
            ],
        ]);

        Location::create([
            'name' => 'StJamesPalace',
            'location' => [
                'type' => 'Point',
                'coordinates' => [
                    -0.139827,
                    51.504736,
                ],
            ],
        ]);
    }

    public function tearDown(): void
    {
        Schema::drop('locations');

        parent::tearDown();
    }

    public function testGeoWithin()
    {
        $locations = Location::where('location', 'geoWithin', [
            '$geometry' => [
                'type' => 'Polygon',
                'coordinates' => [
                    [
                        [
                            -0.1450383,
                            51.5069158,
                        ],
                        [
                            -0.1367563,
                            51.5100913,
                        ],
                        [
                            -0.1270247,
                            51.5013233,
                        ],
                        [
                            -0.1460866,
                            51.4952136,
                        ],
                        [
                            -0.1450383,
                            51.5069158,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertEquals(1, $locations->count());

        $locations->get()->each(function ($item, $key) {
            $this->assertEquals('StJamesPalace', $item->name);
        });
    }

    public function testGeoIntersects()
    {
        $locations = Location::where('location', 'geoIntersects', [
            '$geometry' => [
                'type' => 'LineString',
                'coordinates' => [
                    [
                        -0.144044,
                        51.515215,
                    ],
                    [
                        -0.1367563,
                        51.5100913,
                    ],
                    [
                        -0.129545,
                        51.5078646,
                    ],
                ],
            ],
        ]);

        $this->assertEquals(1, $locations->count());

        $locations->get()->each(function ($item, $key) {
            $this->assertEquals('Picadilly', $item->name);
        });
    }

    public function testNear()
    {
        $locations = Location::where('location', 'near', [
            '$geometry' => [
                'type' => 'Point',
                'coordinates' => [
                    -0.1367563,
                    51.5100913,
                ],
            ],
            '$maxDistance' => 50,
        ]);

        $locations = $locations->get();

        $this->assertEquals(1, $locations->count());

        $locations->each(function ($item, $key) {
            $this->assertEquals('Picadilly', $item->name);
        });
    }
}
