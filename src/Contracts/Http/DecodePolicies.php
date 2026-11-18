<?php

declare(strict_types=1);

namespace Crab\Contracts\Http;

/**
 * The decode policies plugins have added; see DecodePolicy. The first
 * that chooses a reading for a request decides it.
 */
interface DecodePolicies
{
    public function add(DecodePolicy $policy): void;

    /**
     * @return list<DecodePolicy> In the order added.
     */
    public function all(): array;
}
