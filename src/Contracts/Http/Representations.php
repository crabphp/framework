<?php

declare(strict_types=1);

namespace Crab\Contracts\Http;

/**
 * The representations plugins have added; see Representation. The first
 * that writes a request's error is the one used.
 */
interface Representations
{
    public function add(Representation $representation): void;

    /**
     * @return list<Representation> In the order added.
     */
    public function all(): array;
}
