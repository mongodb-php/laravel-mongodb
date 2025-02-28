<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests;

use DateTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Events\Dispatcher;
use Mockery;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Tests\Models\Address;
use MongoDB\Laravel\Tests\Models\User;

use function array_merge;

class EmbeddedRelationsTest extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();
        User::truncate();

        parent::tearDown();
    }

    public function testEmbedsManySave()
    {
        $user    = User::create(['name' => 'John Doe']);
        $address = new Address(['city' => 'London']);

        $address->setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('dispatch')->with('eloquent.retrieved: ' . $address::class, Mockery::any());
        $events->shouldReceive('until')
            ->once()
            ->with('eloquent.saving: ' . $address::class, $address)
            ->andReturn(true);
        $events->shouldReceive('until')
            ->once()
            ->with('eloquent.creating: ' . $address::class, $address)
            ->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with('eloquent.created: ' . $address::class, $address);
        $events->shouldReceive('dispatch')->once()->with('eloquent.saved: ' . $address::class, $address);

        $address = $user->addresses()->save($address);
        $address->unsetEventDispatcher();

        $this->assertNotNull($user->addresses);
        $this->assertInstanceOf(Collection::class, $user->addresses);
        $this->assertEquals(['London'], $user->addresses->pluck('city')->all());
        $this->assertInstanceOf(DateTime::class, $address->created_at);
        $this->assertInstanceOf(DateTime::class, $address->updated_at);
        $this->assertNotNull($address->id);
        $this->assertIsString($address->id);

        $raw = $address->getAttributes();
        $this->assertInstanceOf(ObjectId::class, $raw['id']);

        $address = $user->addresses()->save(new Address(['city' => 'Paris']));

        $user = User::find($user->id);
        $this->assertEquals(['London', 'Paris'], $user->addresses->pluck('city')->all());

        $address->setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('dispatch')->with('eloquent.retrieved: ' . $address::class, Mockery::any());
        $events->shouldReceive('until')
            ->once()
            ->with('eloquent.saving: ' . $address::class, $address)
            ->andReturn(true);
        $events->shouldReceive('until')
            ->once()
            ->with('eloquent.updating: ' . $address::class, $address)
            ->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with('eloquent.updated: ' . $address::class, $address);
        $events->shouldReceive('dispatch')->once()->with('eloquent.saved: ' . $address::class, $address);

        $address->city = 'New York';
        $user->addresses()->save($address);
        $address->unsetEventDispatcher();

        $this->assertCount(2, $user->addresses);
        $this->assertCount(2, $user->addresses()->get());
        $this->assertEquals(2, $user->addresses->count());
        $this->assertEquals(2, $user->addresses()->count());
        $this->assertEquals(['London', 'New York'], $user->addresses->pluck('city')->all());

        $freshUser = User::find($user->id);
        $this->assertEquals(['London', 'New York'], $freshUser->addresses->pluck('city')->all());

        $address = $user->addresses->first();
        $this->assertEquals('London', $address->city);
        $this->assertInstanceOf(DateTime::class, $address->created_at);
        $this->assertInstanceOf(DateTime::class, $address->updated_at);
        $this->assertInstanceOf(User::class, $address->user);
        $this->assertEmpty($address->relationsToArray()); // prevent infinite loop

        $user = User::find($user->id);
        $user->addresses()->save(new Address(['city' => 'Bruxelles']));
        $this->assertEquals(['London', 'New York', 'Bruxelles'], $user->addresses->pluck('city')->all());

        $address       = $user->addresses[1];
        $address->city = 'Manhattan';
        $user->addresses()->save($address);
        $this->assertEquals(['London', 'Manhattan', 'Bruxelles'], $user->addresses->pluck('city')->all());

        $freshUser = User::find($user->id);
        $this->assertEquals(['London', 'Manhattan', 'Bruxelles'], $freshUser->addresses->pluck('city')->all());
    }

    public function testEmbedsToArray()
    {
        $user = User::create(['name' => 'John Doe']);
        $user->addresses()->saveMany([new Address(['city' => 'London']), new Address(['city' => 'Bristol'])]);

        $array = $user->toArray();
        $this->assertArrayNotHasKey('_addresses', $array);
        $this->assertArrayHasKey('addresses', $array);
    }

    public function testEmbedsManyAssociate()
    {
        $user    = User::create(['name' => 'John Doe']);
        $address = new Address(['city' => 'London']);

        $user->addresses()->associate($address);
        $this->assertEquals(['London'], $user->addresses->pluck('city')->all());
        $this->assertNotNull($address->id);

        $freshUser = User::find($user->id);
        $this->assertEquals([], $freshUser->addresses->pluck('city')->all());

        $address->city = 'Londinium';
        $user->addresses()->associate($address);
        $this->assertEquals(['Londinium'], $user->addresses->pluck('city')->all());

        $freshUser = User::find($user->id);
        $this->assertEquals([], $freshUser->addresses->pluck('city')->all());
    }

    public function testEmbedsManySaveMany()
    {
        $user = User::create(['name' => 'John Doe']);
        $user->addresses()->saveMany([new Address(['city' => 'London']), new Address(['city' => 'Bristol'])]);
        $this->assertEquals(['London', 'Bristol'], $user->addresses->pluck('city')->all());

        $freshUser = User::find($user->id);
        $this->assertEquals(['London', 'Bristol'], $freshUser->addresses->pluck('city')->all());
    }

    public function testEmbedsManyDuplicate()
    {
        $user    = User::create(['name' => 'John Doe']);
        $address = new Address(['city' => 'London']);
        $user->addresses()->save($address);
        $user->addresses()->save($address);
        $this->assertEquals(1, $user->addresses->count());
        $this->assertEquals(['London'], $user->addresses->pluck('city')->all());

        $user = User::find($user->id);
        $this->assertEquals(1, $user->addresses->count());

        $address->city = 'Paris';
        $user->addresses()->save($address);
        $this->assertEquals(1, $user->addresses->count());
        $this->assertEquals(['Paris'], $user->addresses->pluck('city')->all());

        $user->addresses()->create(['id' => $address->id, 'city' => 'Bruxelles']);
        $this->assertEquals(1, $user->addresses->count());
        $this->assertEquals(['Bruxelles'], $user->addresses->pluck('city')->all());
    }

    public function testEmbedsManyCreate()
    {
        $user    = User::create([]);
        $address = $user->addresses()->create(['city' => 'Bruxelles']);
        $this->assertInstanceOf(Address::class, $address);
        $this->assertIsString($address->id);
        $this->assertEquals(['Bruxelles'], $user->addresses->pluck('city')->all());

        $raw = $address->getAttributes();
        $this->assertInstanceOf(ObjectId::class, $raw['id']);

        $freshUser = User::find($user->id);
        $this->assertEquals(['Bruxelles'], $freshUser->addresses->pluck('city')->all());

        $user    = User::create([]);
        $address = $user->addresses()->create(['id' => '', 'city' => 'Bruxelles']);
        $this->assertIsString($address->id);

        $raw = $address->getAttributes();
        $this->assertInstanceOf(ObjectId::class, $raw['id']);
    }

    public function testEmbedsManyCreateMany()
    {
        $user                = User::create([]);
        [$bruxelles, $paris] = $user->addresses()->createMany([['city' => 'Bruxelles'], ['city' => 'Paris']]);
        $this->assertInstanceOf(Address::class, $bruxelles);
        $this->assertEquals('Bruxelles', $bruxelles->city);
        $this->assertEquals(['Bruxelles', 'Paris'], $user->addresses->pluck('city')->all());

        $freshUser = User::find($user->id);
        $this->assertEquals(['Bruxelles', 'Paris'], $freshUser->addresses->pluck('city')->all());
    }

    public function testEmbedsManyDestroy()
    {
        $user = User::create(['name' => 'John Doe']);
        $user->addresses()->saveMany([
            new Address(['city' => 'London']),
            new Address(['city' => 'Bristol']),
            new Address(['city' => 'Bruxelles']),
        ]);

        $address = $user->addresses->first();

        $address->setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('dispatch')->with('eloquent.retrieved: ' . $address::class, Mockery::any());
        $events->shouldReceive('until')
            ->once()
            ->with('eloquent.deleting: ' . $address::class, Mockery::type(Address::class))
            ->andReturn(true);
        $events->shouldReceive('dispatch')
            ->once()
            ->with('eloquent.deleted: ' . $address::class, Mockery::type(Address::class));

        $user->addresses()->destroy($address->id);
        $this->assertEquals(['Bristol', 'Bruxelles'], $user->addresses->pluck('city')->all());

        $address->unsetEventDispatcher();

        $address = $user->addresses->first();
        $user->addresses()->destroy($address);
        $this->assertEquals(['Bruxelles'], $user->addresses->pluck('city')->all());

        $user->addresses()->create(['city' => 'Paris']);
        $user->addresses()->create(['city' => 'San Francisco']);

        $freshUser = User::find($user->id);
        $this->assertEquals(['Bruxelles', 'Paris', 'San Francisco'], $freshUser->addresses->pluck('city')->all());

        $ids = $user->addresses->pluck('id');
        $user->addresses()->destroy($ids);
        $this->assertEquals([], $user->addresses->pluck('city')->all());

        $freshUser = User::find($user->id);
        $this->assertEquals([], $freshUser->addresses->pluck('city')->all());

        [$london, $bristol, $bruxelles] = $user->addresses()->saveMany([
            new Address(['city' => 'London']),
            new Address(['city' => 'Bristol']),
            new Address(['city' => 'Bruxelles']),
        ]);
        $user->addresses()->destroy([$london, $bruxelles]);
        $this->assertEquals(['Bristol'], $user->addresses->pluck('city')->all());
    }

    public function testEmbedsManyDelete()
    {
        $user = User::create(['name' => 'John Doe']);
        $user->addresses()->saveMany([
            new Address(['city' => 'London']),
            new Address(['city' => 'Bristol']),
            new Address(['city' => 'Bruxelles']),
        ]);

        $address = $user->addresses->first();

        $address->setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('dispatch')->with('eloquent.retrieved: ' . $address::class, Mockery::any());
        $events->shouldReceive('until')
            ->once()
            ->with('eloquent.deleting: ' . $address::class, Mockery::type(Address::class))
            ->andReturn(true);
        $events->shouldReceive('dispatch')
            ->once()
            ->with('eloquent.deleted: ' . $address::class, Mockery::type(Address::class));

        $address->delete();

        $this->assertEquals(2, $user->addresses()->count());
        $this->assertEquals(2, $user->addresses->count());

        $address->unsetEventDispatcher();

        $address = $user->addresses->first();
        $address->delete();

        $user = User::where('name', 'John Doe')->first();
        $this->assertEquals(1, $user->addresses()->count());
        $this->assertEquals(1, $user->addresses->count());
    }

    public function testEmbedsManyDissociate()
    {
        $user    = User::create([]);
        $cordoba = $user->addresses()->create(['city' => 'Cordoba']);

        $user->addresses()->dissociate($cordoba->id);

        $freshUser = User::find($user->id);
        $this->assertEquals(0, $user->addresses->count());
        $this->assertEquals(1, $freshUser->addresses->count());

        $brokenAddress = Address::make(['name' => 'Broken']);

        $user->update([
            'addresses' => array_merge(
                [$brokenAddress->toArray()],
                $user->addresses()->toArray(),
            ),
        ]);

        $curitiba = $user->addresses()->create(['city' => 'Curitiba']);
        $user->addresses()->dissociate($curitiba->id);

        $this->assertEquals(1, $user->addresses->where('name', $brokenAddress->name)->count());
        $this->assertEquals(1, $user->addresses->count());
    }

    public function testEmbedsManyAliases()
    {
        $user    = User::create(['name' => 'John Doe']);
        $address = new Address(['city' => 'London']);

        $address = $user->addresses()->attach($address);
        $this->assertEquals(['London'], $user->addresses->pluck('city')->all());

        $user->addresses()->detach($address);
        $this->assertEquals([], $user->addresses->pluck('city')->all());
    }

    public function testEmbedsManyCreatingEventReturnsFalse()
    {
        $user    = User::create(['name' => 'John Doe']);
        $address = new Address(['city' => 'London']);

        $address->setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('dispatch')->with('eloquent.retrieved: ' . $address::class, Mockery::any());
        $events->shouldReceive('until')
            ->once()
            ->with('eloquent.saving: ' . $address::class, $address)
            ->andReturn(true);
        $events->shouldReceive('until')
            ->once()
            ->with('eloquent.creating: ' . $address::class, $address)
            ->andReturn(false);

        $this->assertFalse($user->addresses()->save($address));
        $address->unsetEventDispatcher();
    }

    public function testEmbedsManySavingEventReturnsFalse()
    {
        $user            = User::create(['name' => 'John Doe']);
        $address         = new Address(['city' => 'Paris']);
        $address->exists = true;

        $address->setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('dispatch')->with('eloquent.retrieved: ' . $address::class, Mockery::any());
        $events->shouldReceive('until')
            ->once()
            ->with('eloquent.saving: ' . $address::class, $address)
            ->andReturn(false);

        $this->assertFalse($user->addresses()->save($address));
        $address->unsetEventDispatcher();
    }

    public function testEmbedsManyUpdatingEventReturnsFalse()
    {
        $user    = User::create(['name' => 'John Doe']);
        $address = new Address(['city' => 'New York']);
        $user->addresses()->save($address);

        $address->setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('dispatch')->with('eloquent.retrieved: ' . $address::class, Mockery::any());
        $events->shouldReceive('until')
            ->once()
            ->with('eloquent.saving: ' . $address::class, $address)
            ->andReturn(true);
        $events->shouldReceive('until')
            ->once()
            ->with('eloquent.updating: ' . $address::class, $address)
            ->andReturn(false);

        $address->city = 'Warsaw';

        $this->assertFalse($user->addresses()->save($address));
        $address->unsetEventDispatcher();
    }

    public function testEmbedsManyDeletingEventReturnsFalse()
    {
        $user = User::create(['name' => 'John Doe']);
        $user->addresses()->save(new Address(['city' => 'New York']));

        $address = $user->addresses->first();

        $address->setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('dispatch')->with('eloquent.retrieved: ' . $address::class, Mockery::any());
        $events->shouldReceive('until')
            ->once()
            ->with('eloquent.deleting: ' . $address::class, Mockery::mustBe($address))
            ->andReturn(false);

        $this->assertEquals(0, $user->addresses()->destroy($address));
        $this->assertEquals(['New York'], $user->addresses->pluck('city')->all());

        $address->unsetEventDispatcher();
    }

    public function testEmbedsManyFindOrContains()
    {
        $user     = User::create(['name' => 'John Doe']);
        $address1 = $user->addresses()->save(new Address(['city' => 'New York']));
        $address2 = $user->addresses()->save(new Address(['city' => 'Paris']));

        $address = $user->addresses()->find($address1->id);
        $this->assertEquals($address->city, $address1->city);

        $address = $user->addresses()->find($address2->id);
        $this->assertEquals($address->city, $address2->city);

        $this->assertTrue($user->addresses()->contains($address2->id));
        $this->assertFalse($user->addresses()->contains('123'));
    }

    public function testEmbedsManyEagerLoading()
    {
        $user1 = User::create(['name' => 'John Doe']);
        $user1->addresses()->save(new Address(['city' => 'New York']));
        $user1->addresses()->save(new Address(['city' => 'Paris']));

        $user2 = User::create(['name' => 'Jane Doe']);
        $user2->addresses()->save(new Address(['city' => 'Berlin']));
        $user2->addresses()->save(new Address(['city' => 'Paris']));

        $user      = User::find($user1->id);
        $relations = $user->getRelations();
        $this->assertArrayNotHasKey('addresses', $relations);
        $this->assertArrayHasKey('addresses', $user->toArray());
        $this->assertIsArray($user->toArray()['addresses']);

        $user      = User::with('addresses')->get()->first();
        $relations = $user->getRelations();
        $this->assertArrayHasKey('addresses', $relations);
        $this->assertEquals(2, $relations['addresses']->count());
        $this->assertArrayHasKey('addresses', $user->toArray());
        $this->assertIsArray($user->toArray()['addresses']);
    }

    public function testEmbedsManyDeleteAll()
    {
        $user1 = User::create(['name' => 'John Doe']);
        $user1->addresses()->save(new Address(['city' => 'New York']));
        $user1->addresses()->save(new Address(['city' => 'Paris']));

        $user2 = User::create(['name' => 'Jane Doe']);
        $user2->addresses()->save(new Address(['city' => 'Berlin']));
        $user2->addresses()->save(new Address(['city' => 'Paris']));

        $user1->addresses()->delete();
        $this->assertEquals(0, $user1->addresses()->count());
        $this->assertEquals(0, $user1->addresses->count());
        $this->assertEquals(2, $user2->addresses()->count());
        $this->assertEquals(2, $user2->addresses->count());

        $user1 = User::find($user1->id);
        $user2 = User::find($user2->id);
        $this->assertEquals(0, $user1->addresses()->count());
        $this->assertEquals(0, $user1->addresses->count());
        $this->assertEquals(2, $user2->addresses()->count());
        $this->assertEquals(2, $user2->addresses->count());
    }

    public function testEmbedsManyCollectionMethods()
    {
        $user = User::create(['name' => 'John Doe']);
        $user->addresses()->save(new Address([
            'city' => 'Paris',
            'country' => 'France',
            'visited' => 4,
            'created_at' => new DateTime('3 days ago'),
        ]));
        $user->addresses()->save(new Address([
            'city' => 'Bruges',
            'country' => 'Belgium',
            'visited' => 7,
            'created_at' => new DateTime('5 days ago'),
        ]));
        $user->addresses()->save(new Address([
            'city' => 'Brussels',
            'country' => 'Belgium',
            'visited' => 2,
            'created_at' => new DateTime('4 days ago'),
        ]));
        $user->addresses()->save(new Address([
            'city' => 'Ghent',
            'country' => 'Belgium',
            'visited' => 13,
            'created_at' => new DateTime('2 days ago'),
        ]));

        $this->assertEquals(['Paris', 'Bruges', 'Brussels', 'Ghent'], $user->addresses()->pluck('city')->all());
        $this->assertEquals(['Bruges', 'Brussels', 'Ghent', 'Paris'], $user->addresses()
            ->sortBy('city')
            ->pluck('city')
            ->all());
        $this->assertEquals([], $user->addresses()->where('city', 'New York')->pluck('city')->all());
        $this->assertEquals(['Bruges', 'Brussels', 'Ghent'], $user->addresses()
            ->where('country', 'Belgium')
            ->pluck('city')
            ->all());
        $this->assertEquals(['Bruges', 'Brussels', 'Ghent'], $user->addresses()
            ->where('country', 'Belgium')
            ->sortBy('city')
            ->pluck('city')
            ->all());

        $results = $user->addresses->first();
        $this->assertInstanceOf(Address::class, $results);

        $results = $user->addresses()->where('country', 'Belgium');
        $this->assertInstanceOf(Collection::class, $results);
        $this->assertEquals(3, $results->count());

        $results = $user->addresses()->whereIn('visited', [7, 13]);
        $this->assertEquals(2, $results->count());
    }

    public function testEmbedsOne()
    {
        $user   = User::create(['name' => 'John Doe']);
        $father = new User(['name' => 'Mark Doe']);

        $father->setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('dispatch')->with('eloquent.retrieved: ' . $father::class, Mockery::any());
        $events->shouldReceive('until')
            ->once()
            ->with('eloquent.saving: ' . $father::class, $father)
            ->andReturn(true);
        $events->shouldReceive('until')
            ->once()
            ->with('eloquent.creating: ' . $father::class, $father)
            ->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with('eloquent.created: ' . $father::class, $father);
        $events->shouldReceive('dispatch')->once()->with('eloquent.saved: ' . $father::class, $father);

        $father = $user->father()->save($father);
        $father->unsetEventDispatcher();

        $this->assertNotNull($user->father);
        $this->assertEquals('Mark Doe', $user->father->name);
        $this->assertInstanceOf(DateTime::class, $father->created_at);
        $this->assertInstanceOf(DateTime::class, $father->updated_at);
        $this->assertNotNull($father->id);
        $this->assertIsString($father->id);

        $raw = $father->getAttributes();
        $this->assertInstanceOf(ObjectId::class, $raw['id']);

        $father->setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('dispatch')->with('eloquent.retrieved: ' . $father::class, Mockery::any());
        $events->shouldReceive('until')
            ->once()
            ->with('eloquent.saving: ' . $father::class, $father)
            ->andReturn(true);
        $events->shouldReceive('until')
            ->once()
            ->with('eloquent.updating: ' . $father::class, $father)
            ->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with('eloquent.updated: ' . $father::class, $father);
        $events->shouldReceive('dispatch')->once()->with('eloquent.saved: ' . $father::class, $father);

        $father->name = 'Tom Doe';
        $user->father()->save($father);
        $father->unsetEventDispatcher();

        $this->assertNotNull($user->father);
        $this->assertEquals('Tom Doe', $user->father->name);

        $father = new User(['name' => 'Jim Doe']);

        $father->setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('dispatch')->with('eloquent.retrieved: ' . $father::class, Mockery::any());
        $events->shouldReceive('until')
            ->once()
            ->with('eloquent.saving: ' . $father::class, $father)
            ->andReturn(true);
        $events->shouldReceive('until')
            ->once()
            ->with('eloquent.creating: ' . $father::class, $father)
            ->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with('eloquent.created: ' . $father::class, $father);
        $events->shouldReceive('dispatch')->once()->with('eloquent.saved: ' . $father::class, $father);

        $father = $user->father()->save($father);
        $father->unsetEventDispatcher();

        $this->assertNotNull($user->father);
        $this->assertEquals('Jim Doe', $user->father->name);
    }

    public function testEmbedsOneAssociate()
    {
        $user   = User::create(['name' => 'John Doe']);
        $father = new User(['name' => 'Mark Doe']);

        $father->setEventDispatcher($events = Mockery::mock(Dispatcher::class));
        $events->shouldReceive('dispatch')->with('eloquent.retrieved: ' . $father::class, Mockery::any());
        $events->shouldReceive('until')->times(0)->with('eloquent.saving: ' . $father::class, $father);

        $father = $user->father()->associate($father);
        $father->unsetEventDispatcher();

        $this->assertNotNull($user->father);
        $this->assertEquals('Mark Doe', $user->father->name);
    }

    public function testEmbedsOneNullAssociation()
    {
        $user = User::create();
        $this->assertNull($user->father);
    }

    public function testEmbedsOneDelete()
    {
        $user   = User::create(['name' => 'John Doe']);
        $father = $user->father()->save(new User(['name' => 'Mark Doe']));

        $user->father()->delete();
        $this->assertNull($user->father);
    }

    public function testEmbedsOneRefresh()
    {
        $user   = User::create(['name' => 'John Doe']);
        $father = new User(['name' => 'Mark Doe']);

        $user->father()->associate($father);
        $user->save();

        $user->refresh();

        $this->assertNotNull($user->father);
        $this->assertEquals('Mark Doe', $user->father->name);
    }

    public function testEmbedsOneEmptyRefresh()
    {
        $user   = User::create(['name' => 'John Doe']);
        $father = new User(['name' => 'Mark Doe']);

        $user->father()->associate($father);
        $user->save();

        $user->father()->dissociate();
        $user->save();

        $user->refresh();

        $this->assertNull($user->father);
    }

    public function testEmbedsManyToArray()
    {
        $user = User::create(['name' => 'John Doe']);
        $this->assertInstanceOf(User::class, $user);
        $user->addresses()->save(new Address(['city' => 'New York']));
        $user->addresses()->save(new Address(['city' => 'Paris']));
        $user->addresses()->save(new Address(['city' => 'Brussels']));

        $array = $user->toArray();
        $this->assertArrayHasKey('addresses', $array);
        $this->assertIsArray($array['addresses']);
    }

    public function testEmbedsManyRefresh()
    {
        $user = User::create(['name' => 'John Doe']);
        $this->assertInstanceOf(User::class, $user);
        $user->addresses()->save(new Address(['city' => 'New York']));
        $user->addresses()->save(new Address(['city' => 'Paris']));
        $user->addresses()->save(new Address(['city' => 'Brussels']));

        $user->refresh();

        $array = $user->toArray();

        $this->assertArrayHasKey('addresses', $array);
        $this->assertIsArray($array['addresses']);
    }

    public function testEmbeddedSave()
    {
        $user = User::create(['name' => 'John Doe']);
        $this->assertInstanceOf(User::class, $user);
        $address = $user->addresses()->create(['city' => 'New York']);
        $this->assertInstanceOf(Address::class, $address);
        $father = $user->father()->create(['name' => 'Mark Doe']);

        $address->city = 'Paris';
        $address->save();

        $father->name = 'Steve Doe';
        $father->save();

        $this->assertEquals('Paris', $user->addresses->first()->city);
        $this->assertEquals('Steve Doe', $user->father->name);

        $user = User::where('name', 'John Doe')->first();
        $this->assertEquals('Paris', $user->addresses->first()->city);
        $this->assertEquals('Steve Doe', $user->father->name);

        $address = $user->addresses()->first();
        $father  = $user->father;

        $address->city = 'Ghent';
        $address->save();

        $father->name = 'Mark Doe';
        $father->save();

        $this->assertEquals('Ghent', $user->addresses->first()->city);
        $this->assertEquals('Mark Doe', $user->father->name);

        $user = User::where('name', 'John Doe')->first();
        $this->assertEquals('Ghent', $user->addresses->first()->city);
        $this->assertEquals('Mark Doe', $user->father->name);
    }

    public function testNestedEmbedsOne()
    {
        $user             = User::create(['name' => 'John Doe']);
        $father           = $user->father()->create(['name' => 'Mark Doe']);
        $grandfather      = $father->father()->create(['name' => 'Steve Doe']);
        $greatgrandfather = $grandfather->father()->create(['name' => 'Tom Doe']);

        $user->name = 'Tim Doe';
        $user->save();

        $father->name = 'Sven Doe';
        $father->save();

        $greatgrandfather->name = 'Ron Doe';
        $greatgrandfather->save();

        $this->assertEquals('Tim Doe', $user->name);
        $this->assertEquals('Sven Doe', $user->father->name);
        $this->assertEquals('Steve Doe', $user->father->father->name);
        $this->assertEquals('Ron Doe', $user->father->father->father->name);

        $user = User::where('name', 'Tim Doe')->first();
        $this->assertEquals('Tim Doe', $user->name);
        $this->assertEquals('Sven Doe', $user->father->name);
        $this->assertEquals('Steve Doe', $user->father->father->name);
        $this->assertEquals('Ron Doe', $user->father->father->father->name);
    }

    public function testNestedEmbedsMany()
    {
        $user     = User::create(['name' => 'John Doe']);
        $country1 = $user->addresses()->create(['country' => 'France']);
        $country2 = $user->addresses()->create(['country' => 'Belgium']);
        $city1    = $country1->addresses()->create(['city' => 'Paris']);
        $city2    = $country2->addresses()->create(['city' => 'Ghent']);
        $city3    = $country2->addresses()->create(['city' => 'Brussels']);

        $city3->city = 'Bruges';
        $city3->save();

        $this->assertEquals(2, $user->addresses()->count());
        $this->assertEquals(1, $user->addresses()->first()->addresses()->count());
        $this->assertEquals(2, $user->addresses()->last()->addresses()->count());

        $user = User::where('name', 'John Doe')->first();
        $this->assertEquals(2, $user->addresses()->count());
        $this->assertEquals(1, $user->addresses()->first()->addresses()->count());
        $this->assertEquals(2, $user->addresses()->last()->addresses()->count());
    }

    public function testNestedMixedEmbeds()
    {
        $user     = User::create(['name' => 'John Doe']);
        $father   = $user->father()->create(['name' => 'Mark Doe']);
        $country1 = $father->addresses()->create(['country' => 'France']);
        $country2 = $father->addresses()->create(['country' => 'Belgium']);

        $country2->country = 'England';
        $country2->save();

        $father->name = 'Steve Doe';
        $father->save();

        $this->assertEquals('France', $user->father->addresses()->first()->country);
        $this->assertEquals('England', $user->father->addresses()->last()->country);
        $this->assertEquals('Steve Doe', $user->father->name);

        $user = User::where('name', 'John Doe')->first();
        $this->assertEquals('France', $user->father->addresses()->first()->country);
        $this->assertEquals('England', $user->father->addresses()->last()->country);
        $this->assertEquals('Steve Doe', $user->father->name);
    }

    public function testNestedEmbedsOneDelete()
    {
        $user             = User::create(['name' => 'John Doe']);
        $father           = $user->father()->create(['name' => 'Mark Doe']);
        $grandfather      = $father->father()->create(['name' => 'Steve Doe']);
        $greatgrandfather = $grandfather->father()->create(['name' => 'Tom Doe']);

        $grandfather->delete();

        $this->assertNull($user->father->father);

        $user = User::where(['name' => 'John Doe'])->first();
        $this->assertNull($user->father->father);
    }

    public function testNestedEmbedsManyDelete()
    {
        $user    = User::create(['name' => 'John Doe']);
        $country = $user->addresses()->create(['country' => 'France']);
        $city1   = $country->addresses()->create(['city' => 'Paris']);
        $city2   = $country->addresses()->create(['city' => 'Nice']);
        $city3   = $country->addresses()->create(['city' => 'Lyon']);

        $city2->delete();

        $this->assertEquals(2, $user->addresses()->first()->addresses()->count());
        $this->assertEquals('Lyon', $country->addresses()->last()->city);

        $user = User::where('name', 'John Doe')->first();
        $this->assertEquals(2, $user->addresses()->first()->addresses()->count());
        $this->assertEquals('Lyon', $country->addresses()->last()->city);
    }

    public function testNestedMixedEmbedsDelete()
    {
        $user     = User::create(['name' => 'John Doe']);
        $father   = $user->father()->create(['name' => 'Mark Doe']);
        $country1 = $father->addresses()->create(['country' => 'France']);
        $country2 = $father->addresses()->create(['country' => 'Belgium']);

        $country1->delete();

        $this->assertEquals(1, $user->father->addresses()->count());
        $this->assertEquals('Belgium', $user->father->addresses()->last()->country);

        $user = User::where('name', 'John Doe')->first();
        $this->assertEquals(1, $user->father->addresses()->count());
        $this->assertEquals('Belgium', $user->father->addresses()->last()->country);
    }

    public function testDoubleAssociate()
    {
        $user    = User::create(['name' => 'John Doe']);
        $address = new Address(['city' => 'Paris']);

        $user->addresses()->associate($address);
        $user->addresses()->associate($address);
        $address = $user->addresses()->first();
        $user->addresses()->associate($address);
        $this->assertEquals(1, $user->addresses()->count());

        $user = User::where('name', 'John Doe')->first();
        $user->addresses()->associate($address);
        $this->assertEquals(1, $user->addresses()->count());

        $user->save();
        $user->addresses()->associate($address);
        $this->assertEquals(1, $user->addresses()->count());
    }

    public function testSaveEmptyModel()
    {
        $user = User::create(['name' => 'John Doe']);
        $user->addresses()->save(new Address());
        $this->assertNotNull($user->addresses);
        $this->assertEquals(1, $user->addresses()->count());
    }

    public function testIncrementEmbedded()
    {
        $user    = User::create(['name' => 'John Doe']);
        $address = $user->addresses()->create(['city' => 'New York', 'visited' => 5]);

        $address->increment('visited');
        $this->assertEquals(6, $address->visited);
        $this->assertEquals(6, $user->addresses()->first()->visited);

        $user = User::where('name', 'John Doe')->first();
        $this->assertEquals(6, $user->addresses()->first()->visited);

        $user    = User::where('name', 'John Doe')->first();
        $address = $user->addresses()->first();

        $address->decrement('visited');
        $this->assertEquals(5, $address->visited);
        $this->assertEquals(5, $user->addresses()->first()->visited);

        $user = User::where('name', 'John Doe')->first();
        $this->assertEquals(5, $user->addresses()->first()->visited);
    }

    public function testPaginateEmbedsMany()
    {
        $user = User::create(['name' => 'John Doe']);
        $user->addresses()->save(new Address(['city' => 'New York']));
        $user->addresses()->save(new Address(['city' => 'Paris']));
        $user->addresses()->save(new Address(['city' => 'Brussels']));

        $results = $user->addresses()->paginate(2);
        $this->assertEquals(2, $results->count());
        $this->assertEquals(3, $results->total());

        // With Closures
        $results = $user->addresses()->paginate(fn () => 3, page: 1, total: fn () => 5);
        $this->assertEquals(3, $results->count());
        $this->assertEquals(5, $results->total());
        $this->assertEquals(3, $results->perPage());
    }

    public function testGetQueueableRelationsEmbedsMany()
    {
        $user = User::create(['name' => 'John Doe']);
        $user->addresses()->save(new Address(['city' => 'New York']));
        $user->addresses()->save(new Address(['city' => 'Paris']));

        $this->assertEquals(['addresses'], $user->getQueueableRelations());
        $this->assertEquals([], $user->addresses->getQueueableRelations());
    }

    public function testGetQueueableRelationsEmbedsOne()
    {
        $user = User::create(['name' => 'John Doe']);
        $user->father()->save(new User(['name' => 'Mark Doe']));

        $this->assertEquals(['father'], $user->getQueueableRelations());
        $this->assertEquals([], $user->father->getQueueableRelations());
    }

    public function testUnsetPropertyOnEmbed()
    {
        $user = User::create(['name' => 'John Doe']);
        $user->addresses()->save(new Address(['city' => 'New York']));
        $user->addresses()->save(new Address(['city' => 'Tokyo']));

        // Set property
        $user->addresses->first()->city = 'Paris';
        $user->addresses->first()->save();

        $user = User::where('name', 'John Doe')->first();
        $this->assertSame('Paris', $user->addresses->get(0)->city);
        $this->assertSame('Tokyo', $user->addresses->get(1)->city);

        // Unset property
        unset($user->addresses->first()->city);
        $user->addresses->first()->save();

        $user = User::where('name', 'John Doe')->first();
        $this->assertNull($user->addresses->get(0)->city);
        $this->assertSame('Tokyo', $user->addresses->get(1)->city);

        // Unset and reset property
        unset($user->addresses->get(1)->city);
        $user->addresses->get(1)->city = 'Kyoto';
        $user->addresses->get(1)->save();

        $user = User::where('name', 'John Doe')->first();
        $this->assertNull($user->addresses->get(0)->city);
        $this->assertSame('Kyoto', $user->addresses->get(1)->city);
    }
}
