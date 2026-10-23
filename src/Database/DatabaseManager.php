<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Database\Migrators;
use Crab\Engine\Network\DnsResolver;
use Crab\Engine\Process\Worker;
use Psr\Clock\ClockInterface;

/**
 * The databases an application is configured with, by name. Each is made
 * when it is first asked for and then kept, with its own pool of
 * connections and its own migrator.
 *
 * Each database's settings are an array with these keys, all but "dsn"
 * optional:
 *
 *     dsn          "mysql:host=127.0.0.1;dbname=app" or "sqlite:/path/app.sqlite"
 *     username     for MySQL
 *     password     for MySQL
 *     options      integer PDO attributes, or the async driver's string ssl stream option
 *     async        use the nonblocking MySQL driver inside a provider's worker; false
 *     pool         the most connections each worker opens; 1 for SQLite, which allows one writer, and 10 otherwise
 *     timeout      seconds to wait for a connection when all are in use; 5
 *     migrations   the folder that holds this database's migration files
 */
final class DatabaseManager implements Migrators
{
    private ?Worker $worker = null;
    /**
     * @var array<string, Database>
     */
    private array $databases = [];
    /**
     * @var array<string, Migrator>
     */
    private array $migrators = [];

    /**
     * @param array<string, array<string, mixed>> $connections The settings of each database, by its name.
     * @param string $default The name of the one used when none is named.
     * @param ClockInterface|null $clock Passed to the migrators; the real time when left out.
     * @param int $migrationLockTimeout Whole seconds each migrator waits; zero does not wait.
     * @param string|null $migrations Where the default database's migrations are when its settings do not say.
     */
    public function __construct(
        private readonly array $connections,
        private readonly string $default,
        private readonly ?ClockInterface $clock = null,
        private readonly int $migrationLockTimeout = 60,
        private readonly ?string $migrations = null,
    ) {
        // With none configured there is nothing to check: an application that uses no database asks for none.
        if ($connections !== [] && !isset($connections[$default])) {
            throw new DatabaseException(
                "The default database is \"{$default}\", and no database of that name is configured.",
            );
        }
    }

    /**
     * The database of this name, or the default one.
     */
    public function connection(?string $name = null): Database
    {
        $name ??= $this->default;
        return $this->databases[$name] ??= $this->make($name);
    }

    /**
     * The migrator of the database of this name, or of the default one.
     */
    public function migrator(?string $name = null): Migrator
    {
        $name ??= $this->default;
        if (!isset($this->migrators[$name])) {
            $database = $this->connection($name);
            $directory = $this->migrations($name);
            $this->migrators[$name] = new Migrator(
                $database,
                $directory ?? [],
                clock: $this->clock,
                lockTimeout: $this->migrationLockTimeout,
            );
        }
        return $this->migrators[$name];
    }

    /**
     * The folder of this database's migrations: its "migrations" setting,
     * or for the default database, database/migrations. Another database
     * has none until it is set, since guessing one would mix two databases' files.
     */
    public function migrations(?string $name = null): ?string
    {
        $name ??= $this->default;
        $directory = $this->connections[$name]['migrations'] ?? null;
        if (is_string($directory) && $directory !== '') {
            return $directory;
        }
        return $name === $this->default ? $this->migrations : null;
    }

    /**
     * @return list<string> The names of the configured databases.
     */
    public function names(): array
    {
        return array_keys($this->connections);
    }

    /**
     * Closes the connections of every database that was used.
     */
    public function close(): void
    {
        foreach ($this->databases as $database) {
            $database->close();
        }
    }

    /**
     * Provider lifecycle supplies the running worker without process-global state.
     * Existing pools are replaced so a parent or previous worker's PDO is not lent.
     */
    public function start(Worker $worker): void
    {
        $this->close();
        $this->worker = $worker;
    }

    public function stop(): void
    {
        foreach ($this->databases as $database) {
            $database->stop();
        }
        $this->worker = null;
    }

    private function make(string $name): Database
    {
        if ($this->connections === []) {
            throw new DatabaseException(
                'No database is configured. Describe one under "database.connections", by name: \'connections\' => [\'main\' => [\'driver\' => \'sqlite\', \'database\' => \'/path/app.sqlite\']].',
            );
        }
        $settings = $this->connections[$name] ?? null;
        if ($settings === null) {
            throw new DatabaseException(
                "There is no database named \"{$name}\"; the configured ones are: "
                . implode(', ', $this->names())
                . '.',
            );
        }
        $dsn = self::dsn($name, $settings);
        $username = $settings['username'] ?? null;
        $password = $settings['password'] ?? null;
        $options = (array) ($settings['options'] ?? []);
        $sqlite = str_starts_with($dsn, 'sqlite:');
        $connect = static function () use ($dsn, $username, $password, $options, $sqlite): \PDO {
            $mysql = str_starts_with($dsn, 'mysql:');
            $pdoOptions = $mysql
                ? self::pdoOptions($options)
                : array_filter($options, static fn(int|string $key): bool => is_int($key), ARRAY_FILTER_USE_KEY);
            $pdo = new \PDO($dsn, $username, $password, $pdoOptions);
            if ($mysql && ($options['ssl'] ?? false) !== false) {
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $status = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
                $cipher = $status->fetchColumn(1);
                $status->closeCursor();
                if (!is_string($cipher) || $cipher === '') {
                    throw new DatabaseException(
                        'PDO did not establish the requested TLS session; check the MySQL client and server TLS configuration before retrying.',
                    );
                }
            }
            if ($sqlite) {
                // SQLite checks foreign keys only on a connection that asks it to.
                $pdo->exec('pragma foreign_keys = on');
                // Several processes share the file: the workers, the queue, the scheduler. With a
                // write-ahead log readers and a writer do not hold each other up, and a writer
                // that finds another at work waits a few seconds for it before giving up.
                $pdo->exec('pragma journal_mode = wal');
                $pdo->exec('pragma busy_timeout = 5000');
            }
            return $pdo;
        };
        $pool = (int) ($settings['pool'] ?? ($sqlite ? 1 : 10));
        $timeout = (float) ($settings['timeout'] ?? 5.0);
        $async = (bool) ($settings['async'] ?? false) && str_starts_with($dsn, 'mysql:');
        $resolver = new DnsResolver(fallback: false);
        $factory = fn(\Closure $connect): ManagedConnection => $async && $this->worker !== null
            ? new Mysql\Connection(
                $dsn,
                (string) $username,
                (string) $password,
                $options,
                $timeout,
                $this->worker,
                resolver: $resolver,
            )
            : new PdoConnection($connect);
        return new Database($connect, $pool, $timeout, $this, $factory);
    }

    /**
     * The data source name of a database: its "dsn" when one is given, or
     * one written from its "driver" and the settings of that driver.
     *
     *     sqlite   database: the file, or ":memory:"
     *     mysql    host (127.0.0.1), port (3306), database, and charset or unix_socket when given
     *
     * @param array<string, mixed> $settings
     */
    public static function dsn(string $name, array $settings): string
    {
        $dsn = $settings['dsn'] ?? null;
        if (is_string($dsn) && $dsn !== '') {
            return $dsn;
        }
        $driver = $settings['driver'] ?? null;
        $database = (string) ($settings['database'] ?? '');
        if ($driver === 'sqlite') {
            if ($database === '') {
                throw new DatabaseException(
                    "The database \"{$name}\" is SQLite and names no file. Set \"database\" to the file, such as storage/database.sqlite in the application.",
                );
            }
            return 'sqlite:' . $database;
        }
        if ($driver !== 'mysql') {
            throw new DatabaseException(
                "The database \"{$name}\" needs a \"driver\": \"mysql\" with its host, port, database, username and password, or \"sqlite\" with the file as its database.",
            );
        }
        if ($database === '' || str_contains($database, '/')) {
            // A path is the SQLite default left in place after switching to MySQL.
            throw new DatabaseException(
                "The database \"{$name}\" is MySQL and names no database"
                . ($database === '' ? '' : ", only the file {$database}")
                . '. Set "database", DB_DATABASE in the skeleton, to the name of the database on the server.',
            );
        }
        $parts = ($settings['unix_socket'] ?? '') !== ''
            ? ['unix_socket' => $settings['unix_socket']]
            : ['host' => $settings['host'] ?? '127.0.0.1', 'port' => $settings['port'] ?? 3306];
        $parts['dbname'] = $database;
        if (($settings['charset'] ?? '') !== '') {
            $parts['charset'] = $settings['charset'];
        }
        $written = [];
        foreach ($parts as $key => $value) {
            $value = (string) $value;
            // A ";" would start another part of the name.
            if (str_contains($value, ';')) {
                throw new DatabaseException("The {$key} of the database \"{$name}\" cannot contain \";\".");
            }
            $written[] = "{$key}={$value}";
        }
        return 'mysql:' . implode(';', $written);
    }

    /**
     * Maps only stream TLS policies PDO can preserve at construction time.
     * mysqlnd couples certificate/name checks and needs an encryption option
     * as well as its verification flag to send SSLRequest.
     *
     * @param array<int|string, mixed> $options
     * @return array<int, mixed>
     */
    private static function pdoOptions(array $options): array
    {
        $pdo = array_filter($options, static fn(int|string $key): bool => is_int($key), ARRAY_FILTER_USE_KEY);
        $ssl = $options['ssl'] ?? false;
        if ($ssl === false) {
            return $pdo;
        }
        if ($ssl !== true && !is_array($ssl)) {
            throw new DatabaseException(
                'Set options["ssl"] on the database connection to true, false or supported SSL context options.',
            );
        }
        $ssl = $ssl === true ? [] : $ssl;
        $attributes = [
            'cafile' => \PDO::MYSQL_ATTR_SSL_CA,
            'capath' => \PDO::MYSQL_ATTR_SSL_CAPATH,
            'local_cert' => \PDO::MYSQL_ATTR_SSL_CERT,
            'local_pk' => \PDO::MYSQL_ATTR_SSL_KEY,
            'ciphers' => \PDO::MYSQL_ATTR_SSL_CIPHER,
        ];
        foreach ($ssl as $key => $value) {
            if (!array_key_exists($key, $attributes) && !in_array($key, ['verify_peer', 'verify_peer_name'], true)) {
                throw new DatabaseException(
                    "PDO cannot preserve SSL context option {$key}; remove it or configure an equivalent supported policy.",
                );
            }
        }
        $verify = array_key_exists('verify_peer', $ssl) ? $ssl['verify_peer'] : true;
        $verifyName = array_key_exists('verify_peer_name', $ssl) ? $ssl['verify_peer_name'] : true;
        if (!is_bool($verify) || !is_bool($verifyName) || $verify !== $verifyName) {
            throw new DatabaseException(
                'PDO couples certificate and name verification; set verify_peer and verify_peer_name to the same boolean.',
            );
        }
        $mapped = [\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => $verify];
        foreach ($attributes as $key => $attribute) {
            if (!array_key_exists($key, $ssl)) {
                continue;
            }

            if (!is_string($ssl[$key]) || $ssl[$key] === '') {
                throw new DatabaseException("Set SSL context option {$key} to a non-empty string for PDO.");
            }
            $mapped[$attribute] = $ssl[$key];
        }
        foreach ($mapped as $attribute => $value) {
            if (array_key_exists($attribute, $pdo) && $pdo[$attribute] !== $value) {
                throw new DatabaseException(
                    "PDO SSL attribute {$attribute} conflicts with options[\"ssl\"]; use one consistent TLS policy.",
                );
            }
            $pdo[$attribute] = $value;
        }
        if (array_intersect_key($pdo, array_flip($attributes)) === []) {
            $pdo[\PDO::MYSQL_ATTR_SSL_CIPHER] = 'DEFAULT';
        }
        return $pdo;
    }
}
