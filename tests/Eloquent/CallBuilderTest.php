<?php

declare(strict_types=1);

namespace MongoDB\Laravel\Tests\Eloquent;

use BadMethodCallException;
use Generator;
use MongoDB\Laravel\Eloquent\Builder;
use MongoDB\Laravel\Query\Builder as QueryBuilder;
use MongoDB\Laravel\Tests\Models\User;
use MongoDB\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

use function assert;

final class CallBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        User::truncate();

        parent::tearDown();
    }

    #[Dataprovider('provideFunctionNames')]
    public function testCallingABuilderMethodDoesNotReturnTheBuilderInstance(string $method, string $className, $parameters = []): void
    {
        $builder = User::query()->newQuery();
        assert($builder instanceof Builder);

        self::assertNotInstanceOf(expected: $className, actual: $builder->{$method}(...$parameters));
    }

    public static function provideFunctionNames(): Generator
    {
        yield 'does not exist' => ['doesntExist', Builder::class];
        yield 'get bindings' => ['getBindings', Builder::class];
        yield 'get connection' => ['getConnection', Builder::class];
        yield 'get grammar' => ['getGrammar', Builder::class];
        yield 'insert get id' => ['insertGetId', Builder::class, [['user' => 'foo']]];
        yield 'to Mql' => ['toMql', Builder::class];
        yield 'average' => ['average', Builder::class, ['name']];
        yield 'avg' => ['avg', Builder::class, ['name']];
        yield 'count' => ['count', Builder::class, ['name']];
        yield 'exists' => ['exists', Builder::class];
        yield 'insert' => ['insert', Builder::class, [['name']]];
        yield 'max' => ['max', Builder::class, ['name']];
        yield 'min' => ['min', Builder::class, ['name']];
        yield 'pluck' => ['pluck', Builder::class, ['name']];
        yield 'pull' => ['pull', Builder::class, ['name']];
        yield 'push' => ['push', Builder::class, ['name']];
        yield 'raw' => ['raw', Builder::class];
        yield 'sum' => ['sum', Builder::class, ['name']];
    }

    #[Test]
    #[DataProvider('provideUnsupportedMethods')]
    public function callingUnsupportedMethodThrowsAnException(string $method, string $exceptionClass, string $exceptionMessage, $parameters = []): void
    {
        $builder = User::query()->newQuery();
        assert($builder instanceof Builder);

        $this->expectException($exceptionClass);
        $this->expectExceptionMessage($exceptionMessage);

        $builder->{$method}(...$parameters);
    }

    public static function provideUnsupportedMethods(): Generator
    {
        yield 'insert or ignore' => [
            'insertOrIgnore',
            RuntimeException::class,
            'This database engine does not support inserting while ignoring errors',
            [['name' => 'Jane']],
        ];

        yield 'insert using' => [
            'insertUsing',
            BadMethodCallException::class,
            'This method is not supported by MongoDB. Try "toMql()" instead',
            [[['name' => 'Jane']], fn (QueryBuilder $builder) => $builder],
        ];

        yield 'to sql' => [
            'toSql',
            BadMethodCallException::class,
            'This method is not supported by MongoDB. Try "toMql()" instead',
            [[['name' => 'Jane']], fn (QueryBuilder $builder) => $builder],
        ];
    }
}
