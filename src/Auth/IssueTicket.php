<?php

declare(strict_types=1);

namespace Crab\Auth;

use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Auth\Tickets;

/**
 * An authenticated HTTP endpoint a browser calls before each connection.
 * Declare its route explicitly and protect it with your authentication.
 */
final readonly class IssueTicket
{
    public function __construct(private Tickets $tickets)
    {
    }

    /**
     * @return array{ticket: string}
     */
    public function handle(Identity $user): array
    {
        return ['ticket' => $this->tickets->issue($user)];
    }
}
