<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests;

use Illuminate\Database\Eloquent\Collection;
use Mockery;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Tests\Models\Address;
use MongoDB\Laravel\Tests\Models\Book;
use MongoDB\Laravel\Tests\Models\Client;
use MongoDB\Laravel\Tests\Models\Experience;
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
        Experience::truncate();
    }

    public function testHasMany(): void
    {
        $author = User::create(['name' => 'George R. R. Martin']);
        Book::create(['title' => 'A Game of Thrones', 'author_id' => $author->_id]);
        Book::create(['title' => 'A Clash of Kings', 'author_id' => $author->_id]);

        $books = $author->books;
        $this->assertCount(2, $books);

        $user = User::create(['name' => 'John Doe']);
        Item::create(['type' => 'knife', 'user_id' => $user->_id]);
        Item::create(['type' => 'shield', 'user_id' => $user->_id]);
        Item::create(['type' => 'sword', 'user_id' => $user->_id]);
        Item::create(['type' => 'bag', 'user_id' => null]);

        $items = $user->items;
        $this->assertCount(3, $items);
    }

    public function testHasManyWithTrashed(): void
    {
        $user   = User::create(['name' => 'George R. R. Martin']);
        $first  = Soft::create(['title' => 'A Game of Thrones', 'user_id' => $user->_id]);
        $second = Soft::create(['title' => 'The Witcher', 'user_id' => $user->_id]);

        self::assertNull($first->deleted_at);
        self::assertEquals($user->_id, $first->user->_id);
        self::assertEquals([$first->_id, $second->_id], $user->softs->pluck('_id')->toArray());

        $first->delete();
        $user->refresh();

        self::assertNotNull($first->deleted_at);
        self::assertEquals([$second->_id], $user->softs->pluck('_id')->toArray());
        self::assertEquals([$first->_id, $second->_id], $user->softsWithTrashed->pluck('_id')->toArray());
    }

    public function testBelongsTo(): void
    {
        $user = User::create(['name' => 'George R. R. Martin']);
        Book::create(['title' => 'A Game of Thrones', 'author_id' => $user->_id]);
        $book = Book::create(['title' => 'A Clash of Kings', 'author_id' => $user->_id]);

        $author = $book->author;
        $this->assertEquals('George R. R. Martin', $author->name);

        $user = User::create(['name' => 'John Doe']);
        $item = Item::create(['type' => 'sword', 'user_id' => $user->_id]);

        $owner = $item->user;
        $this->assertEquals('John Doe', $owner->name);

        $book = Book::create(['title' => 'A Clash of Kings']);
        $this->assertNull($book->author);
    }

    public function testHasOne(): void
    {
        $user = User::create(['name' => 'John Doe']);
        Role::create(['type' => 'admin', 'user_id' => $user->_id]);

        $role = $user->role;
        $this->assertEquals('admin', $role->type);
        $this->assertEquals($user->_id, $role->user_id);

        $user = User::create(['name' => 'Jane Doe']);
        $role = new Role(['type' => 'user']);
        $user->role()->save($role);

        $role = $user->role;
        $this->assertEquals('user', $role->type);
        $this->assertEquals($user->_id, $role->user_id);

        $user = User::where('name', 'Jane Doe')->first();
        $role = $user->role;
        $this->assertEquals('user', $role->type);
        $this->assertEquals($user->_id, $role->user_id);
    }

    public function testWithBelongsTo(): void
    {
        $user = User::create(['name' => 'John Doe']);
        Item::create(['type' => 'knife', 'user_id' => $user->_id]);
        Item::create(['type' => 'shield', 'user_id' => $user->_id]);
        Item::create(['type' => 'sword', 'user_id' => $user->_id]);
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
        Item::create(['type' => 'knife', 'user_id' => $user->_id]);
        Item::create(['type' => 'shield', 'user_id' => $user->_id]);
        Item::create(['type' => 'sword', 'user_id' => $user->_id]);
        Item::create(['type' => 'bag', 'user_id' => null]);

        $user = User::with('items')->find($user->_id);

        $items = $user->getRelation('items');
        $this->assertCount(3, $items);
        $this->assertInstanceOf(Item::class, $items[0]);
    }

    public function testWithHasOne(): void
    {
        $user = User::create(['name' => 'John Doe']);
        Role::create(['type' => 'admin', 'user_id' => $user->_id]);
        Role::create(['type' => 'guest', 'user_id' => $user->_id]);

        $user = User::with('role')->find($user->_id);

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

        $user  = User::find($user->_id);
        $items = $user->items;
        $this->assertCount(1, $items);
        $this->assertInstanceOf(Item::class, $items[0]);
        $this->assertEquals($user->_id, $items[0]->user_id);

        // Has one
        $user = User::create(['name' => 'John Doe']);
        $role = Role::create(['type' => 'admin']);
        $user->role()->save($role);

        $user = User::find($user->_id);
        $role = $user->role;
        $this->assertInstanceOf(Role::class, $role);
        $this->assertEquals('admin', $role->type);
        $this->assertEquals($user->_id, $role->user_id);
    }

    public function testBelongsToMany(): void
    {
        $user = User::create(['name' => 'John Doe']);

        // Add 2 clients
        $user->clients()->save(new Client(['name' => 'Pork Pies Ltd.']));
        $user->clients()->create(['name' => 'Buffet Bar Inc.']);

        // Refetch
        $user   = User::with('clients')->find($user->_id);
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
        $this->assertNotContains($client->_id, $user->client_ids);
        $this->assertNotContains($user->_id, $client->user_ids);
        $this->assertCount(1, $user->clients);
        $this->assertCount(1, $client->users);

        // Attach the client to the user
        $user->clients()->attach($client);

        // Get the new user model
        $user   = User::where('name', '=', 'Jane Doe')->first();
        $client = Client::Where('name', '=', 'Buffet Bar Inc.')->first();

        // Assert they are attached
        $this->assertContains($client->_id, $user->client_ids);
        $this->assertContains($user->_id, $client->user_ids);
        $this->assertCount(2, $user->clients);
        $this->assertCount(2, $client->users);

        // Detach clients from user
        $user->clients()->sync([]);

        // Get the new user model
        $user   = User::where('name', '=', 'Jane Doe')->first();
        $client = Client::Where('name', '=', 'Buffet Bar Inc.')->first();

        // Assert they are not attached
        $this->assertNotContains($client->_id, $user->client_ids);
        $this->assertNotContains($user->_id, $client->user_ids);
        $this->assertCount(0, $user->clients);
        $this->assertCount(1, $client->users);
    }

    public function testBelongsToManyAttachesExistingModels(): void
    {
        $user = User::create(['name' => 'John Doe', 'client_ids' => ['1234523']]);

        $clients = [
            Client::create(['name' => 'Pork Pies Ltd.'])->_id,
            Client::create(['name' => 'Buffet Bar Inc.'])->_id,
        ];

        $moreClients = [
            Client::create(['name' => 'synced Boloni Ltd.'])->_id,
            Client::create(['name' => 'synced Meatballs Inc.'])->_id,
        ];

        // Sync multiple records
        $user->clients()->sync($clients);

        $user = User::with('clients')->find($user->_id);

        // Assert non attached ID's are detached succesfully
        $this->assertNotContains('1234523', $user->client_ids);

        // Assert there are two client objects in the relationship
        $this->assertCount(2, $user->clients);

        // Add more clients
        $user->clients()->sync($moreClients);

        // Refetch
        $user = User::with('clients')->find($user->_id);

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
        $user->clients()->sync([$client1->_id, $client2->_id]);
        $this->assertCount(2, $user->clients);

        // Sync single wrapped by an array
        $user->clients()->sync([$client1->_id]);
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
        $client1 = Client::create(['name' => 'Test 1'])->_id;
        $client2 = Client::create(['name' => 'Test 2'])->_id;

        $user = User::where('name', '=', 'John Doe')->first();
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

    public function testBelongsToManySyncEloquentCollectionWithCustomRelatedKey(): void
    {
        $experience = Experience::create(['years' => '5']);
        $skill1    = Skill::create(['cskill_id' => (string) (new ObjectId()), 'name' => 'PHP']);
        $skill2    = Skill::create(['cskill_id' => (string) (new ObjectId()), 'name' => 'Laravel']);
        $collection = new Collection([$skill1, $skill2]);

        $experience = Experience::query()->find($experience->id);
        $experience->skillsWithCustomRelatedKey()->sync($collection);
        $this->assertCount(2, $experience->skillsWithCustomRelatedKey);

        self::assertIsString($skill1->cskill_id);
        self::assertContains($skill1->cskill_id, $experience->skillsWithCustomRelatedKey->pluck('cskill_id'));

        self::assertIsString($skill2->cskill_id);
        self::assertContains($skill2->cskill_id, $experience->skillsWithCustomRelatedKey->pluck('cskill_id'));

        $skill1->refresh();
        self::assertIsString($skill1->_id);
        self::assertNotContains($skill1->_id, $experience->skillsWithCustomRelatedKey->pluck('cskill_id'));

        $skill2->refresh();
        self::assertIsString($skill2->_id);
        self::assertNotContains($skill2->_id, $experience->skillsWithCustomRelatedKey->pluck('cskill_id'));
    }

    public function testBelongsToManySyncEloquentCollectionWithCustomParentKey(): void
    {
        $experience = Experience::create(['cexperience_id' => (string) (new ObjectId()), 'years' => '5']);
        $skill1    = Skill::create(['name' => 'PHP']);
        $skill2    = Skill::create(['name' => 'Laravel']);
        $collection = new Collection([$skill1, $skill2]);

        $experience = Experience::query()->find($experience->id);
        $experience->skillsWithCustomParentKey()->sync($collection);
        $this->assertCount(2, $experience->skillsWithCustomParentKey);

        self::assertIsString($skill1->_id);
        self::assertContains($skill1->_id, $experience->skillsWithCustomParentKey->pluck('_id'));

        self::assertIsString($skill2->_id);
        self::assertContains($skill2->_id, $experience->skillsWithCustomParentKey->pluck('_id'));
    }

    public function testBelongsToManySyncAlreadyPresent(): void
    {
        $user    = User::create(['name' => 'John Doe']);
        $client1 = Client::create(['name' => 'Test 1'])->_id;
        $client2 = Client::create(['name' => 'Test 2'])->_id;

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
        $user  = User::find($user->_id);
        $group = Group::find($group->_id);

        // Check for custom relation attributes
        $this->assertArrayHasKey('users', $group->getAttributes());
        $this->assertArrayHasKey('groups', $user->getAttributes());

        // Assert they are attached
        $this->assertContains($group->_id, $user->groups->pluck('_id')->toArray());
        $this->assertContains($user->_id, $group->users->pluck('_id')->toArray());
        $this->assertEquals($group->_id, $user->groups()->first()->_id);
        $this->assertEquals($user->_id, $group->users()->first()->_id);
    }

    public function testMorph(): void
    {
        $user   = User::create(['name' => 'John Doe']);
        $client = Client::create(['name' => 'Jane Doe']);

        $photo = Photo::create(['url' => 'http://graph.facebook.com/john.doe/picture']);
        $photo = $user->photos()->save($photo);

        $this->assertEquals(1, $user->photos->count());
        $this->assertEquals($photo->id, $user->photos->first()->id);

        $user = User::find($user->_id);
        $this->assertEquals(1, $user->photos->count());
        $this->assertEquals($photo->id, $user->photos->first()->id);

        $photo = Photo::create(['url' => 'http://graph.facebook.com/jane.doe/picture']);
        $client->photo()->save($photo);

        $this->assertNotNull($client->photo);
        $this->assertEquals($photo->id, $client->photo->id);

        $client = Client::find($client->_id);
        $this->assertNotNull($client->photo);
        $this->assertEquals($photo->id, $client->photo->id);

        $photo = Photo::first();
        $this->assertEquals($photo->hasImage->name, $user->name);

        $user      = User::with('photos')->find($user->_id);
        $relations = $user->getRelations();
        $this->assertArrayHasKey('photos', $relations);
        $this->assertEquals(1, $relations['photos']->count());

        $photos    = Photo::with('hasImage')->get();
        $relations = $photos[0]->getRelations();
        $this->assertArrayHasKey('hasImage', $relations);
        $this->assertInstanceOf(User::class, $photos[0]->hasImage);

        $relations = $photos[1]->getRelations();
        $this->assertArrayHasKey('hasImage', $relations);
        $this->assertInstanceOf(Client::class, $photos[1]->hasImage);

        // inverse
        $photo = Photo::query()->create(['url' => 'https://graph.facebook.com/hans.thomas/picture']);
        $client = Client::create(['name' => 'Hans Thomas']);
        $photo->hasImage()->associate($client)->save();

        $this->assertCount(1, $photo->hasImage()->get());
        $this->assertInstanceOf(Client::class, $photo->hasImage);
        $this->assertEquals($client->_id, $photo->hasImage->_id);

        // inverse with custom ownerKey
        $photo = Photo::query()->create(['url' => 'https://graph.facebook.com/young.gerald/picture']);
        $client = Client::create(['cclient_id' => (string) (new ObjectId()), 'name' => 'Young Gerald']);
        $photo->hasImageWithCustomOwnerKey()->associate($client)->save();

        $this->assertCount(1, $photo->hasImageWithCustomOwnerKey()->get());
        $this->assertInstanceOf(Client::class, $photo->hasImageWithCustomOwnerKey);
        $this->assertEquals($client->cclient_id, $photo->has_image_with_custom_owner_key_id);
        $this->assertEquals($client->_id, $photo->hasImageWithCustomOwnerKey->_id);
    }

    public function testMorphToMany(): void
    {
        $user = User::query()->create(['name' => 'John Doe']);
        $client = Client::query()->create(['name' => 'Hans Thomas']);

        $label  = Label::query()->create(['name' => 'My test label']);

        $user->labels()->attach($label);
        $client->labels()->attach($label);

        $this->assertEquals(1, $user->labels->count());
        $this->assertContains($label->_id, $user->labels->pluck('_id'));

        $this->assertEquals(1, $client->labels->count());
        $this->assertContains($label->_id, $user->labels->pluck('_id'));
    }

    public function testMorphToManyAttachEloquentCollection(): void
    {
        $client = Client::query()->create(['name' => 'Young Gerald']);

        $label1  = Label::query()->create(['name' => "Make no mistake, it's the life that I was chosen for"]);
        $label2  = Label::query()->create(['name' => 'All I prayed for was an open door']);

        $client->labels()->attach(new Collection([$label1, $label2]));

        $this->assertEquals(2, $client->labels->count());
        $this->assertContains($label1->_id, $client->labels->pluck('_id'));
        $this->assertContains($label2->_id, $client->labels->pluck('_id'));
    }

    public function testMorphToManyAttachMultipleIds(): void
    {
        $client = Client::query()->create(['name' => 'Young Gerald']);

        $label1  = Label::query()->create(['name' => "Make no mistake, it's the life that I was chosen for"]);
        $label2  = Label::query()->create(['name' => 'All I prayed for was an open door']);

        $client->labels()->attach([$label1->_id, $label2->_id]);

        $this->assertEquals(2, $client->labels->count());
        $this->assertContains($label1->_id, $client->labels->pluck('_id'));
        $this->assertContains($label2->_id, $client->labels->pluck('_id'));
    }

    public function testMorphToManyDetaching(): void
    {
        $client = Client::query()->create(['name' => 'Young Gerald']);

        $label1  = Label::query()->create(['name' => "Make no mistake, it's the life that I was chosen for"]);
        $label2  = Label::query()->create(['name' => 'All I prayed for was an open door']);

        $client->labels()->attach([$label1->_id, $label2->_id]);

        $this->assertEquals(2, $client->labels->count());

        $client->labels()->detach($label1);
        $check = $client->withoutRelations();

        $this->assertEquals(1, $check->labels->count());
        $this->assertContains($label2->_id, $client->labels->pluck('_id'));
    }

    public function testMorphToManyDetachingMultipleIds(): void
    {
        $client = Client::query()->create(['name' => 'Young Gerald']);

        $label1  = Label::query()->create(['name' => "Make no mistake, it's the life that I was chosen for"]);
        $label2  = Label::query()->create(['name' => 'All I prayed for was an open door']);
        $label3  = Label::query()->create(['name' => 'If it was easy, everyone would do it']);

        $client->labels()->attach([$label1->_id, $label2->_id, $label3->_id]);

        $this->assertEquals(3, $client->labels->count());

        $client->labels()->detach([$label1->_id, $label2->_id]);
        $check = $client->withoutRelations();

        $this->assertEquals(1, $check->labels->count());
        $this->assertContains($label3->_id, $client->labels->pluck('_id'));
    }

    public function testMorphToManySyncing(): void
    {
        $user = User::query()->create(['name' => 'John Doe']);
        $client = Client::query()->create(['name' => 'Hans Thomas']);

        $label  = Label::query()->create(['name' => 'My test label']);
        $label2  = Label::query()->create(['name' => 'My test label 2']);

        $user->labels()->sync($label);
        $client->labels()->sync($label);
        $client->labels()->sync($label2, false);

        $this->assertEquals(1, $user->labels->count());
        $this->assertContains($label->_id, $user->labels->pluck('_id'));
        $this->assertNotContains($label2->_id, $user->labels->pluck('_id'));

        $this->assertEquals(2, $client->labels->count());
        $this->assertContains($label->_id, $client->labels->pluck('_id'));
        $this->assertContains($label2->_id, $client->labels->pluck('_id'));
    }

    public function testMorphToManySyncingEloquentCollection(): void
    {
        $client = Client::query()->create(['name' => 'Hans Thomas']);

        $label  = Label::query()->create(['name' => 'My test label']);
        $label2  = Label::query()->create(['name' => 'My test label 2']);

        $client->labels()->sync(new Collection([$label, $label2]));

        $this->assertEquals(2, $client->labels->count());
        $this->assertContains($label->_id, $client->labels->pluck('_id'));
        $this->assertContains($label2->_id, $client->labels->pluck('_id'));
    }

    public function testMorphToManySyncingMultipleIds(): void
    {
        $client = Client::query()->create(['name' => 'Hans Thomas']);

        $label  = Label::query()->create(['name' => 'My test label']);
        $label2  = Label::query()->create(['name' => 'My test label 2']);

        $client->labels()->sync([$label->_id, $label2->_id]);

        $this->assertEquals(2, $client->labels->count());
        $this->assertContains($label->_id, $client->labels->pluck('_id'));
        $this->assertContains($label2->_id, $client->labels->pluck('_id'));
    }

    public function testMorphedByMany(): void
    {
        $user = User::query()->create(['name' => 'John Doe']);
        $client = Client::query()->create(['name' => 'Hans Thomas']);

        $label  = Label::query()->create(['name' => 'My test label']);

        $label->users()->attach($user);
        $label->clients()->attach($client);

        $this->assertEquals(1, $label->users->count());
        $this->assertContains($user->_id, $label->users->pluck('_id'));

        $this->assertEquals(1, $label->clients->count());
        $this->assertContains($client->_id, $label->clients->pluck('_id'));
    }

    public function testMorphedByManyAttachEloquentCollection(): void
    {
        $client1 = Client::query()->create(['name' => 'Young Gerald']);
        $client2 = Client::query()->create(['name' => 'Hans Thomas']);
        $extra = Client::query()->create(['name' => 'one more client']);

        $label  = Label::query()->create(['name' => 'They want me to architect Rome, in a day']);

        $label->clients()->attach(new Collection([$client1, $client2]));

        $this->assertEquals(2, $label->clients->count());
        $this->assertContains($client1->_id, $label->clients->pluck('_id'));
        $this->assertContains($client2->_id, $label->clients->pluck('_id'));

        $client1->refresh();
        $this->assertEquals(1, $client1->labels->count());
    }

    public function testMorphedByManyAttachMultipleIds(): void
    {
        $client1 = Client::query()->create(['name' => 'Young Gerald']);
        $client2 = Client::query()->create(['name' => 'Hans Thomas']);
        $extra = Client::query()->create(['name' => 'one more client']);

        $label  = Label::query()->create(['name' => 'They want me to architect Rome, in a day']);

        $label->clients()->attach([$client1->_id, $client2->_id]);

        $this->assertEquals(2, $label->clients->count());
        $this->assertContains($client1->_id, $label->clients->pluck('_id'));
        $this->assertContains($client2->_id, $label->clients->pluck('_id'));

        $client1->refresh();
        $this->assertEquals(1, $client1->labels->count());
    }

    public function testMorphedByManyDetaching(): void
    {
        $client1 = Client::query()->create(['name' => 'Young Gerald']);
        $client2 = Client::query()->create(['name' => 'Hans Thomas']);
        $extra = Client::query()->create(['name' => 'one more client']);

        $label  = Label::query()->create(['name' => 'They want me to architect Rome, in a day']);

        $label->clients()->attach([$client1->_id, $client2->_id]);

        $this->assertEquals(2, $label->clients->count());

        $label->clients()->detach($client1->_id);
        $check = $label->withoutRelations();

        $this->assertEquals(1, $check->clients->count());
        $this->assertContains($client2->_id, $check->clients->pluck('_id'));
    }

    public function testMorphedByManyDetachingMultipleIds(): void
    {
        $client1 = Client::query()->create(['name' => 'Young Gerald']);
        $client2 = Client::query()->create(['name' => 'Hans Thomas']);
        $client3 = Client::query()->create(['name' => 'Austin Richard Post']);

        $label  = Label::query()->create(['name' => 'They want me to architect Rome, in a day']);

        $label->clients()->attach([$client1->_id, $client2->_id, $client3->_id]);

        $this->assertEquals(3, $label->clients->count());

        $label->clients()->detach([$client1->_id, $client2->_id]);
        $check = $label->withoutRelations();

        $this->assertEquals(1, $check->clients->count());
        $this->assertContains($client3->_id, $check->clients->pluck('_id'));
    }

    public function testMorphedByManySyncing(): void
    {
        $client1 = Client::query()->create(['name' => 'Young Gerald']);
        $client2 = Client::query()->create(['name' => 'Hans Thomas']);
        $client3 = Client::query()->create(['name' => 'Austin Richard Post']);

        $label  = Label::query()->create(['name' => 'My test label']);

        $label->clients()->sync($client1);
        $label->clients()->sync($client2, false);
        $label->clients()->sync($client3, false);

        $this->assertEquals(3, $label->clients->count());
        $this->assertContains($client1->_id, $label->clients->pluck('_id'));
        $this->assertContains($client2->_id, $label->clients->pluck('_id'));
        $this->assertContains($client3->_id, $label->clients->pluck('_id'));
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
        $this->assertEquals($author->_id, $book->author_id);

        $author = User::where('name', 'George R. R. Martin')->first();
        $book   = Book::where('title', 'A Game of Thrones')->first();
        $this->assertEquals(1, $author->books()->count());
        $this->assertEquals($author->_id, $book->author_id);

        $author->books()->save($book);
        $author->books()->save($book);
        $author->save();
        $this->assertEquals(1, $author->books()->count());
        $this->assertEquals($author->_id, $book->author_id);
    }

    public function testDoubleSaveManyToMany(): void
    {
        $user   = User::create(['name' => 'John Doe']);
        $client = Client::create(['name' => 'Admins']);

        $user->clients()->save($client);
        $user->clients()->save($client);
        $user->save();

        $this->assertEquals(1, $user->clients()->count());
        $this->assertEquals([$user->_id], $client->user_ids);
        $this->assertEquals([$client->_id], $user->client_ids);

        $user   = User::where('name', 'John Doe')->first();
        $client = Client::where('name', 'Admins')->first();
        $this->assertEquals(1, $user->clients()->count());
        $this->assertEquals([$user->_id], $client->user_ids);
        $this->assertEquals([$client->_id], $user->client_ids);

        $user->clients()->save($client);
        $user->clients()->save($client);
        $user->save();
        $this->assertEquals(1, $user->clients()->count());
        $this->assertEquals([$user->_id], $client->user_ids);
        $this->assertEquals([$client->_id], $user->client_ids);
    }

    public function testWhereBelongsTo()
    {
        $user = User::create(['name' => 'John Doe']);
        Item::create(['user_id' => $user->_id]);
        Item::create(['user_id' => $user->_id]);
        Item::create(['user_id' => $user->_id]);
        Item::create(['user_id' => null]);

        $items = Item::whereBelongsTo($user)->get();

        $this->assertCount(3, $items);
    }
}
