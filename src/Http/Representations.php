<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Http\Representation;
use Crab\Contracts\Http\Representations as RepresentationsContract;

/**
 * The representations plugins have added, in the order added.
 */
final class Representations implements RepresentationsContract
{
    /**
     * @var list<Representation>
     */
    private array $representations = [];

    public function add(Representation $representation): void
    {
        $this->representations[] = $representation;
    }

    public function all(): array
    {
        return $this->representations;
    }
}
