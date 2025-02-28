<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests\Scout\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\SQLiteBuilder;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Searchable;
use MongoDB\Laravel\Eloquent\SoftDeletes;

use function assert;

class ScoutUser extends Model
{
    use Searchable;
    use SoftDeletes;

    protected $connection       = 'sqlite';
    protected $table            = 'scout_users';
    protected static $unguarded = true;

    /**
     * Create the SQL table for the model.
     */
    public static function executeSchema(): void
    {
        $schema = Schema::connection('sqlite');
        assert($schema instanceof SQLiteBuilder);

        $schema->dropIfExists('scout_users');
        $schema->create('scout_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->nullable();
            $table->date('email_verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
