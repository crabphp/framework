<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Database\Query as QueryContract;

/**
 * A query written inside another, under a name: a column of selectSub(),
 * or the rows of joinSub().
 */
final readonly class Subquery
{
    public function __construct(
        public QueryContract $query,
        public string $as,
    ) {
        if (trim($as) === '') {
            throw new \InvalidArgumentException('A query read inside another is given a name to be known by.');
        }
    }
}
