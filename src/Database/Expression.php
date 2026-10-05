<?php

declare(strict_types=1);

namespace Crab\Database;

/**
 * SQL that goes into a statement as written, with the values for its own
 * placeholders. A query keeps one for each of its Raw methods.
 */
final readonly class Expression
{
    /**
     * @param list<mixed> $bindings
     */
    public function __construct(public string $sql, public array $bindings = [])
    {
    }
}
