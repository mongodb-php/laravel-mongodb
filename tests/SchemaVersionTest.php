<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests;

use Illuminate\Support\Facades\DB;
use LogicException;
use MongoDB\Laravel\Eloquent\HasSchemaVersion;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Tests\Models\SchemaVersion;

class SchemaVersionTest extends TestCase
{
    public function tearDown(): void
    {
        SchemaVersion::truncate();

        parent::tearDown();
    }

    public function testWithBasicDocument()
    {
        $document = new SchemaVersion(['name' => 'Luc']);
        $this->assertEmpty($document->getSchemaVersion());
        $document->save();

        // The current schema version of the model is stored by default
        $this->assertEquals(2, $document->getSchemaVersion());

        // Test automatic migration
        SchemaVersion::insert([
            ['name' => 'Vador', 'schema_version' => 1],
        ]);
        $document = SchemaVersion::where('name', 'Vador')->first();
        $this->assertEquals(2, $document->getSchemaVersion());
        $this->assertEquals(35, $document->age);

        $document->save();

        // The migrated version is saved
        $data = DB::connection('mongodb')
            ->table('documentVersion')
            ->where('name', 'Vador')
            ->get();

        $this->assertEquals(2, $data[0]->schema_version);
    }

    public function testIncompleteImplementation(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('::SCHEMA_VERSION is required when using HasSchemaVersion');
        $document = new class extends Model {
            use HasSchemaVersion;
        };

        $document->save();
    }
}
