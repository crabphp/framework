<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Database\Connection;

/**
 * One PDO connection, opened when it is first used. PDO blocks: while a
 * statement runs, its worker serves nothing else.
 */
final class PdoConnection implements Connection
{
    /**
     * Failures that mean the connection is gone; it is reopened on next use.
     */
    private const LOST = [
        'server has gone away', 'no connection to the server', 'Lost connection', 'is dead or not enabled',
        'Error while sending', 'server closed the connection unexpectedly', 'SSL connection has been closed unexpectedly',
        'Error writing data to the connection', 'Connection timed out', 'reset by peer', 'Broken pipe', 'Packets out of order',
    ];
    /**
     * Of those, the ones raised before the statement reached the server,
     * so that running it again cannot apply it twice.
     */
    private const NOT_SENT = ['server has gone away', 'no connection to the server'];
    /**
     * Prepared statements kept per connection.
     */
    private const STATEMENTS = 256;

    private ?\PDO $pdo = null;
    private int $depth = 0;
    /**
     * @var array<string, \PDOStatement>
     */
    private array $statements = [];
    private ?string $driver = null;

    /**
     * @param \Closure(): \PDO $connect Opens the connection; called again after one is lost.
     */
    public function __construct(private readonly \Closure $connect)
    {
    }

    public function table(string $table): Query
    {
        return new Query($this, $table);
    }

    public function driver(): string
    {
        return $this->driver ??= (string) $this->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->statement($sql, $bindings);
        try {
            return $statement->fetchAll();
        } finally {
            $statement->closeCursor();
        }
    }

    public function first(string $sql, array $bindings = []): ?array
    {
        $statement = $this->statement($sql, $bindings);
        try {
            $row = $statement->fetch();
            return $row === false ? null : $row;
        } finally {
            $statement->closeCursor();
        }
    }

    public function insert(string $sql, array $bindings = []): int|string
    {
        $this->statement($sql, $bindings)->closeCursor();
        try {
            $id = (string) $this->pdo->lastInsertId();
        } catch (\PDOException $error) {
            throw new DatabaseException('The database did not report an inserted id: ' . $error->getMessage(), $sql, $error);
        }
        return ctype_digit($id) ? (int) $id : $id;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $statement = $this->statement($sql, $bindings);
        try {
            return $statement->rowCount();
        } finally {
            $statement->closeCursor();
        }
    }

    public function transaction(\Closure $callback): mixed
    {
        $this->begin();
        $level = $this->depth;
        try {
            $result = $callback($this);
        } catch (\Throwable $error) {
            // A lost connection took the transaction with it; there is nothing left to roll back.
            if ($this->depth === $level) {
                $this->rollBack();
            }
            throw $error;
        }
        if ($this->depth !== $level) {
            throw new DatabaseException('The transaction was lost with its connection, so nothing was committed.');
        }
        $this->commit();
        return $result;
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0 || ($this->pdo?->inTransaction() ?? false);
    }

    /**
     * Drops the connection and everything prepared on it; an open
     * transaction is lost with it. The next statement reconnects.
     */
    public function close(): void
    {
        $this->statements = [];
        $this->pdo = null;
        $this->depth = 0;
    }

    private function begin(): void
    {
        if ($this->depth > 0) {
            $this->attempt(fn () => $this->pdo()->exec('SAVEPOINT crab_' . $this->depth), 'SAVEPOINT');
        } elseif ($this->driver() === 'sqlite') {
            // SQLite's own BEGIN waits to lock until the first write, and a transaction that has
            // read and then finds another writer is refused at once, "database is locked",
            // however long it would wait. Taking the write lock at the start makes it queue.
            $this->attempt(fn () => $this->pdo()->exec('BEGIN IMMEDIATE'), 'BEGIN IMMEDIATE');
        } else {
            $this->attempt(fn () => $this->pdo()->beginTransaction(), 'BEGIN');
        }
        ++$this->depth;
    }

    private function commit(): void
    {
        $depth = $this->depth - 1;
        try {
            $depth === 0
                ? $this->attempt(fn () => $this->pdo()->commit(), 'COMMIT')
                : $this->attempt(fn () => $this->pdo()->exec('RELEASE SAVEPOINT crab_' . $depth), 'RELEASE SAVEPOINT');
        } catch (\Throwable $error) {
            // Its state is unknown now; a fresh connection is the only safe one.
            $this->close();
            throw $error;
        }
        $this->depth = $depth;
    }

    private function rollBack(): void
    {
        $depth = $this->depth - 1;
        try {
            if ($depth > 0) {
                $this->pdo()->exec('ROLLBACK TO SAVEPOINT crab_' . $depth);
            } elseif ($this->pdo()->inTransaction()) {
                // Some statements end a transaction by themselves; then there is nothing to roll back.
                $this->pdo()->rollBack();
            }
            $this->depth = $depth;
        } catch (\Throwable) {
            // The failure being rolled back is the one to report; dropping the connection rolls back too.
            $this->close();
        }
    }

    /**
     * Prepares, binds and executes, keeping the statement for the next
     * call with the same SQL.
     *
     * @param array<int|string, mixed> $bindings
     */
    private function statement(string $sql, array $bindings): \PDOStatement
    {
        return $this->attempt(function () use ($sql, $bindings): \PDOStatement {
            $statement = $this->statements[$sql] ?? $this->pdo()->prepare($sql);
            foreach ($bindings as $key => $value) {
                [$value, $type] = self::bound($value);
                $statement->bindValue(is_int($key) ? $key + 1 : $key, $value, $type);
            }
            $statement->execute();
            if (!isset($this->statements[$sql])) {
                if (count($this->statements) >= self::STATEMENTS) {
                    array_shift($this->statements);
                }
                $this->statements[$sql] = $statement;
            }
            return $statement;
        }, $sql);
    }

    /**
     * Runs one operation on the connection. A lost connection is dropped,
     * so the next use reconnects; the operation itself is run again only
     * when it never reached the server and no transaction was open.
     */
    private function attempt(\Closure $operation, string $sql): mixed
    {
        for ($attempt = 0; ; ++$attempt) {
            try {
                return $operation();
            } catch (\PDOException $error) {
                unset($this->statements[$sql]);
                $message = $error->getMessage();
                if (self::mentions($message, self::LOST)) {
                    $inTransaction = $this->depth > 0;
                    $this->close();
                    if ($attempt === 0 && !$inTransaction && self::mentions($message, self::NOT_SENT)) {
                        continue;
                    }
                }
                throw new DatabaseException($message, $sql, $error);
            }
        }
    }

    private function pdo(): \PDO
    {
        if ($this->pdo === null) {
            try {
                $pdo = ($this->connect)();
            } catch (\PDOException $error) {
                throw new DatabaseException('Cannot connect to the database: ' . $error->getMessage(), previous: $error);
            }
            if (!$pdo instanceof \PDO) {
                throw new \LogicException('The connect closure must return a PDO.');
            }
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
            $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, false);
            $this->pdo = $pdo;
        }
        return $this->pdo;
    }

    /**
     * @return array{mixed, int} The value as PDO takes it, and its PDO type.
     */
    private static function bound(mixed $value): array
    {
        return match (true) {
            is_int($value) => [$value, \PDO::PARAM_INT],
            is_bool($value) => [$value, \PDO::PARAM_BOOL],
            $value === null => [null, \PDO::PARAM_NULL],
            is_string($value) => [$value, \PDO::PARAM_STR],
            is_float($value) => [(string) $value, \PDO::PARAM_STR],
            $value instanceof \DateTimeInterface => [$value->format('Y-m-d H:i:s'), \PDO::PARAM_STR],
            $value instanceof \Stringable => [(string) $value, \PDO::PARAM_STR],
            default => throw new \InvalidArgumentException('A ' . get_debug_type($value) . ' cannot be bound to a statement.'),
        };
    }

    /**
     * @param list<string> $phrases
     */
    private static function mentions(string $message, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (str_contains($message, $phrase)) {
                return true;
            }
        }
        return false;
    }
}
