<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Database as DatabaseContract;
use Crab\Engine\Async\Pool;
use Crab\Engine\Async\PoolExhaustedException;
use Crab\Engine\EventLoop;

/**
 * A pool of database connections. Nothing is opened until a statement runs, so
 * it is safe to create anywhere; each worker ends up with its own
 * connections.
 */
final class Database implements DatabaseContract
{
    private ?Pool $pool = null;
    /**
     * The event loop the pool waits on.
     */
    private ?EventLoop $loop = null;
    private ?string $driver = null;
    /**
     * Each connection returns to the pool that lent it, even after that
     * pool closes. Weak keys let shutdown find outstanding sessions too.
     *
     * @var \WeakMap<ManagedConnection, Pool>|null
     */
    private ?\WeakMap $connections = null;

    /**
     * @param \Closure(): \PDO $connect Opens one connection, for example `fn() => new PDO($dsn, $user, $password)`.
     * @param int $max The most connections this pool opens.
     * @param float $timeout Seconds to wait for a connection when all are in use.
     * @param DatabaseManager|null $manager The databases this one is among, which connection() chooses from.
     * @param (\Closure(\Closure): ManagedConnection)|null $factory Selects a driver, given the PDO connection factory.
     */
    public function __construct(
        private readonly \Closure $connect,
        private readonly int $max = 10,
        private readonly float $timeout = 5.0,
        private readonly ?DatabaseManager $manager = null,
        private readonly ?\Closure $factory = null,
    ) {
        if ($max < 1 || !is_finite($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException(
                'A database needs a positive pool size and a finite, positive timeout.',
            );
        }
    }

    public function connection(string $name): DatabaseContract
    {
        if ($this->manager === null) {
            throw new \LogicException(
                "This database was made on its own and knows no other, so it has none named \"{$name}\". Named databases come from a DatabaseManager, or from the \"database.connections\" setting.",
            );
        }
        return $this->manager->connection($name);
    }

    public function owns(Connection $connection): bool
    {
        return (
            $connection === $this
            || $connection instanceof ManagedConnection
            && isset($this->connections[$connection])
        );
    }

    public function afterCommit(\Closure $callback): void
    {
        // A statement of the pool's own is committed as it runs.
        $callback();
    }

    public function acquire(): Connection
    {
        $pool = $this->pool();
        try {
            $connection = $pool->acquire();
        } catch (PoolExhaustedException $error) {
            throw new DatabaseException(
                "No database connection became free within {$this->timeout} seconds; the pool holds {$this->max}.",
                previous: $error,
            );
        }
        $this->connections ??= new \WeakMap();
        $this->connections[$connection] = $pool;
        return $connection;
    }

    public function release(Connection $connection): void
    {
        if (!$connection instanceof ManagedConnection || !isset($this->connections[$connection])) {
            throw new \LogicException(
                'This connection was not borrowed from this database. Release it through the database that lent it.',
            );
        }
        $pool = $this->connections[$connection];
        if ($connection->inTransaction()) {
            // Validate the loan before closing it; the next borrower must not inherit the transaction.
            $pool->discard($connection);
            throw new \LogicException(
                'A connection was released inside a transaction; the transaction was rolled back.',
            );
        }
        $pool->release($connection);
    }

    public function run(\Closure $callback): mixed
    {
        $connection = $this->acquire();
        try {
            return $callback($connection);
        } finally {
            $this->release($connection);
        }
    }

    public function table(string $table): Query
    {
        return new Query($this, $table);
    }

    public function driver(): string
    {
        return $this->driver ??= $this->run(static fn(Connection $connection): string => $connection->driver());
    }

    public function select(string $sql, array $bindings = []): array
    {
        return $this->run(static fn(Connection $connection): array => $connection->select($sql, $bindings));
    }

    public function first(string $sql, array $bindings = []): ?array
    {
        return $this->run(static fn(Connection $connection): ?array => $connection->first($sql, $bindings));
    }

    public function insert(string $sql, array $bindings = []): int|string
    {
        return $this->run(static fn(Connection $connection): int|string => $connection->insert($sql, $bindings));
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return $this->run(static fn(Connection $connection): int => $connection->execute($sql, $bindings));
    }

    public function transaction(\Closure $callback): mixed
    {
        return $this->run(static fn(Connection $connection): mixed => $connection->transaction($callback));
    }

    /**
     * Closes the idle connections now and the borrowed ones as they come
     * back. The database can be used again afterwards; it reconnects.
     */
    public function close(): void
    {
        $this->pool?->close();
        $this->pool = null;
        $this->loop = null;
    }

    /**
     * Worker teardown cuts outstanding commands as well as idle sessions.
     * No abandoned socket or readiness watcher survives the worker deadline.
     *
     * @internal
     */
    public function stop(): void
    {
        $this->close();
        foreach ($this->connections ?? [] as $connection => $_) {
            $connection->close();
        }
    }

    private function make(): ManagedConnection
    {
        return $this->factory !== null ? ($this->factory)($this->connect) : new PdoConnection($this->connect);
    }

    /**
     * The pool for the current event loop. A worker installs its own loop
     * after it is forked, so a pool made earlier, in the parent, would wait
     * on a loop that no longer runs; it is replaced, connections and all.
     */
    private function pool(): Pool
    {
        $loop = EventLoop::current();
        if ($this->pool === null || $this->loop !== $loop) {
            $this->pool?->close();
            $this->loop = $loop;
            $this->pool = new Pool(
                $loop,
                $this->make(...),
                $this->max,
                $this->timeout,
                static fn(ManagedConnection $connection) => $connection->close(),
            );
        }
        return $this->pool;
    }
}
