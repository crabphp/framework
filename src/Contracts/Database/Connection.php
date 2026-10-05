<?php

declare(strict_types=1);

namespace Crab\Contracts\Database;

/**
 * Runs SQL with bound parameters. Values never go into the SQL text: write
 * ? or :name placeholders and pass the values as bindings, a list for ? and
 * a map for :name.
 */
interface Connection
{
    /**
     * Starts a query on a table, built with methods instead of SQL:
     * table('users')->where('active', true)->get(). The table may be given
     * an alias, "users as u".
     */
    public function table(string $table): Query;

    /**
     * The database this connection talks to, "mysql" or "sqlite", which
     * decides the SQL a query is written in. Asking may open a connection.
     */
    public function driver(): string;

    /**
     * @param array<int|string, mixed> $bindings
     * @return list<array<string, mixed>> Every row, as column name to value.
     */
    public function select(string $sql, array $bindings = []): array;

    /**
     * @param array<int|string, mixed> $bindings
     * @return array<string, mixed>|null The first row, or null when there is none.
     */
    public function first(string $sql, array $bindings = []): ?array;

    /**
     * Runs an INSERT and returns the id the database generated for it, as
     * an integer when it is one.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function insert(string $sql, array $bindings = []): int|string;

    /**
     * Runs any other statement, such as UPDATE or DELETE.
     *
     * @param array<int|string, mixed> $bindings
     * @return int The number of rows it affected.
     */
    public function execute(string $sql, array $bindings = []): int;

    /**
     * Runs the callback inside a transaction on one connection, which the
     * callback receives: committed when it returns, rolled back when it
     * throws. A transaction inside a transaction is a savepoint.
     *
     * @param \Closure(Connection): mixed $callback
     * @return mixed What the callback returned.
     */
    public function transaction(\Closure $callback): mixed;
}
