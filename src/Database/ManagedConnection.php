<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Database\Connection;

/**
 * Driver identity and physical-session scopes used inside the database component.
 * These operations do not widen the contract that application packages use.
 *
 * @internal
 */
interface ManagedConnection extends Connection
{
    /**
     * Whether this driver was given the same connection factory.
     * Database tracks pool ownership separately for each connection.
     */
    public function opensWith(\Closure $connect): bool;

    /**
     * Includes raw server transactions as well as managed callback depth.
     */
    public function inTransaction(): bool;

    /**
     * Keeps one physical session for work protected by an advisory lock.
     * Loss aborts the scope; no command in it may reconnect or be replayed.
     *
     * @param \Closure(self): mixed $callback
     */
    public function withoutReconnect(\Closure $callback): mixed;

    /**
     * Whether local session resources remain; this is not a liveness probe.
     * A closed session has already released its server-owned advisory locks.
     */
    public function isOpen(): bool;

    /**
     * Releases resources while preserving any active callback's lost state.
     */
    public function close(): void;
}
