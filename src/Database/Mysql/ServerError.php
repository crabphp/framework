<?php

declare(strict_types=1);

namespace Crab\Database\Mysql;

/**
 * Preserves the server's SQLSTATE and numeric error in PDO's familiar shape.
 * Existing callers inspecting a database exception's cause remain compatible.
 *
 * @internal
 */
final class ServerError extends \PDOException
{
    public function __construct(string $message, int $number, string $state = 'HY000')
    {
        parent::__construct($message, $number);
        $this->errorInfo = [$state, $number, $message];
    }
}
