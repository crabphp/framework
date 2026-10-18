<?php

declare(strict_types=1);

namespace Crab\Database;

/**
 * A statement or connection failed. Crab adds SQL, never the binding array.
 * Forwarded server messages may themselves quote an offending value.
 */
final class DatabaseException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $sql = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $sql === '' ? $message : $message . ' (SQL: ' . $sql . ')',
            $previous instanceof \PDOException ? (int) ($previous->errorInfo[1] ?? 0) : 0,
            $previous,
        );
    }

    /**
     * The driver number is independent of whether PDO or the wire client failed.
     */
    public function code(): ?int
    {
        $previous = $this->getPrevious();
        return $previous instanceof \PDOException && isset($previous->errorInfo[1])
            ? (int) $previous->errorInfo[1]
            : null;
    }

    public function sqlState(): ?string
    {
        $previous = $this->getPrevious();
        return $previous instanceof \PDOException ? $previous->errorInfo[0] ?? null : null;
    }
}
