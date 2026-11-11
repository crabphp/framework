<?php

declare(strict_types=1);

namespace Crab\Auth;

use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Auth\IdentityResolver;
use Crab\Contracts\Auth\Tickets as TicketsContract;
use Crab\Contracts\Cache\Cache;

/**
 * Exchanges a short-lived random ticket for its user once. The shared
 * cache's atomic take decides which concurrent handshake succeeds.
 *
 * A ticket keeps its user's class beside the id, and is redeemed only for
 * a user of that class: where a Customer and a User can share an id, a
 * customer's ticket never comes back as the user.
 */
final readonly class Tickets implements TicketsContract
{
    public function __construct(
        private Cache $cache,
        private IdentityResolver $users,
        private int $ttl = 30,
    ) {
        if ($ttl < 1) {
            throw new \InvalidArgumentException(
                'A ticket needs a positive lifetime. Set auth.ticket_ttl to a whole number of seconds greater than zero.',
            );
        }
    }

    public function issue(Identity $user): string
    {
        do {
            $ticket = bin2hex(random_bytes(32));
        } while (!$this->cache->add($this->key($ticket), [$user::class, $user->id], $this->ttl));
        return $ticket;
    }

    public function redeem(string $ticket): ?Identity
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $ticket) !== 1) {
            return null;
        }
        $stored = $this->cache->take($this->key($ticket));
        if (!is_array($stored) || count($stored) !== 2 || !is_string($stored[0] ?? null)) {
            return null;
        }
        [$class, $id] = $stored;
        if (!is_int($id) && !is_string($id)) {
            return null;
        }
        $user = $this->users->resolve($id);
        return $user instanceof $class ? $user : null;
    }

    private function key(string $ticket): string
    {
        return 'auth.ticket.' . hash('sha256', $ticket);
    }
}
