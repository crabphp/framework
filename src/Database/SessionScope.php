<?php

declare(strict_types=1);

namespace Crab\Database;

/**
 * Separates callback lifetime from the physical session's transaction depth.
 * Losing a session poisons its active callbacks until their scopes unwind.
 *
 * @internal
 */
final class SessionScope
{
    private int $transactions = 0;
    private int $fixed = 0;
    private bool $transactionLost = false;
    private bool $sessionLost = false;
    private ?DatabaseException $transactionFailure = null;

    /**
     * A transaction callback cannot turn into an autocommit callback on loss.
     */
    public function transaction(\Closure $work): mixed
    {
        ++$this->transactions;
        try {
            $result = $work();
            if ($this->transactionFailure !== null) {
                throw $this->transactionFailure;
            }
            return $result;
        } finally {
            --$this->transactions;
            if ($this->transactions === 0) {
                $this->transactionLost = false;
                $this->transactionFailure = null;
            }
        }
    }

    /**
     * Advisory locks belong to one physical session, including across DDL.
     */
    public function fixed(\Closure $work): mixed
    {
        $this->check();
        ++$this->fixed;
        try {
            return $work();
        } finally {
            --$this->fixed;
            if ($this->fixed === 0) {
                $this->sessionLost = false;
            }
        }
    }

    /**
     * Closing resources must not erase the callback's failed-scope state.
     */
    public function lost(): void
    {
        if ($this->transactions > 0) {
            $this->transactionLost = true;
        }
        if ($this->fixed > 0) {
            $this->sessionLost = true;
        }
    }

    /**
     * A deadlock rolls back the whole transaction even while its session lives.
     * Keep its error through nested callbacks so callers can retry the whole work.
     */
    public function failed(DatabaseException $error): void
    {
        if ($this->transactions > 0) {
            $this->transactionFailure ??= $error;
        }
    }

    /**
     * Reopening is safe only outside both kinds of session ownership.
     */
    public function canReconnect(): bool
    {
        return $this->transactions === 0 && $this->fixed === 0;
    }

    /**
     * Reject commands before opening or using a replacement session.
     */
    public function check(string $sql = ''): void
    {
        if ($this->transactionFailure !== null) {
            throw $this->transactionFailure;
        }
        if ($this->transactionLost) {
            throw new DatabaseException(
                'The transaction lost its session; let this callback unwind before using the connection again.',
                $sql,
            );
        }
        if ($this->sessionLost) {
            throw new DatabaseException(
                'The fixed database session was lost; abort this scope before using the connection again.',
                $sql,
            );
        }
    }
}
