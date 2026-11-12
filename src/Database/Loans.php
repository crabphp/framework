<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Database\Connection;

/**
 * The loan a pooled connection is on, so that a query built during one loan
 * cannot run after it: once the connection is back in the pool it may be lent
 * to another fiber, mid-transaction, and the same physical connection lent
 * again later is a new loan, not the old one come back.
 *
 * Held weakly by connection, so a connection that is gone takes its loan
 * with it. A connection never lent has no loan, and its queries are free.
 *
 * @internal
 */
final class Loans
{
    /**
     * @var \WeakMap<Connection, object>|null
     */
    private static ?\WeakMap $loans = null;

    public static function lend(Connection $connection): void
    {
        self::$loans ??= new \WeakMap();
        self::$loans[$connection] = new \stdClass();
    }

    public static function end(Connection $connection): void
    {
        if (self::$loans !== null) {
            unset(self::$loans[$connection]);
        }
    }

    public static function of(Connection $connection): ?object
    {
        return self::$loans[$connection] ?? null;
    }
}
