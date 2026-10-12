<?php

declare(strict_types=1);

namespace Crab\Contracts\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16's cache, with values made when missing, atomic counts and locks
 * that only their holder can release.
 *
 * A time to live is a number of seconds, a DateInterval, or null for the
 * cache's default. A key is text without any of {}()/\@: in it.
 */
interface Cache extends CacheInterface
{
    /**
     * Returns and removes a value atomically, so only one caller receives
     * it. A missing or expired entry returns the given default.
     */
    public function take(string $key, mixed $default = null): mixed;

    /**
     * The value at the key; when there is none, what $make returns, which
     * is then kept for the time to live:
     *
     *     $rates = $cache->remember('rates', 3600, fn () => $this->bank->rates());
     *
     * Two callers that miss at the same moment may both make the value.
     *
     * @param \Closure(): mixed $make
     */
    public function remember(string $key, null|int|\DateInterval $ttl, \Closure $make): mixed;

    /**
     * Sets the value only when the key holds none, as one step, so that
     * of several callers at once exactly one is told true.
     *
     * @return bool Whether this call set it.
     */
    public function add(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool;

    /**
     * Takes a lock, or returns null when another caller holds it. The
     * lock uses its own keys, apart from ordinary cached values.
     * Give a positive time to live so a crashed process cannot hold it forever.
     *
     * @throws \InvalidArgumentException When the time to live is zero or less.
     */
    public function lock(string $key, int|\DateInterval $ttl): ?Lock;

    /**
     * Adds to the whole number at the key, as one step, so that of
     * several callers at once each is told a different count. A key that
     * holds none counts from zero, and the time to live is given to a
     * count as it starts, not moved by each addition:
     *
     *     $attempts = $cache->increment('login.' . $email, ttl: 900);
     *
     * An entry that is not a whole number is replaced by the count.
     *
     * @param int $by How much to add; less than zero takes away.
     * @return int The number now at the key.
     */
    public function increment(string $key, int $by = 1, null|int|\DateInterval $ttl = null): int;
}
