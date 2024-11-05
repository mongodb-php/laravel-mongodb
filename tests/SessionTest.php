<?php

namespace MongoDB\Laravel\Tests;

use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\MongoDbSessionHandler;

class SessionTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::connection('mongodb')->getCollection('sessions')->drop();

        parent::tearDown();
    }

    public function testDatabaseSessionHandlerCompatibility()
    {
        $sessionId = '123';

        $handler = new DatabaseSessionHandler(
            $this->app['db']->connection('mongodb'),
            'sessions',
            10,
        );

        $handler->write($sessionId, 'foo');
        $this->assertEquals('foo', $handler->read($sessionId));

        $handler->write($sessionId, 'bar');
        $this->assertEquals('bar', $handler->read($sessionId));
    }

    public function testDatabaseSessionHandlerRegistration()
    {
        $this->app['config']->set('session.driver', 'database');
        $this->app['config']->set('session.connection', 'mongodb');

        $session = $this->app['session'];
        $this->assertInstanceOf(SessionManager::class, $session);
        $this->assertInstanceOf(DatabaseSessionHandler::class, $session->getHandler());

        $this->assertSessionCanStoreInMongoDB($session);
    }

    public function testMongoDBSessionHandlerRegistration()
    {
        $this->app['config']->set('session.driver', 'mongodb');
        $this->app['config']->set('session.connection', 'mongodb');

        $session = $this->app['session'];
        $this->assertInstanceOf(SessionManager::class, $session);
        $this->assertInstanceOf(MongoDbSessionHandler::class, $session->getHandler());

        $this->assertSessionCanStoreInMongoDB($session);
    }

    private function assertSessionCanStoreInMongoDB(SessionManager $session): void
    {
        $session->put('foo', 'bar');
        $session->save();

        $this->assertNotNull($session->getId());

        $data = DB::connection('mongodb')
            ->getCollection('sessions')
            ->findOne(['_id' => $session->getId()]);

        self::assertIsObject($data);
        self::assertSame($session->getId(), $data->_id);
    }
}
