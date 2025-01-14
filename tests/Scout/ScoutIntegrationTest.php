<?php

namespace MongoDB\Laravel\Tests\Scout;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\ScoutServiceProvider;
use LogicException;
use MongoDB\Laravel\Tests\Scout\Models\ScoutUser;
use MongoDB\Laravel\Tests\Scout\Models\SearchableInSameNamespace;
use MongoDB\Laravel\Tests\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Depends;

use function array_merge;
use function count;
use function env;
use function Orchestra\Testbench\artisan;
use function range;
use function sprintf;
use function usleep;

class ScoutIntegrationTest extends TestCase
{
    #[Override]
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [ScoutServiceProvider::class]);
    }

    #[Override]
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('scout.driver', 'mongodb');
        $app['config']->set('scout.prefix', 'prefix_');
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->skipIfSearchIndexManagementIsNotSupported();

        // Init the SQL database with some objects that will be indexed
        // Test data copied from Laravel Scout tests
        // https://github.com/laravel/scout/blob/10.x/tests/Integration/SearchableTests.php
        ScoutUser::executeSchema();

        $collect = LazyCollection::make(function () {
            yield ['name' => 'Laravel Framework'];

            foreach (range(2, 10) as $key) {
                yield ['name' => 'Example ' . $key];
            }

            yield ['name' => 'Larry Casper', 'email_verified_at' => null];
            yield ['name' => 'Reta Larkin'];

            foreach (range(13, 19) as $key) {
                yield ['name' => 'Example ' . $key];
            }

            yield ['name' => 'Prof. Larry Prosacco DVM', 'email_verified_at' => null];

            foreach (range(21, 38) as $key) {
                yield ['name' => 'Example ' . $key, 'email_verified_at' => null];
            }

            yield ['name' => 'Linkwood Larkin', 'email_verified_at' => null];
            yield ['name' => 'Otis Larson MD'];
            yield ['name' => 'Gudrun Larkin'];
            yield ['name' => 'Dax Larkin'];
            yield ['name' => 'Dana Larson Sr.'];
            yield ['name' => 'Amos Larson Sr.'];
        });

        $id = 0;
        $date = new DateTimeImmutable('2021-01-01 00:00:00');
        foreach ($collect as $data) {
            $data = array_merge(['id' => ++$id, 'email_verified_at' => $date], $data);
            ScoutUser::create($data)->save();
        }

        self::assertSame(44, ScoutUser::count());
    }

    /** This test create the search index for tests performing search */
    public function testItCanCreateTheCollection()
    {
        $collection = DB::connection('mongodb')->getCollection('prefix_scout_users');
        $collection->drop();

        // Recreate the indexes using the artisan commands
        // Ensure they return a success exit code (0)
        self::assertSame(0, artisan($this, 'scout:delete-index', ['name' => ScoutUser::class]));
        self::assertSame(0, artisan($this, 'scout:index', ['name' => ScoutUser::class]));
        self::assertSame(0, artisan($this, 'scout:import', ['model' => ScoutUser::class]));

        self::assertSame(44, $collection->countDocuments());

        $searchIndexes = $collection->listSearchIndexes(['name' => 'scout']);
        self::assertCount(1, $searchIndexes);

        // Wait for all documents to be indexed asynchronously
        $i = 100;
        while (true) {
            $indexedDocuments = $collection->aggregate([
                ['$search' => ['index' => 'scout', 'exists' => ['path' => 'name']]],
            ])->toArray();

            if (count($indexedDocuments) >= 44) {
                break;
            }

            if ($i-- === 0) {
                self::fail('Documents not indexed');
            }

            usleep(100_000);
        }

        self::assertCount(44, $indexedDocuments);
    }

    #[Depends('testItCanCreateTheCollection')]
    public function testItCanUseBasicSearch()
    {
        // All the search queries use "sort" option to ensure the results are deterministic
        $results = ScoutUser::search('lar')->take(10)->orderBy('id')->get();

        self::assertSame([
            1 => 'Laravel Framework',
            11 => 'Larry Casper',
            12 => 'Reta Larkin',
            20 => 'Prof. Larry Prosacco DVM',
            39 => 'Linkwood Larkin',
            40 => 'Otis Larson MD',
            41 => 'Gudrun Larkin',
            42 => 'Dax Larkin',
            43 => 'Dana Larson Sr.',
            44 => 'Amos Larson Sr.',
        ], $results->pluck('name', 'id')->all());
    }

    #[Depends('testItCanCreateTheCollection')]
    public function testItCanUseBasicSearchCursor()
    {
        // All the search queries use "sort" option to ensure the results are deterministic
        $results = ScoutUser::search('lar')->take(10)->orderBy('id')->cursor();

        self::assertSame([
            1 => 'Laravel Framework',
            11 => 'Larry Casper',
            12 => 'Reta Larkin',
            20 => 'Prof. Larry Prosacco DVM',
            39 => 'Linkwood Larkin',
            40 => 'Otis Larson MD',
            41 => 'Gudrun Larkin',
            42 => 'Dax Larkin',
            43 => 'Dana Larson Sr.',
            44 => 'Amos Larson Sr.',
        ], $results->pluck('name', 'id')->all());
    }

    #[Depends('testItCanCreateTheCollection')]
    public function testItCanUseBasicSearchWithQueryCallback()
    {
        $results = ScoutUser::search('lar')->take(10)->orderBy('id')->query(function ($query) {
            return $query->whereNotNull('email_verified_at');
        })->get();

        self::assertSame([
            1 => 'Laravel Framework',
            12 => 'Reta Larkin',
            40 => 'Otis Larson MD',
            41 => 'Gudrun Larkin',
            42 => 'Dax Larkin',
            43 => 'Dana Larson Sr.',
            44 => 'Amos Larson Sr.',
        ], $results->pluck('name', 'id')->all());
    }

    #[Depends('testItCanCreateTheCollection')]
    public function testItCanUseBasicSearchToFetchKeys()
    {
        $results = ScoutUser::search('lar')->orderBy('id')->take(10)->keys();

        self::assertSame([1, 11, 12, 20, 39, 40, 41, 42, 43, 44], $results->all());
    }

    #[Depends('testItCanCreateTheCollection')]
    public function testItCanUseBasicSearchWithQueryCallbackToFetchKeys()
    {
        $results = ScoutUser::search('lar')->take(10)->orderBy('id', 'desc')->query(function ($query) {
            return $query->whereNotNull('email_verified_at');
        })->keys();

        self::assertSame([44, 43, 42, 41, 40, 39, 20, 12, 11, 1], $results->all());
    }

    #[Depends('testItCanCreateTheCollection')]
    public function testItCanUsePaginatedSearch()
    {
        $page1 = ScoutUser::search('lar')->take(10)->orderBy('id')->paginate(5, 'page', 1);
        $page2 = ScoutUser::search('lar')->take(10)->orderBy('id')->paginate(5, 'page', 2);

        self::assertSame([
            1 => 'Laravel Framework',
            11 => 'Larry Casper',
            12 => 'Reta Larkin',
            20 => 'Prof. Larry Prosacco DVM',
            39 => 'Linkwood Larkin',
        ], $page1->pluck('name', 'id')->all());

        self::assertSame([
            40 => 'Otis Larson MD',
            41 => 'Gudrun Larkin',
            42 => 'Dax Larkin',
            43 => 'Dana Larson Sr.',
            44 => 'Amos Larson Sr.',
        ], $page2->pluck('name', 'id')->all());
    }

    #[Depends('testItCanCreateTheCollection')]
    public function testItCanUsePaginatedSearchWithQueryCallback()
    {
        $queryCallback = function ($query) {
            return $query->whereNotNull('email_verified_at');
        };

        $page1 = ScoutUser::search('lar')->take(10)->orderBy('id')->query($queryCallback)->paginate(5, 'page', 1);
        $page2 = ScoutUser::search('lar')->take(10)->orderBy('id')->query($queryCallback)->paginate(5, 'page', 2);

        self::assertSame([
            1 => 'Laravel Framework',
            12 => 'Reta Larkin',
        ], $page1->pluck('name', 'id')->all());

        self::assertSame([
            40 => 'Otis Larson MD',
            41 => 'Gudrun Larkin',
            42 => 'Dax Larkin',
            43 => 'Dana Larson Sr.',
            44 => 'Amos Larson Sr.',
        ], $page2->pluck('name', 'id')->all());
    }

    public function testItCannotIndexInTheSameNamespace()
    {
        self::expectException(LogicException::class);
        self::expectExceptionMessage(sprintf(
            'The MongoDB Scout collection "%s.searchable_in_same_namespaces" must use a different collection from the collection name of the model "%s". Set the "scout.prefix" configuration or use a distinct MongoDB database',
            env('MONGODB_DATABASE', 'unittest'),
            SearchableInSameNamespace::class,
        ),);

        SearchableInSameNamespace::create(['name' => 'test']);
    }
}
