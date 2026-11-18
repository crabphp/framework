<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Http\DecodePolicies as DecodePoliciesContract;
use Crab\Contracts\Http\DecodePolicy;

/**
 * The decode policies plugins have added, in the order added.
 */
final class DecodePolicies implements DecodePoliciesContract
{
    /**
     * @var list<DecodePolicy>
     */
    private array $policies = [];

    public function add(DecodePolicy $policy): void
    {
        $this->policies[] = $policy;
    }

    public function all(): array
    {
        return $this->policies;
    }
}
