<?php

declare(strict_types=1);

namespace Crab\Contracts\Auth;

/**
 * Short-lived, single-use credentials for a browser's socket handshake.
 * Only the issuing caller sees the ticket; storage keeps its hash and
 * the identity id needed to restore the user.
 */
interface Tickets
{
    public function issue(Identity $user): string;

    /**
     * Consumes a ticket and resolves its user, or returns null for every
     * missing, expired, used or unresolvable credential.
     */
    public function redeem(string $ticket): ?Identity;
}
