<?php

declare(strict_types=1);

namespace Crab\Contracts;

/**
 * The declarations packages make beside the application's routes; see
 * Definition. Each is finished once every plugin, the application's setup
 * and its route files have run.
 */
interface Definitions
{
    public function add(Definition $definition): void;

    /**
     * @return list<Definition> In the order added.
     */
    public function all(): array;
}
