<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests;

use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Tests\Models\Book;
use MongoDB\Laravel\Tests\Models\Experience;
use MongoDB\Laravel\Tests\Models\Label;
use MongoDB\Laravel\Tests\Models\Role;
use MongoDB\Laravel\Tests\Models\Skill;
use MongoDB\Laravel\Tests\Models\SqlBook;
use MongoDB\Laravel\Tests\Models\SqlRole;
use MongoDB\Laravel\Tests\Models\SqlUser;
use MongoDB\Laravel\Tests\Models\User;
use PDOException;

class HybridRelationsTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection('sqlite')->select('SELECT 1');
        } catch (PDOException) {
            $this->markTestSkipped('SQLite connection is not available.');
        }

        SqlUser::executeSchema();
        SqlBook::executeSchema();
        SqlRole::executeSchema();
    }

    public function tearDown(): void
    {
        SqlUser::truncate();
        SqlBook::truncate();
        SqlRole::truncate();
        Skill::truncate();
        Experience::truncate();
        Label::truncate();
    }

    public function testSqlRelations()
    {
        $user = new SqlUser();
        $this->assertInstanceOf(SqlUser::class, $user);
        $this->assertInstanceOf(SQLiteConnection::class, $user->getConnection());

        // SQL User
        $user->name = 'John Doe';
        $user->save();
        $this->assertIsInt($user->id);

        // SQL has many
        $book = new Book(['title' => 'Game of Thrones']);
        $user->books()->save($book);
        $user = SqlUser::find($user->id); // refetch
        $this->assertCount(1, $user->books);

        // MongoDB belongs to
        $book = $user->books()->first(); // refetch
        $this->assertEquals('John Doe', $book->sqlAuthor->name);

        // SQL has one
        $role = new Role(['type' => 'admin']);
        $user->role()->save($role);
        $user = SqlUser::find($user->id); // refetch
        $this->assertEquals('admin', $user->role->type);

        // MongoDB belongs to
        $role = $user->role()->first(); // refetch
        $this->assertEquals('John Doe', $role->sqlUser->name);

        // MongoDB User
        $user       = new User();
        $user->name = 'John Doe';
        $user->save();

        // MongoDB has many
        $book = new SqlBook(['title' => 'Game of Thrones']);
        $user->sqlBooks()->save($book);
        $user = User::find($user->id); // refetch
        $this->assertCount(1, $user->sqlBooks);

        // SQL belongs to
        $book = $user->sqlBooks()->first(); // refetch
        $this->assertEquals('John Doe', $book->author->name);

        // MongoDB has one
        $role = new SqlRole(['type' => 'admin']);
        $user->sqlRole()->save($role);
        $user = User::find($user->id); // refetch
        $this->assertEquals('admin', $user->sqlRole->type);

        // SQL belongs to
        $role = $user->sqlRole()->first(); // refetch
        $this->assertEquals('John Doe', $role->user->name);
    }

    public function testHybridWhereHas()
    {
        $user      = new SqlUser();
        $otherUser = new SqlUser();
        $this->assertInstanceOf(SqlUser::class, $user);
        $this->assertInstanceOf(SQLiteConnection::class, $user->getConnection());
        $this->assertInstanceOf(SqlUser::class, $otherUser);
        $this->assertInstanceOf(SQLiteConnection::class, $otherUser->getConnection());

        // SQL User
        $user->name = 'John Doe';
        $user->id   = 2;
        $user->save();
        // Other user
        $otherUser->name = 'Other User';
        $otherUser->id   = 3;
        $otherUser->save();
        // Make sure they are created
        $this->assertIsInt($user->id);
        $this->assertIsInt($otherUser->id);
        // Clear to start
        $user->books()->truncate();
        $otherUser->books()->truncate();
        // Create books
        $otherUser->books()->saveMany([
            new Book(['title' => 'Harry Plants']),
            new Book(['title' => 'Harveys']),
        ]);
        // SQL has many
        $user->books()->saveMany([
            new Book(['title' => 'Game of Thrones']),
            new Book(['title' => 'Harry Potter']),
            new Book(['title' => 'Harry Planter']),
        ]);

        $users = SqlUser::whereHas('books', function ($query) {
            return $query->where('title', 'LIKE', 'Har%');
        })->get();

        $this->assertEquals(2, $users->count());

        $users = SqlUser::whereHas('books', function ($query) {
            return $query->where('title', 'LIKE', 'Harry%');
        }, '>=', 2)->get();

        $this->assertEquals(1, $users->count());

        $books = Book::whereHas('sqlAuthor', function ($query) {
            return $query->where('name', 'LIKE', 'Other%');
        })->get();

        $this->assertEquals(2, $books->count());
    }

    public function testHybridWith()
    {
        DB::connection('mongodb')->enableQueryLog();
        $user      = new SqlUser();
        $otherUser = new SqlUser();
        $this->assertInstanceOf(SqlUser::class, $user);
        $this->assertInstanceOf(SQLiteConnection::class, $user->getConnection());
        $this->assertInstanceOf(SqlUser::class, $otherUser);
        $this->assertInstanceOf(SQLiteConnection::class, $otherUser->getConnection());

        // SQL User
        $user->name = 'John Doe';
        $user->id   = 2;
        $user->save();
        // Other user
        $otherUser->name = 'Other User';
        $otherUser->id   = 3;
        $otherUser->save();
        // Make sure they are created
        $this->assertIsInt($user->id);
        $this->assertIsInt($otherUser->id);
        // Clear to start
        Book::truncate();
        SqlBook::truncate();
        // Create books
        // SQL relation
        $user->sqlBooks()->saveMany([
            new SqlBook(['title' => 'Game of Thrones']),
            new SqlBook(['title' => 'Harry Potter']),
        ]);

        $otherUser->sqlBooks()->saveMany([
            new SqlBook(['title' => 'Harry Plants']),
            new SqlBook(['title' => 'Harveys']),
            new SqlBook(['title' => 'Harry Planter']),
        ]);
        // SQL has many Hybrid
        $user->books()->saveMany([
            new Book(['title' => 'Game of Thrones']),
            new Book(['title' => 'Harry Potter']),
        ]);

        $otherUser->books()->saveMany([
            new Book(['title' => 'Harry Plants']),
            new Book(['title' => 'Harveys']),
            new Book(['title' => 'Harry Planter']),
        ]);

        SqlUser::with('books')->get()
            ->each(function ($user) {
                $this->assertEquals($user->id, $user->books->count());
            });
        //SqlUser::withCount('books')->get()
        //    ->each(function ($user) {
        //        $this->assertEquals($user->id, $user->books_count);
        //    });

        SqlUser::whereHas('sqlBooks', function ($query) {
            return $query->where('title', 'LIKE', 'Harry%');
        })
            ->with('books')
            ->get()
            ->each(function ($user) {
                $this->assertEquals($user->id, $user->books->count());
            });
    }

    public function testHybridBelongsToMany()
    {
        $user = new SqlUser();
        $user2 = new SqlUser();
        $this->assertInstanceOf(SqlUser::class, $user);
        $this->assertInstanceOf(SQLiteConnection::class, $user->getConnection());
        $this->assertInstanceOf(SqlUser::class, $user2);
        $this->assertInstanceOf(SQLiteConnection::class, $user2->getConnection());

        // Create Mysql Users
        $user->fill(['name' => 'John Doe'])->save();
        $user = SqlUser::query()->find($user->id);

        $user2->fill(['name' => 'Maria Doe'])->save();
        $user2 = SqlUser::query()->find($user2->id);

        // Create Mongodb Skills
        $skill = Skill::query()->create(['name' => 'Laravel']);
        $skill2 = Skill::query()->create(['name' => 'MongoDB']);

        // sync (pivot is empty)
        $skill->sqlUsers()->sync([$user->id, $user2->id]);
        $check = Skill::query()->find($skill->id);
        $this->assertEquals(2, $check->sqlUsers->count());

        // sync (pivot is not empty)
        $skill->sqlUsers()->sync($user);
        $check = Skill::query()->find($skill->id);
        $this->assertEquals(1, $check->sqlUsers->count());

        // Inverse sync (pivot is empty)
        $user->skills()->sync([$skill->id, $skill2->id]);
        $check = SqlUser::find($user->id);
        $this->assertEquals(2, $check->skills->count());

        // Inverse sync (pivot is not empty)
        $user->skills()->sync($skill);
        $check = SqlUser::find($user->id);
        $this->assertEquals(1, $check->skills->count());

        // Inverse attach
        $user->skills()->sync([]);
        $check = SqlUser::find($user->id);
        $this->assertEquals(0, $check->skills->count());
        $user->skills()->attach($skill);
        $check = SqlUser::find($user->id);
        $this->assertEquals(1, $check->skills->count());
    }

    public function testHybridMorphToManySqlModelToMongoModel()
    {
        // SqlModel -> MorphToMany -> MongoModel
        $user      = new SqlUser();
        $user2 = new SqlUser();
        $this->assertInstanceOf(SqlUser::class, $user);
        $this->assertInstanceOf(SQLiteConnection::class, $user->getConnection());
        $this->assertInstanceOf(SqlUser::class, $user2);
        $this->assertInstanceOf(SQLiteConnection::class, $user2->getConnection());

        // Create Mysql Users
        $user->fill(['name' => 'John Doe'])->save();
        $user = SqlUser::query()->find($user->id);

        $user2->fill(['name' => 'Maria Doe'])->save();
        $user2 = SqlUser::query()->find($user2->id);

        // Create Mongodb skills
        $label = Label::query()->create(['name' => 'Laravel']);
        $label2 = Label::query()->create(['name' => 'MongoDB']);

        // MorphToMany (pivot is empty)
        $user->labels()->sync([$label->id, $label2->id]);
        $check = SqlUser::query()->find($user->id);
        $this->assertEquals(2, $check->labels->count());

        // MorphToMany (pivot is not empty)
        $user->labels()->sync($label);
        $check = SqlUser::query()->find($user->id);
        $this->assertEquals(1, $check->labels->count());

        // Attach MorphToMany
        $user->labels()->sync([]);
        $check = SqlUser::query()->find($user->id);
        $this->assertEquals(0, $check->labels->count());
        $user->labels()->attach($label);
        $user->labels()->attach($label); // ignore duplicates
        $check = SqlUser::query()->find($user->id);
        $this->assertEquals(1, $check->labels->count());

        // Inverse MorphToMany (pivot is empty)
        $label->sqlUsers()->sync([$user->id, $user2->id]);
        $check = Label::query()->find($label->id);
        $this->assertEquals(2, $check->sqlUsers->count());

        // Inverse MorphToMany (pivot is empty)
        $label->sqlUsers()->sync([$user->id, $user2->id]);
        $check = Label::query()->find($label->id);
        $this->assertEquals(2, $check->sqlUsers->count());
    }

    public function testHybridMorphToManyMongoModelToSqlModel()
    {
        // MongoModel -> MorphToMany -> SqlModel
        $user      = new SqlUser();
        $user2 = new SqlUser();
        $this->assertInstanceOf(SqlUser::class, $user);
        $this->assertInstanceOf(SQLiteConnection::class, $user->getConnection());
        $this->assertInstanceOf(SqlUser::class, $user2);
        $this->assertInstanceOf(SQLiteConnection::class, $user2->getConnection());

        // Create Mysql Users
        $user->fill(['name' => 'John Doe'])->save();
        $user = SqlUser::query()->find($user->id);

        $user2->fill(['name' => 'Maria Doe'])->save();
        $user2 = SqlUser::query()->find($user2->id);

        // Create Mongodb experiences
        $experience = Experience::query()->create(['title' => 'DB expert']);
        $experience2 = Experience::query()->create(['title' => 'MongoDB']);

        // MorphToMany (pivot is empty)
        $experience->sqlUsers()->sync([$user->id, $user2->id]);
        $check = Experience::query()->find($experience->id);
        $this->assertEquals(2, $check->sqlUsers->count());

        // MorphToMany (pivot is not empty)
        $experience->sqlUsers()->sync([$user->id]);
        $check = Experience::query()->find($experience->id);
        $this->assertEquals(1, $check->sqlUsers->count());

        // Inverse MorphToMany (pivot is empty)
        $user->experiences()->sync([$experience->id, $experience2->id]);
        $check = SqlUser::query()->find($user->id);
        $this->assertEquals(2, $check->experiences->count());

        // Inverse MorphToMany (pivot is not empty)
        $user->experiences()->sync([$experience->id]);
        $check = SqlUser::query()->find($user->id);
        $this->assertEquals(1, $check->experiences->count());

        // Inverse MorphToMany (pivot is not empty)
        $user->experiences()->sync([]);
        $check = SqlUser::query()->find($user->id);
        $this->assertEquals(0, $check->experiences->count());
        $user->experiences()->attach($experience);
        $user->experiences()->attach($experience); // ignore duplicates
        $check = SqlUser::query()->find($user->id);
        $this->assertEquals(1, $check->experiences->count());
    }
}
