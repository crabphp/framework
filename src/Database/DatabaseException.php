<?php

declare(strict_types=1);

namespace Crab\Database;

/**
 * A statement or a connection failed. The message carries the SQL but
 * never the bound values, which may be secrets.
 */
final class DatabaseException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $sql = '', ?\Throwable $previous = null)
    {
        parent::__construct($sql === '' ? $message : $message . ' (SQL: ' . $sql . ')', 0, $previous);
    }
}
