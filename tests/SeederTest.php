<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use MongoDB\Laravel\Tests\Models\User;
use MongoDB\Laravel\Tests\Seeder\DatabaseSeeder;
use MongoDB\Laravel\Tests\Seeder\UserTableSeeder;

class SeederTest extends TestCase
{
    public function tearDown(): void
    {
        User::truncate();

        parent::tearDown();
    }

    public function testSeed(): void
    {
        $seeder = new UserTableSeeder();
        $seeder->run();

        $user = User::where('name', 'John Doe')->first();
        $this->assertTrue($user->seed);
    }

    public function testArtisan(): void
    {
        Artisan::call('db:seed', ['class' => DatabaseSeeder::class]);

        $user = User::where('name', 'John Doe')->first();
        $this->assertTrue($user->seed);
    }
}
