<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests;

use Illuminate\Database\Eloquent\Collection;
use Mockery;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Tests\Models\Address;
use MongoDB\Laravel\Tests\Models\Book;
use MongoDB\Laravel\Tests\Models\Client;
use MongoDB\Laravel\Tests\Models\Group;
use MongoDB\Laravel\Tests\Models\Item;
use MongoDB\Laravel\Tests\Models\Label;
use MongoDB\Laravel\Tests\Models\Photo;
use MongoDB\Laravel\Tests\Models\Role;
use MongoDB\Laravel\Tests\Models\Skill;
use MongoDB\Laravel\Tests\Models\Soft;
use MongoDB\Laravel\Tests\Models\User;

class RelationsTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();

        User::truncate();
        Client::truncate();
        Address::truncate();
        Book::truncate();
        Item::truncate();
        Role::truncate();
        Group::truncate();
        Photo::truncate();
        Label::truncate();
        Skill::truncate();
        Soft::truncate();

        parent::tearDown();
    }

    public function testHasMany(): void
    {
        $author = User::create(['name' => 'George R. R. Martin']);
        Book::create(['title' => 'A Game of Thrones', 'author_id' => $author->id]);
        Book::create(['title' => 'A Clash of Kings', 'author_id' => $author->id]);

        $books = $author->books;
        $this->assertCount(2, $books);

        $user = User::create(['name' => 'John Doe']);
        Item::create(['type' => 'knife', 'user_id' => $user->id]);
        Item::create(['type' => 'shield', 'user_id' => $user->id]);
        Item::create(['type' => 'sword', 'user_id' => $user->id]);
        Item::create(['type' => 'bag', 'user_id' => null]);

        $items = $user->items;
        $this->assertCount(3, $items);
    }

    public function testHasManyWithTrashed(): void
    {
        $user   = User::create(['name' => 'George R. R. Martin']);
        $first  = Soft::create(['title' => 'A Game of Thrones', 'user_id' => $user->id]);
        $second = Soft::create(['title' => 'The Witcher', 'user_id' => $user->id]);

        self::assertNull($first->deleted_at);
        self::assertEquals($user->id, $first->user->id);
        self::assertEquals([$first->id, $second->id], $user->softs->pluck('id')->toArray());

        $first->delete();
        $user->refresh();

        self::assertNotNull($first->deleted_at);
        self::assertEquals([$second->id], $user->softs->pluck('id')->toArray());
        self::assertEquals([$first->id, $second->id], $user->softsWithTrashed->pluck('id')->toArray());
    }

    public function testBelongsTo(): void
    {
        $user = User::create(['name' => 'George R. R. Martin']);
        Book::create(['title' => 'A Game of Thrones', 'author_id' => $user->id]);
        $book = Book::create(['title' => 'A Clash of Kings', 'author_id' => $user->id]);

        $author = $book->author;
        $this->assertEquals('George R. R. Martin', $author->name);

        $user = User::create(['name' => 'John Doe']);
        $item = Item::create(['type' => 'sword', 'user_id' => $user->id]);

        $owner = $item->user;
        $this->assertEquals('John Doe', $owner->name);

        $book = Book::create(['title' => 'A Clash of Kings']);
        $this->assertNull($book->author);
    }

    public function testHasOne(): void
    {
        $user = User::create(['name' => 'John Doe']);
        Role::create(['type' => 'admin', 'user_id' => $user->id]);

        $role = $user->role;
        $this->assertEquals('admin', $role->type);
        $this->assertEquals($user->id, $role->user_id);

        $user = User::create(['name' => 'Jane Doe']);
        $role = new Role(['type' => 'user']);
        $user->role()->save($role);

        $role = $user->role;
        $this->assertEquals('user', $role->type);
        $this->assertEquals($user->id, $role->user_id);

        $user = User::where('name', 'Jane Doe')->first();
        $role = $user->role;
        $this->assertEquals('user', $role->type);
        $this->assertEquals($user->id, $role->user_id);
    }

    public function testWithBelongsTo(): void
    {
        $user = User::create(['name' => 'John Doe']);
        Item::create(['type' => 'knife', 'user_id' => $user->id]);
        Item::create(['type' => 'shield', 'user_id' => $user->id]);
        Item::create(['type' => 'sword', 'user_id' => $user->id]);
        Item::create(['type' => 'bag', 'user_id' => null]);

        $items = Item::with('user')->orderBy('user_id', 'desc')->get();

        $user = $items[0]->getRelation('user');
        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('John Doe', $user->name);
        $this->assertCount(1, $items[0]->getRelations());
        $this->assertNull($items[3]->getRelation('user'));
    }

    public function testWithHashMany(): void
    {
        $user = User::create(['name' => 'John Doe']);
        Item::create(['type' => 'knife', 'user_id' => $user->id]);
        Item::create(['type' => 'shield', 'user_id' => $user->id]);
        Item::create(['type' => 'sword', 'user_id' => $user->id]);
        Item::create(['type' => 'bag', 'user_id' => null]);

        $user = User::with('items')->find($user->id);

        $items = $user->getRelation('items');
        $this->assertCount(3, $items);
        $this->assertInstanceOf(Item::class, $items[0]);
    }

    public function testWithHasOne(): void
    {
        $user = User::create(['name' => 'John Doe']);
        Role::create(['type' => 'admin', 'user_id' => $user->id]);
        Role::create(['type' => 'guest', 'user_id' => $user->id]);

        $user = User::with('role')->find($user->id);

        $role = $user->getRelation('role');
        $this->assertInstanceOf(Role::class, $role);
        $this->assertEquals('admin', $role->type);
    }

    public function testEasyRelation(): void
    {
        // Has Many
        $user = User::create(['name' => 'John Doe']);
        $item = Item::create(['type' => 'knife']);
        $user->items()->save($item);

        $user  = User::find($user->id);
        $items = $user->items;
        $this->assertCount(1, $items);
        $this->assertInstanceOf(Item::class, $items[0]);
        $this->assertEquals($user->id, $items[0]->user_id);

        // Has one
        $user = User::create(['name' => 'John Doe']);
        $role = Role::create(['type' => 'admin']);
        $user->role()->save($role);

        $user = User::find($user->id);
        $role = $user->role;
        $this->assertInstanceOf(Role::class, $role);
        $this->assertEquals('admin', $role->type);
        $this->assertEquals($user->id, $role->user_id);
    }

    public function testBelongsToMany(): void
    {
        $user = User::create(['name' => 'John Doe']);

        // Add 2 clients
        $user->clients()->save(new Client(['name' => 'Pork Pies Ltd.']));
        $user->clients()->create(['name' => 'Buffet Bar Inc.']);

        // Refetch
        $user   = User::with('clients')->find($user->id);
        $client = Client::with('users')->first();

        // Check for relation attributes
        $this->assertArrayHasKey('user_ids', $client->getAttributes());
        $this->assertArrayHasKey('client_ids', $user->getAttributes());

        $clients = $user->getRelation('clients');
        $users   = $client->getRelation('users');

        $this->assertInstanceOf(Collection::class, $users);
        $this->assertInstanceOf(Collection::class, $clients);
        $this->assertInstanceOf(Client::class, $clients[0]);
        $this->assertInstanceOf(User::class, $users[0]);
        $this->assertCount(2, $user->clients);
        $this->assertCount(1, $client->users);

        // Now create a new user to an existing client
        $user = $client->users()->create(['name' => 'Jane Doe']);

        $this->assertInstanceOf(Collection::class, $user->clients);
        $this->assertInstanceOf(Client::class, $user->clients->first());
        $this->assertCount(1, $user->clients);

        // Get user and unattached client
        $user   = User::where('name', '=', 'Jane Doe')->first();
        $client = Client::Where('name', '=', 'Buffet Bar Inc.')->first();

        // Check the models are what they should be
        $this->assertInstanceOf(Client::class, $client);
        $this->assertInstanceOf(User::class, $user);

        // Assert they are not attached
        $this->assertNotContains($client->id, $user->client_ids);
        $this->assertNotContains($user->id, $client->user_ids);
        $this->assertCount(1, $user->clients);
        $this->assertCount(1, $client->users);

        // Attach the client to the user
        $user->clients()->attach($client);

        // Get the new user model
        $user   = User::where('name', '=', 'Jane Doe')->first();
        $client = Client::Where('name', '=', 'Buffet Bar Inc.')->first();

        // Assert they are attached
        $this->assertContains($client->id, $user->client_ids);
        $this->assertContains($user->id, $client->user_ids);
        $this->assertCount(2, $user->clients);
        $this->assertCount(2, $client->users);

        // Detach clients from user
        $user->clients()->sync([]);

        // Get the new user model
        $user   = User::where('name', '=', 'Jane Doe')->first();
        $client = Client::Where('name', '=', 'Buffet Bar Inc.')->first();

        // Assert they are not attached
        $this->assertNotContains($client->id, $user->client_ids);
        $this->assertNotContains($user->id, $client->user_ids);
        $this->assertCount(0, $user->clients);
        $this->assertCount(1, $client->users);
    }

    public function testBelongsToManyAttachesExistingModels(): void
    {
        $user = User::create(['name' => 'John Doe', 'client_ids' => ['1234523']]);

        $clients = [
            Client::create(['name' => 'Pork Pies Ltd.'])->id,
            Client::create(['name' => 'Buffet Bar Inc.'])->id,
        ];

        $moreClients = [
            Client::create(['name' => 'synced Boloni Ltd.'])->id,
            Client::create(['name' => 'synced Meatballs Inc.'])->id,
        ];

        // Sync multiple records
        $user->clients()->sync($clients);

        $user = User::with('clients')->find($user->id);

        // Assert non attached ID's are detached successfully
        $this->assertNotContains('1234523', $user->client_ids);

        // Assert there are two client objects in the relationship
        $this->assertCount(2, $user->clients);

        // Add more clients
        $user->clients()->sync($moreClients);

        // Refetch
        $user = User::with('clients')->find($user->id);

        // Assert there are now still 2 client objects in the relationship
        $this->assertCount(2, $user->clients);

        // Assert that the new relationships name start with synced
        $this->assertStringStartsWith('synced', $user->clients[0]->name);
        $this->assertStringStartsWith('synced', $user->clients[1]->name);
    }

    public function testBelongsToManySync(): void
    {
        // create test instances
        $user    = User::create(['name' => 'Hans Thomas']);
        $client1 = Client::create(['name' => 'Pork Pies Ltd.']);
        $client2 = Client::create(['name' => 'Buffet Bar Inc.']);

        // Sync multiple
        $user->clients()->sync([$client1->id, $client2->id]);
        $this->assertCount(2, $user->clients);

        // Sync single wrapped by an array
        $user->clients()->sync([$client1->id]);
        $user->load('clients');

        $this->assertCount(1, $user->clients);
        self::assertTrue($user->clients->first()->is($client1));

        // Sync single model
        $user->clients()->sync($client2);
        $user->load('clients');

        $this->assertCount(1, $user->clients);
        self::assertTrue($user->clients->first()->is($client2));
    }

    public function testBelongsToManyAttachArray(): void
    {
        $user    = User::create(['name' => 'John Doe']);
        $client1 = Client::create(['name' => 'Test 1'])->id;
        $client2 = Client::create(['name' => 'Test 2'])->id;

        $user->clients()->attach([$client1, $client2]);
        $this->assertCount(2, $user->clients);
    }

    public function testBelongsToManyAttachEloquentCollection(): void
    {
        User::create(['name' => 'John Doe']);
        $client1    = Client::create(['name' => 'Test 1']);
        $client2    = Client::create(['name' => 'Test 2']);
        $collection = new Collection([$client1, $client2]);

        $user = User::where('name', '=', 'John Doe')->first();
        $user->clients()->attach($collection);
        $this->assertCount(2, $user->clients);
    }

    public function testBelongsToManySyncWithCustomKeys(): void
    {
        $client = Client::create(['cclient_id' => (string) (new ObjectId()), 'years' => '5']);
        $skill1    = Skill::create(['cskill_id' => (string) (new ObjectId()), 'name' => 'PHP']);
        $skill2    = Skill::create(['cskill_id' => (string) (new ObjectId()), 'name' => 'Laravel']);

        $client = Client::query()->find($client->id);
        $client->skillsWithCustomKeys()->sync([$skill1->cskill_id, $skill2->cskill_id]);
        $this->assertCount(2, $client->skillsWithCustomKeys);

        self::assertIsString($skill1->cskill_id);
        self::assertContains($skill1->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($skill1->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        self::assertIsString($skill2->cskill_id);
        self::assertContains($skill2->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($skill2->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        $check = Skill::query()->find($skill1->id);
        self::assertIsString($check->cskill_id);
        self::assertContains($check->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($check->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        $check = Skill::query()->find($skill2->id);
        self::assertIsString($check->cskill_id);
        self::assertContains($check->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($check->id, $client->skillsWithCustomKeys->pluck('cskill_id'));
    }

    public function testBelongsToManySyncModelWithCustomKeys(): void
    {
        $client = Client::create(['cclient_id' => (string) (new ObjectId()), 'years' => '5']);
        $skill1    = Skill::create(['cskill_id' => (string) (new ObjectId()), 'name' => 'PHP']);

        $client = Client::query()->find($client->id);
        $client->skillsWithCustomKeys()->sync($skill1);
        $this->assertCount(1, $client->skillsWithCustomKeys);

        self::assertIsString($skill1->cskill_id);
        self::assertContains($skill1->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($skill1->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        $check = Skill::query()->find($skill1->id);
        self::assertIsString($check->id);
        self::assertContains($check->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($check->id, $client->skillsWithCustomKeys->pluck('cskill_id'));
    }

    public function testBelongsToManySyncEloquentCollectionWithCustomKeys(): void
    {
        $client = Client::create(['cclient_id' => (string) (new ObjectId()), 'years' => '5']);
        $skill1    = Skill::create(['cskill_id' => (string) (new ObjectId()), 'name' => 'PHP']);
        $skill2    = Skill::create(['cskill_id' => (string) (new ObjectId()), 'name' => 'Laravel']);
        $collection = new Collection([$skill1, $skill2]);

        $client = Client::query()->find($client->id);
        $client->skillsWithCustomKeys()->sync($collection);
        $this->assertCount(2, $client->skillsWithCustomKeys);

        self::assertIsString($skill1->cskill_id);
        self::assertContains($skill1->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($skill1->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        self::assertIsString($skill2->cskill_id);
        self::assertContains($skill2->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($skill2->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        $check = Skill::query()->find($skill1->id);
        self::assertIsString($check->cskill_id);
        self::assertContains($check->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($check->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        $check = Skill::query()->find($skill2->id);
        self::assertIsString($check->cskill_id);
        self::assertContains($check->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($check->id, $client->skillsWithCustomKeys->pluck('cskill_id'));
    }

    public function testBelongsToManyAttachWithCustomKeys(): void
    {
        $client = Client::create(['cclient_id' => (string) (new ObjectId()), 'years' => '5']);
        $skill1    = Skill::create(['cskill_id' => (string) (new ObjectId()), 'name' => 'PHP']);
        $skill2    = Skill::create(['cskill_id' => (string) (new ObjectId()), 'name' => 'Laravel']);

        $client = Client::query()->find($client->id);
        $client->skillsWithCustomKeys()->attach([$skill1->cskill_id, $skill2->cskill_id]);
        $this->assertCount(2, $client->skillsWithCustomKeys);

        self::assertIsString($skill1->cskill_id);
        self::assertContains($skill1->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($skill1->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        self::assertIsString($skill2->cskill_id);
        self::assertContains($skill2->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($skill2->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        $check = Skill::query()->find($skill1->id);
        self::assertIsString($check->cskill_id);
        self::assertContains($check->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($check->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        $check = Skill::query()->find($skill2->id);
        self::assertIsString($check->cskill_id);
        self::assertContains($check->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($check->id, $client->skillsWithCustomKeys->pluck('cskill_id'));
    }

    public function testBelongsToManyAttachModelWithCustomKeys(): void
    {
        $client = Client::create(['cclient_id' => (string) (new ObjectId()), 'years' => '5']);
        $skill1    = Skill::create(['cskill_id' => (string) (new ObjectId()), 'name' => 'PHP']);

        $client = Client::query()->find($client->id);
        $client->skillsWithCustomKeys()->attach($skill1);
        $this->assertCount(1, $client->skillsWithCustomKeys);

        self::assertIsString($skill1->cskill_id);
        self::assertContains($skill1->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($skill1->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        $check = Skill::query()->find($skill1->id);
        self::assertIsString($check->id);
        self::assertContains($check->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($check->id, $client->skillsWithCustomKeys->pluck('cskill_id'));
    }

    public function testBelongsToManyAttachEloquentCollectionWithCustomKeys(): void
    {
        $client = Client::create(['cclient_id' => (string) (new ObjectId()), 'years' => '5']);
        $skill1    = Skill::create(['cskill_id' => (string) (new ObjectId()), 'name' => 'PHP']);
        $skill2    = Skill::create(['cskill_id' => (string) (new ObjectId()), 'name' => 'Laravel']);
        $collection = new Collection([$skill1, $skill2]);

        $client = Client::query()->find($client->id);
        $client->skillsWithCustomKeys()->attach($collection);
        $this->assertCount(2, $client->skillsWithCustomKeys);

        self::assertIsString($skill1->cskill_id);
        self::assertContains($skill1->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($skill1->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        self::assertIsString($skill2->cskill_id);
        self::assertContains($skill2->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($skill2->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        $check = Skill::query()->find($skill1->id);
        self::assertIsString($check->cskill_id);
        self::assertContains($check->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($check->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        $check = Skill::query()->find($skill2->id);
        self::assertIsString($check->cskill_id);
        self::assertContains($check->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($check->id, $client->skillsWithCustomKeys->pluck('cskill_id'));
    }

    public function testBelongsToManyDetachWithCustomKeys(): void
    {
        $client = Client::create(['cclient_id' => (string) (new ObjectId()), 'years' => '5']);
        $skill1    = Skill::create(['cskill_id' => (string) (new ObjectId()), 'name' => 'PHP']);
        $skill2    = Skill::create(['cskill_id' => (string) (new ObjectId()), 'name' => 'Laravel']);

        $client = Client::query()->find($client->id);
        $client->skillsWithCustomKeys()->sync([$skill1->cskill_id, $skill2->cskill_id]);
        $this->assertCount(2, $client->skillsWithCustomKeys);

        $client->skillsWithCustomKeys()->detach($skill1->cskill_id);
        $client->load('skillsWithCustomKeys'); // Reload the relationship based on the latest pivot column's data
        $this->assertCount(1, $client->skillsWithCustomKeys);

        self::assertIsString($skill1->cskill_id);
        self::assertNotContains($skill1->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($skill1->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        self::assertIsString($skill2->cskill_id);
        self::assertContains($skill2->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($skill2->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        $check = Skill::query()->find($skill1->id);
        self::assertIsString($check->cskill_id);
        self::assertNotContains($check->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($check->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        $check = Skill::query()->find($skill2->id);
        self::assertIsString($check->cskill_id);
        self::assertContains($check->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($check->id, $client->skillsWithCustomKeys->pluck('cskill_id'));
    }

    public function testBelongsToManyDetachModelWithCustomKeys(): void
    {
        $client = Client::create(['cclient_id' => (string) (new ObjectId()), 'years' => '5']);
        $skill1    = Skill::create(['cskill_id' => (string) (new ObjectId()), 'name' => 'PHP']);
        $skill2    = Skill::create(['cskill_id' => (string) (new ObjectId()), 'name' => 'Laravel']);

        $client = Client::query()->find($client->id);
        $client->skillsWithCustomKeys()->sync([$skill1->cskill_id, $skill2->cskill_id]);
        $this->assertCount(2, $client->skillsWithCustomKeys);

        $client->skillsWithCustomKeys()->detach($skill1);
        $client->load('skillsWithCustomKeys'); // Reload the relationship based on the latest pivot column's data
        $this->assertCount(1, $client->skillsWithCustomKeys);

        self::assertIsString($skill1->cskill_id);
        self::assertNotContains($skill1->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($skill1->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        self::assertIsString($skill2->cskill_id);
        self::assertContains($skill2->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($skill2->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        $check = Skill::query()->find($skill1->id);
        self::assertIsString($check->cskill_id);
        self::assertNotContains($check->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($check->id, $client->skillsWithCustomKeys->pluck('cskill_id'));

        $check = Skill::query()->find($skill2->id);
        self::assertIsString($check->cskill_id);
        self::assertContains($check->cskill_id, $client->skillsWithCustomKeys->pluck('cskill_id'));
        self::assertNotContains($check->id, $client->skillsWithCustomKeys->pluck('cskill_id'));
    }

    public function testBelongsToManySyncAlreadyPresent(): void
    {
        $user    = User::create(['name' => 'John Doe']);
        $client1 = Client::create(['name' => 'Test 1'])->id;
        $client2 = Client::create(['name' => 'Test 2'])->id;

        $user->clients()->sync([$client1, $client2]);
        $this->assertCount(2, $user->clients);

        $user = User::where('name', '=', 'John Doe')->first();
        $user->clients()->sync([$client1]);
        $this->assertCount(1, $user->clients);

        $user = User::where('name', '=', 'John Doe')->first()->toArray();
        $this->assertCount(1, $user['client_ids']);
    }

    public function testBelongsToManyCustom(): void
    {
        $user  = User::create(['name' => 'John Doe']);
        $group = $user->groups()->create(['name' => 'Admins']);

        // Refetch
        $user  = User::find($user->id);
        $group = Group::find($group->id);

        // Check for custom relation attributes
        $this->assertArrayHasKey('users', $group->getAttributes());
        $this->assertArrayHasKey('groups', $user->getAttributes());

        // Assert they are attached
        $this->assertContains($group->id, $user->groups->pluck('id')->toArray());
        $this->assertContains($user->id, $group->users->pluck('id')->toArray());
        $this->assertEquals($group->id, $user->groups()->first()->id);
        $this->assertEquals($user->id, $group->users()->first()->id);
    }

    public function testMorph(): void
    {
        $user   = User::create(['name' => 'John Doe']);
        $client = Client::create(['name' => 'Jane Doe']);

        $photo = Photo::create(['url' => 'http://graph.facebook.com/john.doe/picture']);
        $photo = $user->photos()->save($photo);

        $this->assertEquals(1, $user->photos->count());
        $this->assertEquals($photo->id, $user->photos->first()->id);

        $user = User::find($user->id);
        $this->assertEquals(1, $user->photos->count());
        $this->assertEquals($photo->id, $user->photos->first()->id);

        $photo = Photo::create(['url' => 'http://graph.facebook.com/jane.doe/picture']);
        $client->photo()->save($photo);

        $this->assertNotNull($client->photo);
        $this->assertEquals($photo->id, $client->photo->id);

        $client = Client::find($client->id);
        $this->assertNotNull($client->photo);
        $this->assertEquals($photo->id, $client->photo->id);

        $photo = Photo::first();
        $this->assertEquals($photo->hasImage->name, $user->name);

        // eager load
        $user      = User::with('photos')->find($user->id);
        $relations = $user->getRelations();
        $this->assertArrayHasKey('photos', $relations);
        $this->assertEquals(1, $relations['photos']->count());

        // inverse eager load
        $photos    = Photo::with('hasImage')->get();
        $relations = $photos[0]->getRelations();
        $this->assertArrayHasKey('hasImage', $relations);
        $this->assertInstanceOf(User::class, $photos[0]->hasImage);

        $relations = $photos[1]->getRelations();
        $this->assertArrayHasKey('hasImage', $relations);
        $this->assertInstanceOf(Client::class, $photos[1]->hasImage);

        // inverse relationship
        $photo = Photo::query()->create(['url' => 'https://graph.facebook.com/hans.thomas/picture']);
        $client = Client::create(['name' => 'Hans Thomas']);
        $photo->hasImage()->associate($client)->save();

        $this->assertCount(1, $photo->hasImage()->get());
        $this->assertInstanceOf(Client::class, $photo->hasImage);
        $this->assertEquals($client->id, $photo->hasImage->id);

        // inverse with custom ownerKey
        $photo = Photo::query()->create(['url' => 'https://graph.facebook.com/young.gerald/picture']);
        $client = Client::create(['cclient_id' => (string) (new ObjectId()), 'name' => 'Young Gerald']);
        $photo->hasImageWithCustomOwnerKey()->associate($client)->save();

        $this->assertCount(1, $photo->hasImageWithCustomOwnerKey()->get());
        $this->assertInstanceOf(Client::class, $photo->hasImageWithCustomOwnerKey);
        $this->assertEquals($client->cclient_id, $photo->has_image_with_custom_owner_key_id);
        $this->assertEquals($client->id, $photo->hasImageWithCustomOwnerKey->id);

        // inverse eager load with custom ownerKey
        $photos    = Photo::with('hasImageWithCustomOwnerKey')->get();
        $check = $photos->last();
        $relations = $check->getRelations();
        $this->assertArrayHasKey('hasImageWithCustomOwnerKey', $relations);
        $this->assertInstanceOf(Client::class, $check->hasImageWithCustomOwnerKey);
    }

    public function testMorphToMany(): void
    {
        $user = User::query()->create(['name' => 'Young Gerald']);
        $client = Client::query()->create(['name' => 'Hans Thomas']);

        $label  = Label::query()->create(['name' => 'Had the world in my palms, I gave it to you']);

        $user->labels()->attach($label);
        $client->labels()->attach($label);

        $this->assertEquals(1, $user->labels->count());
        $this->assertContains($label->id, $user->labels->pluck('id'));

        $this->assertEquals(1, $client->labels->count());
        $this->assertContains($label->id, $user->labels->pluck('id'));
    }

    public function testMorphToManyAttachEloquentCollection(): void
    {
        $client = Client::query()->create(['name' => 'Young Gerald']);

        $label1  = Label::query()->create(['name' => "Make no mistake, it's the life that I was chosen for"]);
        $label2  = Label::query()->create(['name' => 'All I prayed for was an open door']);

        $client->labels()->attach(new Collection([$label1, $label2]));

        $this->assertEquals(2, $client->labels->count());
        $this->assertContains($label1->id, $client->labels->pluck('id'));
        $this->assertContains($label2->id, $client->labels->pluck('id'));
    }

    public function testMorphToManyAttachMultipleIds(): void
    {
        $client = Client::query()->create(['name' => 'Young Gerald']);

        $label1  = Label::query()->create(['name' => 'stayed solid i never fled']);
        $label2  = Label::query()->create(['name' => "I've got a lane and I'm in gear"]);

        $client->labels()->attach([$label1->id, $label2->id]);

        $this->assertEquals(2, $client->labels->count());
        $this->assertContains($label1->id, $client->labels->pluck('id'));
        $this->assertContains($label2->id, $client->labels->pluck('id'));
    }

    public function testMorphToManyDetaching(): void
    {
        $client = Client::query()->create(['name' => 'Marshall Mathers']);

        $label1  = Label::query()->create(['name' => "I'll never love again"]);
        $label2  = Label::query()->create(['name' => 'The way I loved you']);

        $client->labels()->attach([$label1->id, $label2->id]);

        $this->assertEquals(2, $client->labels->count());

        $client->labels()->detach($label1);
        $check = $client->withoutRelations();

        $this->assertEquals(1, $check->labels->count());
        $this->assertContains($label2->id, $client->labels->pluck('id'));
    }

    public function testMorphToManyDetachingMultipleIds(): void
    {
        $client = Client::query()->create(['name' => 'Young Gerald']);

        $label1  = Label::query()->create(['name' => "I make what I wanna make, but I won't make everyone happy"]);
        $label2  = Label::query()->create(['name' => "My skin's thick, but I'm not bulletproof"]);
        $label3  = Label::query()->create(['name' => 'All I can be is myself, go, and tell the truth']);

        $client->labels()->attach([$label1->id, $label2->id, $label3->id]);

        $this->assertEquals(3, $client->labels->count());

        $client->labels()->detach([$label1->id, $label2->id]);
        $client->refresh();

        $this->assertEquals(1, $client->labels->count());
        $this->assertContains($label3->id, $client->labels->pluck('id'));
    }

    public function testMorphToManySyncing(): void
    {
        $user = User::query()->create(['name' => 'Young Gerald']);
        $client = Client::query()->create(['name' => 'Hans Thomas']);

        $label  = Label::query()->create(['name' => "Lesson learned, we weren't the perfect match"]);
        $label2  = Label::query()->create(['name' => 'Future ref, not keeping personal and work attached']);

        $user->labels()->sync($label);
        $client->labels()->sync($label);
        $client->labels()->sync($label2, false);

        $this->assertEquals(1, $user->labels->count());
        $this->assertContains($label->id, $user->labels->pluck('id'));
        $this->assertNotContains($label2->id, $user->labels->pluck('id'));

        $this->assertEquals(2, $client->labels->count());
        $this->assertContains($label->id, $client->labels->pluck('id'));
        $this->assertContains($label2->id, $client->labels->pluck('id'));
    }

    public function testMorphToManySyncingEloquentCollection(): void
    {
        $client = Client::query()->create(['name' => 'Young Gerald']);

        $label  = Label::query()->create(['name' => 'Why the ones who love me most, the people I push away?']);
        $label2  = Label::query()->create(['name' => 'Look in a mirror, this is you']);

        $client->labels()->sync(new Collection([$label, $label2]));

        $this->assertEquals(2, $client->labels->count());
        $this->assertContains($label->id, $client->labels->pluck('id'));
        $this->assertContains($label2->id, $client->labels->pluck('id'));
    }

    public function testMorphToManySyncingMultipleIds(): void
    {
        $client = Client::query()->create(['name' => 'Young Gerald']);

        $label  = Label::query()->create(['name' => 'They all talk about karma, how it slowly comes']);
        $label2  = Label::query()->create(['name' => "But life is short, enjoy it while you're young"]);

        $client->labels()->sync([$label->id, $label2->id]);

        $this->assertEquals(2, $client->labels->count());
        $this->assertContains($label->id, $client->labels->pluck('id'));
        $this->assertContains($label2->id, $client->labels->pluck('id'));
    }

    public function testMorphToManySyncingWithCustomKeys(): void
    {
        $client = Client::query()->create(['cclient_id' => (string) (new ObjectId()), 'name' => 'Young Gerald']);

        $label  = Label::query()->create(['clabel_id' => (string) (new ObjectId()), 'name' => "Why do people do things that be bad for 'em?"]);
        $label2  = Label::query()->create(['clabel_id' => (string) (new ObjectId()), 'name' => "Say we done with these things, then we ask for 'em"]);

        $client->labelsWithCustomKeys()->sync([$label->clabel_id, $label2->clabel_id]);

        $this->assertEquals(2, $client->labelsWithCustomKeys->count());
        $this->assertContains($label->id, $client->labelsWithCustomKeys->pluck('id'));
        $this->assertContains($label2->id, $client->labelsWithCustomKeys->pluck('id'));

        $client->labelsWithCustomKeys()->sync($label);
        $client->load('labelsWithCustomKeys');

        $this->assertEquals(1, $client->labelsWithCustomKeys->count());
        $this->assertContains($label->id, $client->labelsWithCustomKeys->pluck('id'));
        $this->assertNotContains($label2->id, $client->labelsWithCustomKeys->pluck('id'));
    }

    public function testMorphToManyLoadAndRefreshing(): void
    {
        $user = User::query()->create(['name' => 'The Pretty Reckless']);

        $client = Client::query()->create(['name' => 'Young Gerald']);

        $label  = Label::query()->create(['name' => 'The greatest gift is knowledge itself']);
        $label2  = Label::query()->create(['name' => "I made it here all by my lonely, no askin' for help"]);

        $client->labels()->sync([$label->id, $label2->id]);
        $client->users()->sync($user);

        $this->assertEquals(2, $client->labels->count());

        $client->load('labels');

        $this->assertEquals(2, $client->labels->count());

        $client->refresh();

        $this->assertEquals(2, $client->labels->count());

        $check = Client::query()->find($client->id);

        $this->assertEquals(2, $check->labels->count());

        $check = Client::query()->with('labels')->find($client->id);

        $this->assertEquals(2, $check->labels->count());
    }

    public function testMorphToManyHasQuery(): void
    {
        $client = Client::query()->create(['name' => 'Ashley']);
        $client2 = Client::query()->create(['name' => 'Halsey']);
        $client3 = Client::query()->create(['name' => 'John Doe 2']);

        $label  = Label::query()->create(['name' => "I've been digging myself down deeper"]);
        $label2  = Label::query()->create(['name' => "I won't stop 'til I get where you are"]);

        $client->labels()->sync([$label->id, $label2->id]);
        $client2->labels()->sync($label);

        $this->assertEquals(2, $client->labels->count());
        $this->assertEquals(1, $client2->labels->count());

        $check = Client::query()->has('labels')->get();
        $this->assertCount(2, $check);

        $check = Client::query()->has('labels', '>', 1)->get();
        $this->assertCount(1, $check);
        $this->assertContains($client->id, $check->pluck('id'));

        $check = Client::query()->has('labels', '<', 2)->get();
        $this->assertCount(2, $check);
        $this->assertContains($client2->id, $check->pluck('id'));
        $this->assertContains($client3->id, $check->pluck('id'));
    }

    public function testMorphedByMany(): void
    {
        $user = User::query()->create(['name' => 'Young Gerald']);
        $client = Client::query()->create(['name' => 'Hans Thomas']);
        $extra = Client::query()->create(['name' => 'John Doe']);

        $label  = Label::query()->create(['name' => 'Never finished, tryna search for more']);

        $label->users()->attach($user);
        $label->clients()->attach($client);

        $this->assertEquals(1, $label->users->count());
        $this->assertContains($user->id, $label->users->pluck('id'));

        $this->assertEquals(1, $label->clients->count());
        $this->assertContains($client->id, $label->clients->pluck('id'));
    }

    public function testMorphedByManyAttachEloquentCollection(): void
    {
        $client1 = Client::query()->create(['name' => 'Young Gerald']);
        $client2 = Client::query()->create(['name' => 'Hans Thomas']);
        $extra = Client::query()->create(['name' => 'John Doe']);

        $label  = Label::query()->create(['name' => 'They want me to architect Rome, in a day']);

        $label->clients()->attach(new Collection([$client1, $client2]));

        $this->assertEquals(2, $label->clients->count());
        $this->assertContains($client1->id, $label->clients->pluck('id'));
        $this->assertContains($client2->id, $label->clients->pluck('id'));

        $client1->refresh();
        $this->assertEquals(1, $client1->labels->count());
    }

    public function testMorphedByManyAttachMultipleIds(): void
    {
        $client1 = Client::query()->create(['name' => 'Austin Richard Post']);
        $client2 = Client::query()->create(['name' => 'Hans Thomas']);
        $extra = Client::query()->create(['name' => 'John Doe']);

        $label  = Label::query()->create(['name' => 'Always in the game and never played by the rules']);

        $label->clients()->attach([$client1->id, $client2->id]);

        $this->assertEquals(2, $label->clients->count());
        $this->assertContains($client1->id, $label->clients->pluck('id'));
        $this->assertContains($client2->id, $label->clients->pluck('id'));

        $client1->refresh();
        $this->assertEquals(1, $client1->labels->count());
    }

    public function testMorphedByManyDetaching(): void
    {
        $client1 = Client::query()->create(['name' => 'Austin Richard Post']);
        $client2 = Client::query()->create(['name' => 'Hans Thomas']);
        $extra = Client::query()->create(['name' => 'John Doe']);

        $label  = Label::query()->create(['name' => 'Seasons change and our love went cold']);

        $label->clients()->attach([$client1->id, $client2->id]);

        $this->assertEquals(2, $label->clients->count());

        $label->clients()->detach($client1->id);
        $check = $label->withoutRelations();

        $this->assertEquals(1, $check->clients->count());
        $this->assertContains($client2->id, $check->clients->pluck('id'));
    }

    public function testMorphedByManyDetachingMultipleIds(): void
    {
        $client1 = Client::query()->create(['name' => 'Austin Richard Post']);
        $client2 = Client::query()->create(['name' => 'Hans Thomas']);
        $client3 = Client::query()->create(['name' => 'John Doe']);

        $label  = Label::query()->create(['name' => "Run away, but we're running in circles"]);

        $label->clients()->attach([$client1->id, $client2->id, $client3->id]);

        $this->assertEquals(3, $label->clients->count());

        $label->clients()->detach([$client1->id, $client2->id]);
        $label->load('clients');

        $this->assertEquals(1, $label->clients->count());
        $this->assertContains($client3->id, $label->clients->pluck('id'));
    }

    public function testMorphedByManySyncing(): void
    {
        $client1 = Client::query()->create(['name' => 'Austin Richard Post']);
        $client2 = Client::query()->create(['name' => 'Hans Thomas']);
        $client3 = Client::query()->create(['name' => 'John Doe']);

        $label  = Label::query()->create(['name' => "Was scared of losin' somethin' that we never found"]);

        $label->clients()->sync($client1);
        $label->clients()->sync($client2, false);
        $label->clients()->sync($client3, false);

        $this->assertEquals(3, $label->clients->count());
        $this->assertContains($client1->id, $label->clients->pluck('id'));
        $this->assertContains($client2->id, $label->clients->pluck('id'));
        $this->assertContains($client3->id, $label->clients->pluck('id'));
    }

    public function testMorphedByManySyncingEloquentCollection(): void
    {
        $client1 = Client::query()->create(['name' => 'Austin Richard Post']);
        $client2 = Client::query()->create(['name' => 'Hans Thomas']);
        $extra = Client::query()->create(['name' => 'John Doe']);

        $label  = Label::query()->create(['name' => "I'm goin' hard 'til I'm gone. Can you feel it?"]);

        $label->clients()->sync(new Collection([$client1, $client2]));

        $this->assertEquals(2, $label->clients->count());
        $this->assertContains($client1->id, $label->clients->pluck('id'));
        $this->assertContains($client2->id, $label->clients->pluck('id'));

        $this->assertNotContains($extra->id, $label->clients->pluck('id'));
    }

    public function testMorphedByManySyncingMultipleIds(): void
    {
        $client1 = Client::query()->create(['name' => 'Dorothy']);
        $client2 = Client::query()->create(['name' => 'Hans Thomas']);
        $extra = Client::query()->create(['name' => 'John Doe']);

        $label  = Label::query()->create(['name' => "Love ain't patient, it's not kind. true love waits to rob you blind"]);

        $label->clients()->sync([$client1->id, $client2->id]);

        $this->assertEquals(2, $label->clients->count());
        $this->assertContains($client1->id, $label->clients->pluck('id'));
        $this->assertContains($client2->id, $label->clients->pluck('id'));

        $this->assertNotContains($extra->id, $label->clients->pluck('id'));
    }

    public function testMorphedByManySyncingWithCustomKeys(): void
    {
        $client1 = Client::query()->create(['cclient_id' => (string) (new ObjectId()), 'name' => 'Young Gerald']);
        $client2 = Client::query()->create(['cclient_id' => (string) (new ObjectId()), 'name' => 'Hans Thomas']);
        $client3 = Client::query()->create(['cclient_id' => (string) (new ObjectId()), 'name' => 'John Doe']);

        $label  = Label::query()->create(['clabel_id' => (string) (new ObjectId()), 'name' => "I'm in my own lane, so what do I have to hurry for?"]);

        $label->clientsWithCustomKeys()->sync([$client1->cclient_id, $client2->cclient_id]);

        $this->assertEquals(2, $label->clientsWithCustomKeys->count());
        $this->assertContains($client1->id, $label->clientsWithCustomKeys->pluck('id'));
        $this->assertContains($client2->id, $label->clientsWithCustomKeys->pluck('id'));

        $this->assertNotContains($client3->id, $label->clientsWithCustomKeys->pluck('id'));

        $label->clientsWithCustomKeys()->sync($client3);
        $label->load('clientsWithCustomKeys');

        $this->assertEquals(1, $label->clientsWithCustomKeys->count());
        $this->assertNotContains($client1->id, $label->clientsWithCustomKeys->pluck('id'));
        $this->assertNotContains($client2->id, $label->clientsWithCustomKeys->pluck('id'));

        $this->assertContains($client3->id, $label->clientsWithCustomKeys->pluck('id'));
    }

    public function testMorphedByManyLoadAndRefreshing(): void
    {
        $user = User::query()->create(['name' => 'Abel Tesfaye']);

        $client1 = Client::query()->create(['name' => 'Young Gerald']);
        $client2 = Client::query()->create(['name' => 'Hans Thomas']);
        $client3 = Client::query()->create(['name' => 'John Doe']);

        $label  = Label::query()->create(['name' => "but don't think I don't think about you just cause I ain't spoken about you"]);

        $label->clients()->sync(new Collection([$client1, $client2, $client3]));
        $label->users()->sync($user);

        $this->assertEquals(3, $label->clients->count());

        $label->load('clients');

        $this->assertEquals(3, $label->clients->count());

        $label->refresh();

        $this->assertEquals(3, $label->clients->count());

        $check = Label::query()->find($label->id);

        $this->assertEquals(3, $check->clients->count());

        $check = Label::query()->with('clients')->find($label->id);

        $this->assertEquals(3, $check->clients->count());
    }

    public function testMorphedByManyHasQuery(): void
    {
        $user = User::query()->create(['name' => 'Austin Richard Post']);

        $client1 = Client::query()->create(['name' => 'Young Gerald']);
        $client2 = Client::query()->create(['name' => 'John Doe']);

        $label  = Label::query()->create(['name' => "My star's back shining bright, I just polished it"]);
        $label2  = Label::query()->create(['name' => "Somethin' in my spirit woke back up like I just sat up"]);
        $label3  = Label::query()->create(['name' => 'How can I beam when you blocking my light?']);

        $label->clients()->sync(new Collection([$client1, $client2]));
        $label2->clients()->sync($client1);
        $label3->users()->sync($user);

        $this->assertEquals(2, $label->clients->count());

        $check = Label::query()->has('clients')->get();
        $this->assertCount(2, $check);
        $this->assertContains($label->id, $check->pluck('id'));
        $this->assertContains($label2->id, $check->pluck('id'));

        $check = Label::query()->has('users')->get();
        $this->assertCount(1, $check);
        $this->assertContains($label3->id, $check->pluck('id'));

        $check = Label::query()->has('clients', '>', 1)->get();
        $this->assertCount(1, $check);
        $this->assertContains($label->id, $check->pluck('id'));
    }

    public function testHasManyHas(): void
    {
        $author1 = User::create(['name' => 'George R. R. Martin']);
        $author1->books()->create(['title' => 'A Game of Thrones', 'rating' => 5]);
        $author1->books()->create(['title' => 'A Clash of Kings', 'rating' => 5]);
        $author2 = User::create(['name' => 'John Doe']);
        $author2->books()->create(['title' => 'My book', 'rating' => 2]);
        User::create(['name' => 'Anonymous author']);
        Book::create(['title' => 'Anonymous book', 'rating' => 1]);

        $authors = User::has('books')->get();
        $this->assertCount(2, $authors);
        $this->assertEquals('George R. R. Martin', $authors[0]->name);
        $this->assertEquals('John Doe', $authors[1]->name);

        $authors = User::has('books', '>', 1)->get();
        $this->assertCount(1, $authors);

        $authors = User::has('books', '<', 5)->get();
        $this->assertCount(3, $authors);

        $authors = User::has('books', '>=', 2)->get();
        $this->assertCount(1, $authors);

        $authors = User::has('books', '<=', 1)->get();
        $this->assertCount(2, $authors);

        $authors = User::has('books', '=', 2)->get();
        $this->assertCount(1, $authors);

        $authors = User::has('books', '!=', 2)->get();
        $this->assertCount(2, $authors);

        $authors = User::has('books', '=', 0)->get();
        $this->assertCount(1, $authors);

        $authors = User::has('books', '!=', 0)->get();
        $this->assertCount(2, $authors);

        $authors = User::whereHas('books', function ($query) {
            $query->where('rating', 5);
        })->get();
        $this->assertCount(1, $authors);

        $authors = User::whereHas('books', function ($query) {
            $query->where('rating', '<', 5);
        })->get();
        $this->assertCount(1, $authors);
    }

    public function testHasOneHas(): void
    {
        $user1 = User::create(['name' => 'John Doe']);
        $user1->role()->create(['title' => 'admin']);
        $user2 = User::create(['name' => 'Jane Doe']);
        $user2->role()->create(['title' => 'reseller']);
        User::create(['name' => 'Mark Moe']);
        Role::create(['title' => 'Customer']);

        $users = User::has('role')->get();

        $this->assertCount(2, $users);
        $this->assertEquals('John Doe', $users[0]->name);
        $this->assertEquals('Jane Doe', $users[1]->name);

        $users = User::has('role', '=', 0)->get();
        $this->assertCount(1, $users);

        $users = User::has('role', '!=', 0)->get();
        $this->assertCount(2, $users);
    }

    public function testNestedKeys(): void
    {
        $client = Client::create([
            'data' => [
                'client_id' => 35298,
                'name' => 'John Doe',
            ],
        ]);

        $client->addresses()->create([
            'data' => [
                'address_id' => 1432,
                'city' => 'Paris',
            ],
        ]);

        $client = Client::where('data.client_id', 35298)->first();
        $this->assertEquals(1, $client->addresses->count());

        $address = $client->addresses->first();
        $this->assertEquals('Paris', $address->data['city']);

        $client = Client::with('addresses')->first();
        $this->assertEquals('Paris', $client->addresses->first()->data['city']);
    }

    public function testDoubleSaveOneToMany(): void
    {
        $author = User::create(['name' => 'George R. R. Martin']);
        $book   = Book::create(['title' => 'A Game of Thrones']);

        $author->books()->save($book);
        $author->books()->save($book);
        $author->save();
        $this->assertEquals(1, $author->books()->count());
        $this->assertEquals($author->id, $book->author_id);

        $author = User::where('name', 'George R. R. Martin')->first();
        $book   = Book::where('title', 'A Game of Thrones')->first();
        $this->assertEquals(1, $author->books()->count());
        $this->assertEquals($author->id, $book->author_id);

        $author->books()->save($book);
        $author->books()->save($book);
        $author->save();
        $this->assertEquals(1, $author->books()->count());
        $this->assertEquals($author->id, $book->author_id);
    }

    public function testDoubleSaveManyToMany(): void
    {
        $user   = User::create(['name' => 'John Doe']);
        $client = Client::create(['name' => 'Admins']);

        $user->clients()->save($client);
        $user->clients()->save($client);
        $user->save();

        $this->assertEquals(1, $user->clients()->count());
        $this->assertEquals([$user->id], $client->user_ids);
        $this->assertEquals([$client->id], $user->client_ids);

        $user   = User::where('name', 'John Doe')->first();
        $client = Client::where('name', 'Admins')->first();
        $this->assertEquals(1, $user->clients()->count());
        $this->assertEquals([$user->id], $client->user_ids);
        $this->assertEquals([$client->id], $user->client_ids);

        $user->clients()->save($client);
        $user->clients()->save($client);
        $user->save();
        $this->assertEquals(1, $user->clients()->count());
        $this->assertEquals([$user->id], $client->user_ids);
        $this->assertEquals([$client->id], $user->client_ids);
    }

    public function testWhereBelongsTo()
    {
        $user = User::create(['name' => 'John Doe']);
        Item::create(['user_id' => $user->id]);
        Item::create(['user_id' => $user->id]);
        Item::create(['user_id' => $user->id]);
        Item::create(['user_id' => null]);

        $items = Item::whereBelongsTo($user)->get();

        $this->assertCount(3, $items);
    }
}
