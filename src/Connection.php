<?php

declare(strict_types=1);

namespace MongoDB\Laravel;

use Composer\InstalledVersions;
use Illuminate\Database\Connection as BaseConnection;
use InvalidArgumentException;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Exception\AuthenticationException;
use MongoDB\Driver\Exception\ConnectionException;
use MongoDB\Driver\Exception\RuntimeException;
use MongoDB\Driver\ReadPreference;
use MongoDB\Laravel\Concerns\ManagesTransactions;
use OutOfBoundsException;
use Throwable;

use function filter_var;
use function implode;
use function is_array;
use function preg_match;
use function sprintf;
use function str_contains;
use function trigger_error;

use const E_USER_DEPRECATED;
use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;

/** @mixin Database */
class Connection extends BaseConnection
{
    use ManagesTransactions;

    private static ?string $version = null;

    /**
     * The MongoDB database handler.
     *
     * @var Database
     */
    protected $db;

    /**
     * The MongoDB connection handler.
     *
     * @var Client
     */
    protected $connection;

    private ?CommandSubscriber $commandSubscriber = null;

    /**
     * Create a new database connection instance.
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        // Build the connection string
        $dsn = $this->getDsn($config);

        // You can pass options directly to the MongoDB constructor
        $options = $config['options'] ?? [];

        // Create the connection
        $this->connection = $this->createConnection($dsn, $config, $options);
        $this->database = $this->getDefaultDatabaseName($dsn, $config);

        // Select database
        $this->db = $this->connection->getDatabase($this->database);

        $this->tablePrefix = $config['prefix'] ?? '';

        $this->useDefaultPostProcessor();

        $this->useDefaultSchemaGrammar();

        $this->useDefaultQueryGrammar();
    }

    /**
     * Begin a fluent query against a database collection.
     *
     * @param  string      $table The name of the MongoDB collection
     * @param  string|null $as    Ignored. Not supported by MongoDB
     *
     * @return Query\Builder
     */
    public function table($table, $as = null)
    {
        $query = new Query\Builder($this, $this->getQueryGrammar(), $this->getPostProcessor());

        return $query->from($table);
    }

    /**
     * Get a MongoDB collection.
     *
     * @param  string $name
     *
     * @return Collection
     */
    public function getCollection($name): Collection
    {
        return $this->db->selectCollection($this->tablePrefix . $name);
    }

    /** @inheritdoc */
    public function getSchemaBuilder()
    {
        return new Schema\Builder($this);
    }

    /**
     * Get the MongoDB database object.
     *
     * @deprecated since mongodb/laravel-mongodb:5.2, use getDatabase() instead
     *
     * @return Database
     */
    public function getMongoDB()
    {
        trigger_error(sprintf('Since mongodb/laravel-mongodb:5.2, Method "%s()" is deprecated, use "getDatabase()" instead.', __FUNCTION__), E_USER_DEPRECATED);

        return $this->db;
    }

    /**
     * Get the MongoDB database object.
     *
     * @param string|null $name Name of the database, if not provided the default database will be returned.
     *
     * @return Database
     */
    public function getDatabase(?string $name = null): Database
    {
        if ($name && $name !== $this->database) {
            return $this->connection->getDatabase($name);
        }

        return $this->db;
    }

    /**
     * Return MongoDB object.
     *
     * @deprecated since mongodb/laravel-mongodb:5.2, use getClient() instead
     *
     * @return Client
     */
    public function getMongoClient()
    {
        trigger_error(sprintf('Since mongodb/laravel-mongodb:5.2, method "%s()" is deprecated, use "getClient()" instead.', __FUNCTION__), E_USER_DEPRECATED);

        return $this->getClient();
    }

    /**
     * Get the MongoDB client.
     */
    public function getClient(): ?Client
    {
        return $this->connection;
    }

    public function enableQueryLog()
    {
        parent::enableQueryLog();

        if (! $this->commandSubscriber) {
            $this->commandSubscriber = new CommandSubscriber($this);
            $this->connection->addSubscriber($this->commandSubscriber);
        }
    }

    public function disableQueryLog()
    {
        parent::disableQueryLog();

        if ($this->commandSubscriber) {
            $this->connection->removeSubscriber($this->commandSubscriber);
            $this->commandSubscriber = null;
        }
    }

    protected function withFreshQueryLog($callback)
    {
        try {
            return parent::withFreshQueryLog($callback);
        } finally {
            // The parent method enable query log using enableQueryLog()
            // but disables it by setting $loggingQueries to false. We need to
            // remove the subscriber for performance.
            if (! $this->loggingQueries) {
                $this->disableQueryLog();
            }
        }
    }

    /**
     * Get the name of the default database based on db config or try to detect it from dsn.
     *
     * @throws InvalidArgumentException
     */
    protected function getDefaultDatabaseName(string $dsn, array $config): string
    {
        if (empty($config['database'])) {
            if (! preg_match('/^mongodb(?:[+]srv)?:\\/\\/.+\\/([^?&]+)/s', $dsn, $matches)) {
                throw new InvalidArgumentException('Database is not properly configured.');
            }

            $config['database'] = $matches[1];
        }

        return $config['database'];
    }

    /**
     * Create a new MongoDB connection.
     */
    protected function createConnection(string $dsn, array $config, array $options): Client
    {
        // By default driver options is an empty array.
        $driverOptions = [];

        if (isset($config['driver_options']) && is_array($config['driver_options'])) {
            $driverOptions = $config['driver_options'];
        }

        $driverOptions['driver'] = [
            'name' => 'laravel-mongodb',
            'version' => self::getVersion(),
        ];

        // Check if the credentials are not already set in the options
        if (! isset($options['username']) && ! empty($config['username'])) {
            $options['username'] = $config['username'];
        }

        if (! isset($options['password']) && ! empty($config['password'])) {
            $options['password'] = $config['password'];
        }

        if (isset($config['name'])) {
            $driverOptions += ['connectionName' => $config['name']];
        }

        return new Client($dsn, $options, $driverOptions);
    }

    /**
     * Check the connection to the MongoDB server
     *
     * @throws ConnectionException if connection to the server fails (for reasons other than authentication).
     * @throws AuthenticationException if authentication is needed and fails.
     * @throws RuntimeException if a server matching the read preference could not be found.
     */
    public function ping(): void
    {
        $this->getClient()->getManager()->selectServer(new ReadPreference(ReadPreference::PRIMARY_PREFERRED));
    }

    /** @inheritdoc */
    public function disconnect()
    {
        $this->disableQueryLog();
        $this->connection = null;
    }

    /**
     * Determine if the given configuration array has a dsn string.
     *
     * @deprecated
     */
    protected function hasDsnString(array $config): bool
    {
        return ! empty($config['dsn']);
    }

    /**
     * Get the DSN string form configuration.
     */
    protected function getDsnString(array $config): string
    {
        return $config['dsn'];
    }

    /**
     * Get the DSN string for a host / port configuration.
     */
    protected function getHostDsn(array $config): string
    {
        // Treat host option as array of hosts
        $hosts = is_array($config['host']) ? $config['host'] : [$config['host']];

        foreach ($hosts as &$host) {
            // ipv6
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $host = '[' . $host . ']';
                if (! empty($config['port'])) {
                    $host .= ':' . $config['port'];
                }
            } else {
                // Check if we need to add a port to the host
                if (! str_contains($host, ':') && ! empty($config['port'])) {
                    $host .= ':' . $config['port'];
                }
            }
        }

        // Check if we want to authenticate against a specific database.
        $authDatabase = isset($config['options']) && ! empty($config['options']['database']) ? $config['options']['database'] : null;

        return 'mongodb://' . implode(',', $hosts) . ($authDatabase ? '/' . $authDatabase : '');
    }

    /**
     * Create a DSN string from a configuration.
     */
    protected function getDsn(array $config): string
    {
        if (! empty($config['dsn'])) {
            return $this->getDsnString($config);
        }

        if (! empty($config['host'])) {
            return $this->getHostDsn($config);
        }

        throw new InvalidArgumentException('MongoDB connection configuration requires "dsn" or "host" key.');
    }

    /** @inheritdoc */
    public function getDriverName()
    {
        return 'mongodb';
    }

    /** @inheritdoc */
    public function getDriverTitle()
    {
        return 'MongoDB';
    }

    /** @inheritdoc */
    protected function getDefaultPostProcessor()
    {
        return new Query\Processor();
    }

    /** @inheritdoc */
    protected function getDefaultQueryGrammar()
    {
        // Argument added in Laravel 12
        return new Query\Grammar($this);
    }

    /** @inheritdoc */
    protected function getDefaultSchemaGrammar()
    {
        // Argument added in Laravel 12
        return new Schema\Grammar($this);
    }

    /**
     * Set database.
     */
    public function setDatabase(Database $db)
    {
        $this->db = $db;
    }

    /** @inheritdoc  */
    public function threadCount()
    {
        $status = $this->db->command(['serverStatus' => 1])->toArray();

        return $status[0]['connections']['current'];
    }

    /**
     * Dynamically pass methods to the connection.
     *
     * @param  string $method
     * @param  array  $parameters
     *
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->db->$method(...$parameters);
    }

    /**
     * Return the server version of one of the MongoDB servers: primary for
     * replica sets and standalone, and the selected server for sharded clusters.
     *
     * @internal
     */
    public function getServerVersion(): string
    {
        return $this->db->command(['buildInfo' => 1])->toArray()[0]['version'];
    }

    private static function getVersion(): string
    {
        return self::$version ?? self::lookupVersion();
    }

    private static function lookupVersion(): string
    {
        try {
            try {
                return self::$version = InstalledVersions::getPrettyVersion('mongodb/laravel-mongodb') ?? 'unknown';
            } catch (OutOfBoundsException) {
                return self::$version = InstalledVersions::getPrettyVersion('jenssegers/mongodb') ?? 'unknown';
            }
        } catch (Throwable) {
            return self::$version = 'error';
        }
    }
}
