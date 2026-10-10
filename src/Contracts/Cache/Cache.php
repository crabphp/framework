<?php

declare(strict_types=1);

namespace Crab\Contracts\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16's cache, and the two things an application most often builds on
 * it: a value that is made when it is missing, and a value that is set
 * only when it is missing.
 *
 * A time to live is a number of seconds, a DateInterval, or null for the
 * cache's default. A key is text without any of {}()/\@: in it.
 */
interface Cache extends CacheInterface
{
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
     * of several callers at once exactly one is told true. This is what a
     * lock is made of: add('report:running', true, 600).
     *
     * @return bool Whether this call set it.
     */
    public function add(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool;
}
