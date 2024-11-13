<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests;

use Carbon\Carbon;
use DateTime;
use Generator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\Binary;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Laravel\Connection;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Tests\Models\Book;
use MongoDB\Laravel\Tests\Models\Guarded;
use MongoDB\Laravel\Tests\Models\IdIsBinaryUuid;
use MongoDB\Laravel\Tests\Models\IdIsInt;
use MongoDB\Laravel\Tests\Models\IdIsString;
use MongoDB\Laravel\Tests\Models\Item;
use MongoDB\Laravel\Tests\Models\MemberStatus;
use MongoDB\Laravel\Tests\Models\Soft;
use MongoDB\Laravel\Tests\Models\SqlUser;
use MongoDB\Laravel\Tests\Models\User;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;

use function abs;
use function array_keys;
use function array_merge;
use function date_default_timezone_set;
use function get_debug_type;
use function hex2bin;
use function sleep;
use function sort;
use function strlen;
use function time;

use const DATE_ATOM;

class ModelTest extends TestCase
{
    public function tearDown(): void
    {
        Carbon::setTestNow();
        DB::connection('mongodb')->getCollection('users')->drop();
        Soft::truncate();
        Book::truncate();
        Item::truncate();
        Guarded::truncate();
    }

    public function testNewModel(): void
    {
        $user = new User();
        $this->assertTrue(Model::isDocumentModel($user));
        $this->assertInstanceOf(Connection::class, $user->getConnection());
        $this->assertFalse($user->exists);
        $this->assertEquals('users', $user->getTable());
        $this->assertEquals('id', $user->getKeyName());
    }

    public function testQualifyColumn(): void
    {
        // Don't qualify field names in document models
        $user = new User();
        $this->assertEquals('name', $user->qualifyColumn('name'));

        // Qualify column names in hybrid SQL models
        $sqlUser = new SqlUser();
        $this->assertEquals('users.name', $sqlUser->qualifyColumn('name'));
    }

    public function testInsert(): void
    {
        $user        = new User();
        $user->name  = 'John Doe';
        $user->title = 'admin';
        $user->age   = 35;

        $user->save();

        $this->assertTrue($user->exists);
        $this->assertEquals(1, User::count());

        $this->assertTrue(isset($user->id));
        $this->assertIsString($user->id);
        $this->assertNotEquals('', (string) $user->id);
        $this->assertNotEquals(0, strlen((string) $user->id));
        $this->assertInstanceOf(Carbon::class, $user->created_at);

        $raw = $user->getAttributes();
        $this->assertInstanceOf(ObjectID::class, $raw['id']);

        $this->assertEquals('John Doe', $user->name);
        $this->assertEquals(35, $user->age);
    }

    public function testUpdate(): void
    {
        $user        = new User();
        $user->name  = 'John Doe';
        $user->title = 'admin';
        $user->age   = 35;
        $user->save();

        $raw = $user->getAttributes();
        $this->assertInstanceOf(ObjectID::class, $raw['id']);

        $check = User::find($user->id);
        $this->assertInstanceOf(User::class, $check);
        $check->age = 36;
        $check->save();

        $this->assertTrue($check->exists);
        $this->assertInstanceOf(Carbon::class, $check->created_at);
        $this->assertInstanceOf(Carbon::class, $check->updated_at);
        $this->assertEquals(1, User::count());

        $this->assertEquals('John Doe', $check->name);
        $this->assertEquals(36, $check->age);

        $user->update(['age' => 20]);

        $raw = $user->getAttributes();
        $this->assertInstanceOf(ObjectID::class, $raw['id']);

        $check = User::find($user->id);
        $this->assertEquals(20, $check->age);

        $check->age      = 24;
        $check->fullname = 'Hans Thomas'; // new field
        $check->save();

        $check = User::find($user->id);
        $this->assertEquals(24, $check->age);
        $this->assertEquals('Hans Thomas', $check->fullname);
    }

    public function testUpsert()
    {
        $result = User::upsert([
            ['email' => 'foo', 'name' => 'bar'],
            ['name' => 'bar2', 'email' => 'foo2'],
        ], ['email']);

        $this->assertSame(2, $result);

        $this->assertSame(2, $result);
        $this->assertSame(2, User::count());
        $this->assertSame('bar', User::where('email', 'foo')->first()->name);

        // Update 1 document
        $result = User::upsert([
            ['email' => 'foo', 'name' => 'bar2'],
            ['name' => 'bar2', 'email' => 'foo2'],
        ], 'email', ['name']);

        // Even if the same value is set for the 2nd document, the "updated_at" field is updated
        $this->assertSame(2, $result);
        $this->assertSame(2, User::count());
        $this->assertSame('bar2', User::where('email', 'foo')->first()->name);

        // If no update fields are specified, all fields are updated
        // Test single document update
        $result = User::upsert(['email' => 'foo', 'name' => 'bar3'], 'email');

        $this->assertSame(1, $result);
        $this->assertSame(2, User::count());
        $this->assertSame('bar3', User::where('email', 'foo')->first()->name);
    }

    public function testManualStringId(): void
    {
        $user        = new User();
        $user->id   = '4af9f23d8ead0e1d32000000';
        $user->name  = 'John Doe';
        $user->title = 'admin';
        $user->age   = 35;
        $user->save();

        $this->assertTrue($user->exists);
        $this->assertEquals('4af9f23d8ead0e1d32000000', $user->id);

        $raw = $user->getAttributes();
        $this->assertInstanceOf(ObjectID::class, $raw['id']);

        $user        = new User();
        $user->id   = 'customId';
        $user->name  = 'John Doe';
        $user->title = 'admin';
        $user->age   = 35;
        $user->save();

        $this->assertTrue($user->exists);
        $this->assertEquals('customId', $user->id);

        $raw = $user->getAttributes();
        $this->assertIsString($raw['id']);
    }

    public function testManualIntId(): void
    {
        $user        = new User();
        $user->id   = 1;
        $user->name  = 'John Doe';
        $user->title = 'admin';
        $user->age   = 35;
        $user->save();

        $this->assertTrue($user->exists);
        $this->assertEquals(1, $user->id);

        $raw = $user->getAttributes();
        $this->assertIsInt($raw['id']);
    }

    public function testDelete(): void
    {
        $user        = new User();
        $user->name  = 'John Doe';
        $user->title = 'admin';
        $user->age   = 35;
        $user->save();

        $this->assertTrue($user->exists);
        $this->assertEquals(1, User::count());

        $user->delete();

        $this->assertEquals(0, User::count());
    }

    public function testAll(): void
    {
        $user        = new User();
        $user->name  = 'John Doe';
        $user->title = 'admin';
        $user->age   = 35;
        $user->save();

        $user        = new User();
        $user->name  = 'Jane Doe';
        $user->title = 'user';
        $user->age   = 32;
        $user->save();

        $all = User::all();

        $this->assertCount(2, $all);
        $this->assertContains('John Doe', $all->pluck('name'));
        $this->assertContains('Jane Doe', $all->pluck('name'));
    }

    public function testFind(): void
    {
        $user        = new User();
        $user->name  = 'John Doe';
        $user->title = 'admin';
        $user->age   = 35;
        $user->save();

        $check = User::find($user->id);
        $this->assertInstanceOf(User::class, $check);
        $this->assertTrue(Model::isDocumentModel($check));
        $this->assertTrue($check->exists);
        $this->assertEquals($user->id, $check->id);

        $this->assertEquals('John Doe', $check->name);
        $this->assertEquals(35, $check->age);
    }

    public function testInsertEmpty(): void
    {
        $success = User::insert([]);
        $this->assertTrue($success);
    }

    public function testGet(): void
    {
        User::insert([
            ['name' => 'John Doe'],
            ['name' => 'Jane Doe'],
        ]);

        $users = User::get();
        $this->assertCount(2, $users);
        $this->assertInstanceOf(EloquentCollection::class, $users);
        $this->assertInstanceOf(User::class, $users[0]);
    }

    public function testFirst(): void
    {
        User::insert([
            ['name' => 'John Doe'],
            ['name' => 'Jane Doe'],
        ]);

        $user = User::first();
        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue(Model::isDocumentModel($user));
        $this->assertEquals('John Doe', $user->name);
    }

    public function testNoDocument(): void
    {
        $items = Item::where('name', 'nothing')->get();
        $this->assertInstanceOf(EloquentCollection::class, $items);
        $this->assertEquals(0, $items->count());

        $item = Item::where('name', 'nothing')->first();
        $this->assertNull($item);

        $item = Item::find('51c33d8981fec6813e00000a');
        $this->assertNull($item);
    }

    public function testFindOrFail(): void
    {
        $this->expectException(ModelNotFoundException::class);
        User::findOrFail('51c33d8981fec6813e00000a');
    }

    public function testCreate(): void
    {
        $user = User::create(['name' => 'Jane Poe']);
        $this->assertInstanceOf(User::class, $user);

        $this->assertTrue(Model::isDocumentModel($user));
        $this->assertTrue($user->exists);
        $this->assertEquals('Jane Poe', $user->name);

        $check = User::where('name', 'Jane Poe')->first();
        $this->assertInstanceOf(User::class, $check);
        $this->assertEquals($user->id, $check->id);
    }

    public function testDestroy(): void
    {
        $user        = new User();
        $user->name  = 'John Doe';
        $user->title = 'admin';
        $user->age   = 35;
        $user->save();

        User::destroy((string) $user->id);

        $this->assertEquals(0, User::count());
    }

    public function testTouch(): void
    {
        $user        = new User();
        $user->name  = 'John Doe';
        $user->title = 'admin';
        $user->age   = 35;
        $user->save();

        $old = $user->updated_at;
        sleep(1);
        $user->touch();

        $check = User::find($user->id);
        $this->assertInstanceOf(User::class, $check);

        $this->assertNotEquals($old, $check->updated_at);
    }

    public function testSoftDelete(): void
    {
        Soft::create(['name' => 'John Doe']);
        Soft::create(['name' => 'Jane Doe']);

        $this->assertEquals(2, Soft::count());

        $object = Soft::where('name', 'John Doe')->first();
        $this->assertInstanceOf(Soft::class, $object);
        $this->assertTrue($object->exists);
        $this->assertFalse($object->trashed());
        $this->assertNull($object->deleted_at);

        $object->delete();
        $this->assertTrue($object->trashed());
        $this->assertNotNull($object->deleted_at);

        $object = Soft::where('name', 'John Doe')->first();
        $this->assertNull($object);

        $this->assertEquals(1, Soft::count());
        $this->assertEquals(2, Soft::withTrashed()->count());

        $object = Soft::withTrashed()->where('name', 'John Doe')->first();
        $this->assertNotNull($object);
        $this->assertInstanceOf(Carbon::class, $object->deleted_at);
        $this->assertTrue($object->trashed());

        $object->restore();
        $this->assertEquals(2, Soft::count());
    }

    /** @param class-string<Model> $model */
    #[DataProvider('provideId')]
    public function testPrimaryKey(string $model, mixed $id, mixed $expected, bool $expectedFound): void
    {
        $model::truncate();
        $expectedType = get_debug_type($expected);

        $document = new $model();
        $this->assertEquals('id', $document->getKeyName());

        $document->id = $id;
        $document->save();
        $this->assertSame($expectedType, get_debug_type($document->id));
        $this->assertEquals($expected, $document->id);
        $this->assertSame($expectedType, get_debug_type($document->getKey()));
        $this->assertEquals($expected, $document->getKey());

        $check = $model::find($id);

        if ($expectedFound) {
            $this->assertNotNull($check, 'Not found');
            $this->assertSame($expectedType, get_debug_type($check->id));
            $this->assertEquals($id, $check->id);
            $this->assertSame($expectedType, get_debug_type($check->getKey()));
            $this->assertEquals($id, $check->getKey());
        } else {
            $this->assertNull($check, 'Found');
        }
    }

    public static function provideId(): iterable
    {
        yield 'int' => [
            'model' => User::class,
            'id' => 10,
            'expected' => 10,
            // Don't expect this to be found, as the int is cast to string for the query
            'expectedFound' => false,
        ];

        yield 'cast as int' => [
            'model' => IdIsInt::class,
            'id' => 10,
            'expected' => 10,
            'expectedFound' => true,
        ];

        yield 'string' => [
            'model' => User::class,
            'id' => 'user-10',
            'expected' => 'user-10',
            'expectedFound' => true,
        ];

        yield 'cast as string' => [
            'model' => IdIsString::class,
            'id' => 'user-10',
            'expected' => 'user-10',
            'expectedFound' => true,
        ];

        $objectId = new ObjectID();
        yield 'ObjectID' => [
            'model' => User::class,
            'id' => $objectId,
            'expected' => (string) $objectId,
            'expectedFound' => true,
        ];

        $binaryUuid = new Binary(hex2bin('0c103357380648c9a84b867dcb625cfb'), Binary::TYPE_UUID);
        yield 'BinaryUuid' => [
            'model' => User::class,
            'id' => $binaryUuid,
            'expected' => (string) $binaryUuid,
            'expectedFound' => true,
        ];

        yield 'cast as BinaryUuid' => [
            'model' => IdIsBinaryUuid::class,
            'id' => $binaryUuid,
            'expected' => (string) $binaryUuid,
            'expectedFound' => true,
        ];

        $date = new UTCDateTime();
        yield 'UTCDateTime' => [
            'model' => User::class,
            'id' => $date,
            'expected' => $date,
            // Don't expect this to be found, as the original value is stored as UTCDateTime but then cast to string
            'expectedFound' => false,
        ];
    }

    public function testCustomPrimaryKey(): void
    {
        $book = new Book();
        $this->assertEquals('title', $book->getKeyName());

        $book->title  = 'A Game of Thrones';
        $book->author = 'George R. R. Martin';
        $book->save();

        $this->assertEquals('A Game of Thrones', $book->getKey());

        $check = Book::find('A Game of Thrones');
        $this->assertInstanceOf(Book::class, $check);
        $this->assertEquals('title', $check->getKeyName());
        $this->assertEquals('A Game of Thrones', $check->getKey());
        $this->assertEquals('A Game of Thrones', $check->title);
    }

    public function testScope(): void
    {
        Item::insert([
            ['name' => 'knife', 'type' => 'sharp'],
            ['name' => 'spoon', 'type' => 'round'],
        ]);

        $sharp = Item::sharp()->get();
        $this->assertEquals(1, $sharp->count());
    }

    public function testToArray(): void
    {
        $item = Item::create(['name' => 'fork', 'type' => 'sharp']);

        $array = $item->toArray();
        $keys  = array_keys($array);
        sort($keys);
        $this->assertEquals(['created_at', 'id', 'name', 'type', 'updated_at'], $keys);
        $this->assertIsString($array['created_at']);
        $this->assertIsString($array['updated_at']);
        $this->assertIsString($array['id']);
    }

    public function testUnset(): void
    {
        $user1 = User::create(['name' => 'John Doe', 'note1' => 'ABC', 'note2' => 'DEF']);
        $user2 = User::create(['name' => 'Jane Doe', 'note1' => 'ABC', 'note2' => 'DEF']);

        $user1->unset('note1');

        $this->assertFalse(isset($user1->note1));
        $this->assertTrue($user1->isDirty());

        $user1->save();
        $this->assertFalse($user1->isDirty());

        $this->assertFalse(isset($user1->note1));
        $this->assertTrue(isset($user1->note2));
        $this->assertTrue(isset($user2->note1));
        $this->assertTrue(isset($user2->note2));

        // Re-fetch to be sure
        $user1 = User::find($user1->id);
        $user2 = User::find($user2->id);

        $this->assertFalse(isset($user1->note1));
        $this->assertTrue(isset($user1->note2));
        $this->assertTrue(isset($user2->note1));
        $this->assertTrue(isset($user2->note2));

        $user2->unset(['note1', 'note2']);
        $user2->save();

        $this->assertFalse(isset($user2->note1));
        $this->assertFalse(isset($user2->note2));

        // Re-re-fetch to be sure
        $user2 = User::find($user2->id);

        $this->assertFalse(isset($user2->note1));
        $this->assertFalse(isset($user2->note2));
    }

    public function testUnsetRefresh(): void
    {
        $user = User::create(['name' => 'John Doe', 'note' => 'ABC']);
        $user->save();
        $user->unset('note');
        $this->assertTrue($user->isDirty());

        $user->refresh();

        $this->assertSame('ABC', $user->note);
        $this->assertFalse($user->isDirty());
    }

    public function testUnsetAndSet(): void
    {
        $user = User::create(['name' => 'John Doe', 'note1' => 'ABC', 'note2' => 'DEF']);

        $this->assertTrue($user->originalIsEquivalent('note1'));

        // Unset the value
        $user->unset('note1');
        $this->assertFalse(isset($user->note1));
        $this->assertNull($user['note1']);
        $this->assertFalse($user->originalIsEquivalent('note1'));
        $this->assertTrue($user->isDirty());
        $this->assertSame(['$unset' => ['note1' => true]], $user->getDirty());

        // Reset the previous value
        $user->note1 = 'ABC';
        $this->assertTrue($user->originalIsEquivalent('note1'));
        $this->assertFalse($user->isDirty());
        $this->assertSame([], $user->getDirty());

        // Change the value
        $user->note1 = 'GHI';
        $this->assertTrue(isset($user->note1));
        $this->assertSame('GHI', $user['note1']);
        $this->assertFalse($user->originalIsEquivalent('note1'));
        $this->assertTrue($user->isDirty());
        $this->assertSame(['note1' => 'GHI'], $user->getDirty());

        // Fetch to be sure the changes are not persisted yet
        $userCheck = User::find($user->id);
        $this->assertSame('ABC', $userCheck['note1']);

        // Persist the changes
        $user->save();

        // Re-fetch to be sure
        $user = User::find($user->id);

        $this->assertTrue(isset($user->note1));
        $this->assertSame('GHI', $user->note1);
        $this->assertTrue($user->originalIsEquivalent('note1'));
        $this->assertFalse($user->isDirty());
    }

    public function testUnsetDotAttributes(): void
    {
        $user = User::create(['name' => 'John Doe', 'notes' => ['note1' => 'ABC', 'note2' => 'DEF']]);

        $user->unset('notes.note1');

        $this->assertFalse(isset($user->notes['note1']));
        $this->assertTrue(isset($user->notes['note2']));
        $this->assertTrue($user->isDirty());
        $dirty = $user->getDirty();
        $this->assertArrayHasKey('notes', $dirty);
        $this->assertArrayNotHasKey('$unset', $dirty);

        $user->save();

        $this->assertFalse(isset($user->notes['note1']));
        $this->assertTrue(isset($user->notes['note2']));

        // Re-fetch to be sure
        $user = User::find($user->id);

        $this->assertFalse(isset($user->notes['note1']));
        $this->assertTrue(isset($user->notes['note2']));

        // Unset the parent key
        $user->unset('notes');

        $this->assertFalse(isset($user->notes['note1']));
        $this->assertFalse(isset($user->notes['note2']));
        $this->assertFalse(isset($user->notes));

        $user->save();

        $this->assertFalse(isset($user->notes));

        // Re-fetch to be sure
        $user = User::find($user->id);

        $this->assertFalse(isset($user->notes));
    }

    public function testUnsetDotAttributesAndSet(): void
    {
        $user = User::create(['name' => 'John Doe', 'notes' => ['note1' => 'ABC', 'note2' => 'DEF']]);

        // notes.note2 is the last attribute of the document
        $user->unset('notes.note2');
        $this->assertTrue($user->isDirty());
        $this->assertSame(['note1' => 'ABC'], $user->notes);

        $user->setAttribute('notes.note2', 'DEF');
        $this->assertFalse($user->isDirty());
        $this->assertSame(['note1' => 'ABC', 'note2' => 'DEF'], $user->notes);

        // Unsetting and resetting the 1st attribute of the document will change the order of the attributes
        $user->unset('notes.note1');
        $this->assertSame(['note2' => 'DEF'], $user->notes);
        $this->assertTrue($user->isDirty());

        $user->setAttribute('notes.note1', 'ABC');
        $this->assertSame(['note2' => 'DEF', 'note1' => 'ABC'], $user->notes);
        $this->assertTrue($user->isDirty());
        $this->assertSame(['notes' => ['note2' => 'DEF', 'note1' => 'ABC']], $user->getDirty());

        $user->save();
        $this->assertSame(['note2' => 'DEF', 'note1' => 'ABC'], $user->notes);

        // Re-fetch to be sure
        $user = User::find($user->id);

        $this->assertSame(['note2' => 'DEF', 'note1' => 'ABC'], $user->notes);
    }

    public function testDateUseLocalTimeZone(): void
    {
        // The default timezone is reset to UTC before every test in OrchestraTestCase
        $tz = 'Australia/Sydney';
        date_default_timezone_set($tz);

        $date = new DateTime('1965/03/02 15:30:10');
        $user = User::create(['birthday' => $date]);
        $this->assertInstanceOf(Carbon::class, $user->birthday);
        $this->assertEquals($tz, $user->birthday->getTimezone()->getName());
        $user->save();

        $user = User::find($user->id);
        $this->assertEquals($date, $user->birthday);
        $this->assertEquals($tz, $user->birthday->getTimezone()->getName());
        $this->assertSame('1965-03-02T15:30:10+10:00', $user->birthday->format(DATE_ATOM));

        $tz = 'America/New_York';
        date_default_timezone_set($tz);
        $user = User::find($user->id);
        $this->assertEquals($date, $user->birthday);
        $this->assertEquals($tz, $user->birthday->getTimezone()->getName());
        $this->assertSame('1965-03-02T00:30:10-05:00', $user->birthday->format(DATE_ATOM));

        date_default_timezone_set('UTC');
    }

    public function testDates(): void
    {
        $user = User::create(['name' => 'John Doe', 'birthday' => new DateTime('1965/1/1')]);
        $this->assertInstanceOf(Carbon::class, $user->birthday);

        $user = User::where('birthday', '<', new DateTime('1968/1/1'))->first();
        $this->assertEquals('John Doe', $user->name);

        $user = User::create(['name' => 'John Doe', 'birthday' => new DateTime('1980/1/1')]);
        $this->assertInstanceOf(Carbon::class, $user->birthday);

        $check = User::find($user->id);
        $this->assertInstanceOf(Carbon::class, $check->birthday);
        $this->assertEquals($user->birthday, $check->birthday);

        $user = User::where('birthday', '>', new DateTime('1975/1/1'))->first();
        $this->assertEquals('John Doe', $user->name);

        // test custom date format for json output
        $json = $user->toArray();
        $this->assertEquals($user->birthday->format('l jS \of F Y h:i:s A'), $json['birthday']);
        $this->assertEquals($user->created_at->format('l jS \of F Y h:i:s A'), $json['created_at']);

        // test created_at
        $item = Item::create(['name' => 'sword']);
        $this->assertInstanceOf(UTCDateTime::class, $item->getRawOriginal('created_at'));
        $this->assertEquals($item->getRawOriginal('created_at')
            ->toDateTime()
            ->getTimestamp(), $item->created_at->getTimestamp());
        $this->assertLessThan(2, abs(time() - $item->created_at->getTimestamp()));

        $item = Item::create(['name' => 'sword']);
        $this->assertInstanceOf(Item::class, $item);
        $json = $item->toArray();
        $this->assertEquals($item->created_at->toISOString(), $json['created_at']);
    }

    public static function provideDate(): Generator
    {
        yield 'int timestamp' => [time()];
        yield 'Carbon date' => [Date::now()];
        yield 'Date in words' => ['Monday 8th August 2005 03:12:46 PM'];
        yield 'Date in words before unix epoch' => ['Monday 8th August 1960 03:12:46 PM'];
        yield 'Date' => ['2005-08-08'];
        yield 'Date before unix epoch' => ['1965-08-08'];
        yield 'DateTime date' => [new DateTime('2010-08-08')];
        yield 'DateTime date before unix epoch' => [new DateTime('1965-08-08')];
        yield 'DateTime date and time' => [new DateTime('2010-08-08 04.08.37')];
        yield 'DateTime date and time before unix epoch' => [new DateTime('1965-08-08 04.08.37')];
        yield 'DateTime date, time and ms' => [new DateTime('2010-08-08 04.08.37.324')];
        yield 'DateTime date, time and ms before unix epoch' => [new DateTime('1965-08-08 04.08.37.324')];
    }

    #[DataProvider('provideDate')]
    public function testDateInputs($date): void
    {
        // Test with create and standard property
        $user = User::create(['name' => 'Jane Doe', 'birthday' => $date]);
        $this->assertInstanceOf(User::class, $user);
        $this->assertInstanceOf(Carbon::class, $user->birthday);

        //Test with setAttribute and standard property
        $user->setAttribute('birthday', null);
        $this->assertNull($user->birthday);

        $user->setAttribute('birthday', $date);
        $this->assertInstanceOf(Carbon::class, $user->birthday);

        // Test with create and array property
        $user = User::create(['name' => 'Jane Doe', 'entry' => ['date' => $date]]);
        $this->assertInstanceOf(Carbon::class, $user->getAttribute('entry.date'));

        // Test with setAttribute and array property
        $user->setAttribute('entry.date', null);
        $this->assertNull($user->birthday);

        $user->setAttribute('entry.date', $date);
        $this->assertInstanceOf(Carbon::class, $user->getAttribute('entry.date'));

        // Test with create and array property
        $data = $user->toArray();
        $this->assertIsString($data['entry']['date']);
    }

    public function testDateNull(): void
    {
        $user = User::create(['name' => 'Jane Doe', 'birthday' => null]);
        $this->assertNull($user->birthday);

        $user->setAttribute('birthday', new DateTime());
        $user->setAttribute('birthday', null);
        $this->assertNull($user->birthday);

        $user->save();

        // Re-fetch to be sure
        $user = User::find($user->id);
        $this->assertNull($user->birthday);

        // Nested field with dot notation
        $user = User::create(['name' => 'Jane Doe', 'entry' => ['date' => null]]);
        $this->assertNull($user->getAttribute('entry.date'));

        $user->setAttribute('entry.date', new DateTime());
        $user->setAttribute('entry.date', null);
        $this->assertNull($user->getAttribute('entry.date'));

        // Re-fetch to be sure
        $user = User::find($user->id);
        $this->assertNull($user->getAttribute('entry.date'));
    }

    public function testCarbonDateMockingWorks()
    {
        $fakeDate = Carbon::createFromDate(2000, 01, 01);

        Carbon::setTestNow($fakeDate);
        $item = Item::create(['name' => 'sword']);

        $this->assertLessThan(1, $fakeDate->diffInSeconds($item->created_at));
    }

    public function testIdAttribute(): void
    {
        $user = User::create(['name' => 'John Doe']);
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame($user->id, $user->_id);

        $user = User::create(['id' => 'custom_id', 'name' => 'John Doe']);
        $this->assertSame($user->id, $user->_id);
    }

    public function testPushPull(): void
    {
        $user = User::create(['name' => 'John Doe']);
        $this->assertInstanceOf(User::class, $user);

        $user->push('tags', 'tag1');
        $user->push('tags', ['tag1', 'tag2']);
        $user->push('tags', 'tag2', true);

        $this->assertEquals(['tag1', 'tag1', 'tag2'], $user->tags);
        $user = User::where('id', $user->id)->first();
        $this->assertEquals(['tag1', 'tag1', 'tag2'], $user->tags);

        $user->pull('tags', 'tag1');

        $this->assertEquals(['tag2'], $user->tags);
        $user = User::where('id', $user->id)->first();
        $this->assertEquals(['tag2'], $user->tags);

        $user->push('tags', 'tag3');
        $user->pull('tags', ['tag2', 'tag3']);

        $this->assertEquals([], $user->tags);
        $user = User::where('id', $user->id)->first();
        $this->assertEquals([], $user->tags);
    }

    public function testRaw(): void
    {
        User::create(['name' => 'John Doe', 'age' => 35]);
        User::create(['name' => 'Jane Doe', 'age' => 35]);
        User::create(['name' => 'Harry Hoe', 'age' => 15]);

        $users = User::raw(function (Collection $collection) {
            return $collection->find(['age' => 35]);
        });
        $this->assertInstanceOf(EloquentCollection::class, $users);
        $this->assertInstanceOf(User::class, $users[0]);

        $count = User::raw(function (Collection $collection) {
            return $collection->estimatedDocumentCount();
        });
        $this->assertEquals(3, $count);

        $result = User::raw(function (Collection $collection) {
            return $collection->insertOne(['name' => 'Yvonne Yoe', 'age' => 35]);
        });
        $this->assertNotNull($result);
    }

    #[DataProvider('provideTypeMap')]
    public function testRawHyradeModel(array $typeMap): void
    {
        User::insert([
            ['name' => 'John Doe', 'age' => 35, 'embed' => ['foo' => 'bar'], 'list' => [1, 2, 3]],
            ['name' => 'Jane Doe', 'age' => 35, 'embed' => ['foo' => 'bar'], 'list' => [1, 2, 3]],
            ['name' => 'Harry Hoe', 'age' => 15, 'embed' => ['foo' => 'bar'], 'list' => [1, 2, 3]],
        ]);

        // Single document result
        $user = User::raw(fn (Collection $collection) => $collection->findOne(
            ['age' => 35],
            [
                'projection' => ['_id' => 1, 'name' => 1, 'age' => 1, 'now' => '$$NOW', 'embed' => 1, 'list' => 1],
                'typeMap' => $typeMap,
            ],
        ));

        $this->assertInstanceOf(User::class, $user);
        $this->assertArrayNotHasKey('_id', $user->getAttributes());
        $this->assertArrayHasKey('id', $user->getAttributes());
        $this->assertNotEmpty($user->id);
        $this->assertInstanceOf(Carbon::class, $user->now);
        $this->assertEquals(['foo' => 'bar'], (array) $user->embed);
        $this->assertEquals([1, 2, 3], (array) $user->list);

        // Cursor result
        $result = User::raw(fn (Collection $collection) => $collection->aggregate([
            ['$set' => ['now' => '$$NOW']],
            ['$limit' => 2],
        ], ['typeMap' => $typeMap]));

        $this->assertInstanceOf(EloquentCollection::class, $result);
        $this->assertCount(2, $result);
        $user = $result->first();
        $this->assertInstanceOf(User::class, $user);
        $this->assertArrayNotHasKey('_id', $user->getAttributes());
        $this->assertArrayHasKey('id', $user->getAttributes());
        $this->assertNotEmpty($user->id);
        $this->assertInstanceOf(Carbon::class, $user->now);
        $this->assertEquals(['foo' => 'bar'], $user->embed);
        $this->assertEquals([1, 2, 3], $user->list);
    }

    public static function provideTypeMap(): Generator
    {
        yield 'default' => [[]];
        yield 'array' => [['root' => 'array', 'document' => 'array', 'array' => 'array']];
        yield 'object' => [['root' => 'object', 'document' => 'object', 'array' => 'array']];
        yield 'Library BSON' => [['root' => BSONDocument::class, 'document' => BSONDocument::class, 'array' => BSONArray::class]];
        yield 'Driver BSON' => [['root' => 'bson', 'document' => 'bson', 'array' => 'bson']];
    }

    public function testDotNotation(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'address' => [
                'city' => 'Paris',
                'country' => 'France',
            ],
        ]);

        $this->assertEquals('Paris', $user->getAttribute('address.city'));
        $this->assertEquals('Paris', $user['address.city']);
        $this->assertEquals('Paris', $user->{'address.city'});

        // Fill
        $user->fill(['address.city' => 'Strasbourg']);

        $this->assertEquals('Strasbourg', $user['address.city']);
    }

    public function testAttributeMutator(): void
    {
        $username     = 'JaneDoe';
        $usernameSlug = Str::slug($username);
        $user         = User::create([
            'name' => 'Jane Doe',
            'username' => $username,
        ]);

        $this->assertNotEquals($username, $user->getAttribute('username'));
        $this->assertNotEquals($username, $user['username']);
        $this->assertNotEquals($username, $user->username);
        $this->assertEquals($usernameSlug, $user->getAttribute('username'));
        $this->assertEquals($usernameSlug, $user['username']);
        $this->assertEquals($usernameSlug, $user->username);
    }

    public function testMultipleLevelDotNotation(): void
    {
        $book = Book::create([
            'title' => 'A Game of Thrones',
            'chapters' => [
                'one' => ['title' => 'The first chapter'],
            ],
        ]);
        $this->assertInstanceOf(Book::class, $book);

        $this->assertEquals(['one' => ['title' => 'The first chapter']], $book->chapters);
        $this->assertEquals(['title' => 'The first chapter'], $book['chapters.one']);
        $this->assertEquals('The first chapter', $book['chapters.one.title']);
    }

    public function testGetDirtyDates(): void
    {
        $user = new User();
        $user->setRawAttributes(['name' => 'John Doe', 'birthday' => new DateTime('19 august 1989')], true);
        $this->assertEmpty($user->getDirty());

        $user->birthday = new DateTime('19 august 1989');
        $this->assertEmpty($user->getDirty());
    }

    public function testChunkById(): void
    {
        User::create(['name' => 'fork', 'tags' => ['sharp', 'pointy']]);
        User::create(['name' => 'spork', 'tags' => ['sharp', 'pointy', 'round', 'bowl']]);
        User::create(['name' => 'spoon', 'tags' => ['round', 'bowl']]);

        $names = [];
        User::chunkById(2, function (EloquentCollection $items) use (&$names) {
            $names = array_merge($names, $items->pluck('name')->all());
        });

        $this->assertEquals(['fork', 'spork', 'spoon'], $names);
    }

    public function testTruncateModel()
    {
        User::create(['name' => 'John Doe']);

        User::truncate();

        $this->assertEquals(0, User::count());
    }

    public function testGuardedModel()
    {
        $model = new Guarded();

        // foobar is properly guarded
        $model->fill(['foobar' => 'ignored', 'name' => 'John Doe']);
        $this->assertFalse(isset($model->foobar));
        $this->assertSame('John Doe', $model->name);

        // foobar is guarded to any level
        $model->fill(['foobar->level2' => 'v2']);
        $this->assertNull($model->getAttribute('foobar->level2'));

        // multi level statement also guarded
        $model->fill(['level1->level2' => 'v1']);
        $this->assertNull($model->getAttribute('level1->level2'));

        // level1 is still writable
        $dataValues = ['array', 'of', 'values'];
        $model->fill(['level1' => $dataValues]);
        $this->assertEquals($dataValues, $model->getAttribute('level1'));
    }

    public function testFirstOrCreate(): void
    {
        $name = 'Jane Poe';

        $user = User::where('name', $name)->first();
        $this->assertNull($user);

        $user = User::firstOrCreate(['name' => $name]);
        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue(Model::isDocumentModel($user));
        $this->assertTrue($user->exists);
        $this->assertEquals($name, $user->name);

        $check = User::where('name', $name)->first();
        $this->assertInstanceOf(User::class, $check);
        $this->assertEquals($user->id, $check->id);
    }

    public function testEnumCast(): void
    {
        $name = 'John Member';

        $user                = new User();
        $user->name          = $name;
        $user->member_status = MemberStatus::Member;
        $user->save();

        $check = User::where('name', $name)->first();
        $this->assertInstanceOf(User::class, $check);
        $this->assertSame(MemberStatus::Member->value, $check->getRawOriginal('member_status'));
        $this->assertSame(MemberStatus::Member, $check->member_status);
    }

    public function testNumericFieldName(): void
    {
        $user      = new User();
        $user->{1} = 'one';
        $user->{2} = ['3' => 'two.three'];
        $user->save();

        $found = User::where(1, 'one')->first();
        $this->assertInstanceOf(User::class, $found);
        $this->assertEquals('one', $found[1]);

        $found = User::where('2.3', 'two.three')->first();
        $this->assertInstanceOf(User::class, $found);
        $this->assertEquals([3 => 'two.three'], $found[2]);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testCreateOrFirst(bool $transaction)
    {
        $connection = DB::connection('mongodb');
        $connection
            ->getCollection('users')
            ->createIndex(['email' => 1], ['unique' => true]);

        if ($transaction) {
            $connection->beginTransaction();
        }

        Carbon::setTestNow('2010-06-22');
        $createdAt = Carbon::now()->getTimestamp();
        $events = [];
        self::registerModelEvents(User::class, $events);
        $user1 = User::createOrFirst(['email' => 'john.doe@example.com']);

        $this->assertSame('john.doe@example.com', $user1->email);
        $this->assertNull($user1->name);
        $this->assertTrue($user1->wasRecentlyCreated);
        $this->assertEquals($createdAt, $user1->created_at->getTimestamp());
        $this->assertEquals($createdAt, $user1->updated_at->getTimestamp());
        $this->assertEquals(['saving', 'creating', 'created', 'saved'], $events);

        Carbon::setTestNow('2020-12-28');
        $events = [];
        $user2 = User::createOrFirst(
            ['email' => 'john.doe@example.com'],
            ['name' => 'John Doe', 'birthday' => new DateTime('1987-05-28')],
        );

        $this->assertEquals($user1->id, $user2->id);
        $this->assertSame('john.doe@example.com', $user2->email);
        $this->assertNull($user2->name);
        $this->assertNull($user2->birthday);
        $this->assertFalse($user2->wasRecentlyCreated);
        $this->assertEquals($createdAt, $user1->created_at->getTimestamp());
        $this->assertEquals($createdAt, $user1->updated_at->getTimestamp());
        if ($transaction) {
            // In a transaction, firstOrCreate is used instead.
            // Since a document is found, "save" is not called.
            $this->assertEquals([], $events);
        } else {
            // The "duplicate key error" exception interrupts the save process
            // before triggering "created" and "saved". Consistent with Laravel
            $this->assertEquals(['saving', 'creating'], $events);
        }

        $events = [];
        $user3 = User::createOrFirst(
            ['email' => 'jane.doe@example.com'],
            ['name' => 'Jane Doe', 'birthday' => new DateTime('1987-05-28')],
        );

        $this->assertNotEquals($user3->id, $user1->id);
        $this->assertSame('jane.doe@example.com', $user3->email);
        $this->assertSame('Jane Doe', $user3->name);
        $this->assertEquals(new DateTime('1987-05-28'), $user3->birthday);
        $this->assertTrue($user3->wasRecentlyCreated);
        $this->assertEquals($createdAt, $user1->created_at->getTimestamp());
        $this->assertEquals($createdAt, $user1->updated_at->getTimestamp());
        $this->assertEquals(['saving', 'creating', 'created', 'saved'], $events);

        $events = [];
        $user4 = User::createOrFirst(
            ['name' => 'Robert Doe'],
            ['name' => 'Maria Doe', 'email' => 'maria.doe@example.com'],
        );

        $this->assertSame('Maria Doe', $user4->name);
        $this->assertTrue($user4->wasRecentlyCreated);
        $this->assertEquals(['saving', 'creating', 'created', 'saved'], $events);

        if ($transaction) {
            $connection->commit();
        }
    }

    #[TestWith([['_id' => new ObjectID()]])]
    #[TestWith([['id' => new ObjectID()]])]
    #[TestWith([['foo' => 'bar']])]
    public function testUpdateOrCreate(array $criteria)
    {
        // Insert data to ensure we filter on the correct criteria, and not getting
        // the first document randomly.
        User::insert([
            ['email' => 'fixture@example.com'],
            ['email' => 'john.doe@example.com'],
        ]);

        Carbon::setTestNow('2010-01-01');
        $createdAt = Carbon::now()->getTimestamp();
        $events = [];
        self::registerModelEvents(User::class, $events);

        // Create
        $user = User::updateOrCreate(
            $criteria,
            ['email' => 'john.doe@example.com', 'birthday' => new DateTime('1987-05-28')],
        );
        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('john.doe@example.com', $user->email);
        $this->assertEquals(new DateTime('1987-05-28'), $user->birthday);
        $this->assertEquals($createdAt, $user->created_at->getTimestamp());
        $this->assertEquals($createdAt, $user->updated_at->getTimestamp());
        $this->assertEquals(['saving', 'creating', 'created', 'saved'], $events);
        Carbon::setTestNow('2010-02-01');
        $updatedAt = Carbon::now()->getTimestamp();

        // Update
        $events = [];
        $user = User::updateOrCreate(
            $criteria,
            ['birthday' => new DateTime('1990-01-12'), 'foo' => 'bar'],
        );

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('john.doe@example.com', $user->email);
        $this->assertEquals(new DateTime('1990-01-12'), $user->birthday);
        $this->assertEquals($createdAt, $user->created_at->getTimestamp());
        $this->assertEquals($updatedAt, $user->updated_at->getTimestamp());
        $this->assertEquals(['saving', 'updating', 'updated', 'saved'], $events);

        // Stored data
        $checkUser = User::where($criteria)->first();
        $this->assertInstanceOf(User::class, $checkUser);
        $this->assertEquals('john.doe@example.com', $checkUser->email);
        $this->assertEquals(new DateTime('1990-01-12'), $checkUser->birthday);
        $this->assertEquals($createdAt, $checkUser->created_at->getTimestamp());
        $this->assertEquals($updatedAt, $checkUser->updated_at->getTimestamp());
    }

    #[TestWith(['_id'])]
    #[TestWith(['id'])]
    public function testCreateWithNullId(string $id)
    {
        $user = User::create([$id => null, 'email' => 'foo@bar']);
        $this->assertNotNull(ObjectId::class, $user->id);
        $this->assertSame(1, User::count());
    }

    /** @param class-string<Model> $modelClass */
    private static function registerModelEvents(string $modelClass, array &$events): void
    {
        $modelClass::creating(function () use (&$events) {
            $events[] = 'creating';
        });
        $modelClass::created(function () use (&$events) {
            $events[] = 'created';
        });
        $modelClass::updating(function () use (&$events) {
            $events[] = 'updating';
        });
        $modelClass::updated(function () use (&$events) {
            $events[] = 'updated';
        });
        $modelClass::saving(function () use (&$events) {
            $events[] = 'saving';
        });
        $modelClass::saved(function () use (&$events) {
            $events[] = 'saved';
        });
    }
}
