<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Database as DatabaseContract;
use Crab\Engine\Async\Pool;
use Crab\Engine\Async\PoolExhaustedException;
use Crab\Engine\EventLoop;

/**
 * A pool of PDO connections. Nothing is opened until a statement runs, so
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

    /**
     * @param \Closure(): \PDO $connect Opens one connection, for example `fn() => new PDO($dsn, $user, $password)`.
     * @param int $max The most connections this pool opens.
     * @param float $timeout Seconds to wait for a connection when all are in use.
     */
    public function __construct(
        private readonly \Closure $connect,
        private readonly int $max = 10,
        private readonly float $timeout = 5.0,
    ) {
        if ($max < 1 || !is_finite($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('A database needs a positive pool size and a finite, positive timeout.');
        }
    }

    public function acquire(): Connection
    {
        try {
            return $this->pool()->acquire();
        } catch (PoolExhaustedException $error) {
            throw new DatabaseException(
                "No database connection became free within {$this->timeout} seconds; the pool holds {$this->max}.",
                previous: $error,
            );
        }
    }

    public function release(Connection $connection): void
    {
        if ($connection instanceof PdoConnection && $connection->inTransaction()) {
            // The next borrower must not inherit it.
            $connection->close();
            $this->pool()->release($connection);
            throw new \LogicException('A connection was released inside a transaction; the transaction was rolled back.');
        }
        $this->pool()->release($connection);
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

    public function select(string $sql, array $bindings = []): array
    {
        return $this->run(static fn (Connection $connection): array => $connection->select($sql, $bindings));
    }

    public function first(string $sql, array $bindings = []): ?array
    {
        return $this->run(static fn (Connection $connection): ?array => $connection->first($sql, $bindings));
    }

    public function insert(string $sql, array $bindings = []): int|string
    {
        return $this->run(static fn (Connection $connection): int|string => $connection->insert($sql, $bindings));
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return $this->run(static fn (Connection $connection): int => $connection->execute($sql, $bindings));
    }

    public function transaction(\Closure $callback): mixed
    {
        return $this->run(static fn (Connection $connection): mixed => $connection->transaction($callback));
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
                fn (): PdoConnection => new PdoConnection($this->connect),
                $this->max,
                $this->timeout,
                static fn (PdoConnection $connection) => $connection->close(),
            );
        }
        return $this->pool;
    }
}
