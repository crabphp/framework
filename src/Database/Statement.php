<?php

declare(strict_types=1);

namespace Crab\Database;

/**
 * A statement ready to run: its SQL and the values for its placeholders.
 */
final readonly class Statement
{
    /**
     * @param list<mixed> $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings = [],
    ) {}
}
