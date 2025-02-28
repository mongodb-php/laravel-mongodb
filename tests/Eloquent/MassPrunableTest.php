<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests\Eloquent;

use Illuminate\Database\Console\PruneCommand;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Prunable;
use MongoDB\Laravel\Tests\Models\Soft;
use MongoDB\Laravel\Tests\Models\User;
use MongoDB\Laravel\Tests\TestCase;

use function class_uses_recursive;
use function in_array;

class MassPrunableTest extends TestCase
{
    public function tearDown(): void
    {
        User::truncate();
        Soft::truncate();

        parent::tearDown();
    }

    public function testPruneWithQuery(): void
    {
        $this->assertTrue($this->isPrunable(User::class));

        User::insert([
            ['name' => 'John Doe', 'age' => 35],
            ['name' => 'Jane Doe', 'age' => 32],
            ['name' => 'Tomy Doe', 'age' => 11],
        ]);

        $model = new User();
        $total = $model->pruneAll();
        $this->assertEquals(2, $total);
        $this->assertEquals(1, User::count());
    }

    public function testPruneSoftDelete(): void
    {
        $this->assertTrue($this->isPrunable(Soft::class));

        Soft::insert([
            ['name' => 'John Doe'],
            ['name' => 'Jane Doe'],
        ]);

        $model = new Soft();
        $total = $model->pruneAll();
        $this->assertEquals(2, $total);
        $this->assertEquals(0, Soft::count());
        $this->assertEquals(0, Soft::withTrashed()->count());
    }

    /** @see PruneCommand::isPrunable() */
    protected function isPrunable($model)
    {
        $uses = class_uses_recursive($model);

        return in_array(Prunable::class, $uses) || in_array(MassPrunable::class, $uses);
    }
}
