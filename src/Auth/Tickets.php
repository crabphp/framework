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
 */
final readonly class Tickets implements TicketsContract
{
    public function __construct(private Cache $cache, private IdentityResolver $users, private int $ttl = 30)
    {
        if ($ttl < 1) {
            throw new \InvalidArgumentException('A ticket needs a positive lifetime. Set auth.ticket_ttl to a whole number of seconds greater than zero.');
        }
    }

    public function issue(Identity $user): string
    {
        do {
            $ticket = bin2hex(random_bytes(32));
        } while (!$this->cache->add($this->key($ticket), $user->id, $this->ttl));
        return $ticket;
    }

    public function redeem(string $ticket): ?Identity
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $ticket) !== 1) {
            return null;
        }
        $id = $this->cache->take($this->key($ticket));
        return is_int($id) || is_string($id) ? $this->users->resolve($id) : null;
    }

    private function key(string $ticket): string
    {
        return 'auth.ticket.' . hash('sha256', $ticket);
    }
}
