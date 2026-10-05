<?php

declare(strict_types=1);

namespace Crab\Contracts\Database;

/**
 * A pool of connections to one database. Its own select(), first(),
 * insert() and execute() each borrow a connection for that one statement,
 * and its transaction() holds one for the whole callback.
 */
interface Database extends Connection
{
    /**
     * Another of the application's databases, by the name it is configured
     * under: $db->connection('reports')->table('totals')->get(). Each has
     * its own connections, so a transaction never spans two.
     */
    public function connection(string $name): Database;

    /**
     * Borrows a connection, waiting for one to be free when every one is
     * in use. Whatever is acquired must be released; prefer run().
     */
    public function acquire(): Connection;

    public function release(Connection $connection): void;

    /**
     * Lends one connection for the length of the callback, for several
     * statements that should share a connection.
     *
     * @param \Closure(Connection): mixed $callback
     * @return mixed What the callback returned.
     */
    public function run(\Closure $callback): mixed;
}
