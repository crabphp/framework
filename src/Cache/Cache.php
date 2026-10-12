<?php

declare(strict_types=1);

namespace Crab\Cache;

use Crab\Contracts\Cache\Cache as CacheContract;
use Crab\Contracts\Cache\Lock as LockContract;
use Crab\Contracts\Cache\Store;
use Psr\Clock\ClockInterface;

/**
 * A PSR-16 cache in front of a store. It checks keys, works out when an
 * entry expires and turns values into text with serialize(), so anything
 * serialize() takes can be cached, objects included.
 *
 *     $cache = new Cache(new FileStore('/var/cache/app'));
 *     $cache->set('rates', $rates, 3600);
 *
 * A value that is null cannot be told from a missing one by get(); use
 * has(), or do not cache null.
 */
final readonly class Cache implements CacheContract
{
    /**
     * @param string $prefix Put before every key, to keep two applications apart in one store.
     * @param int|null $ttl Seconds an entry lives when no time to live is given; forever when null.
     * @param ClockInterface|null $clock Tells whether an entry has expired; the real time when left out.
     */
    public function __construct(
        private Store $store,
        private string $prefix = '',
        private ?int $ttl = null,
        private ?ClockInterface $clock = null,
    ) {
        if (str_contains($prefix, ':lock:')) {
            throw new \InvalidArgumentException("A cache prefix cannot contain :lock: because that text is kept for the cache's locks. Choose another prefix.");
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->store->get($this->key($key), $this->now());
        return $entry === null ? $default : unserialize($entry);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $now = $this->now();
        $expiresAt = $this->expiresAt($ttl, $now);
        if ($expiresAt !== null && $expiresAt <= $now) {
            // PSR-16: a time to live of nothing or less removes the entry.
            $this->store->delete($this->key($key));
            return true;
        }
        $this->store->set($this->key($key), serialize($value), $expiresAt);
        return true;
    }

    public function take(string $key, mixed $default = null): mixed
    {
        $entry = $this->store->take($this->key($key), $this->now());
        return $entry === null ? $default : unserialize($entry);
    }

    public function add(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $now = $this->now();
        $expiresAt = $this->expiresAt($ttl, $now);
        if ($expiresAt !== null && $expiresAt <= $now) {
            return false;
        }
        return $this->store->add($this->key($key), serialize($value), $expiresAt, $now);
    }

    public function increment(string $key, int $by = 1, null|int|\DateInterval $ttl = null): int
    {
        $now = $this->now();
        return $this->store->increment($this->key($key), $by, $this->expiresAt($ttl, $now), $now);
    }

    public function lock(string $key, int|\DateInterval $ttl): ?LockContract
    {
        $key = $this->key($key, ':lock:');
        $now = $this->now();
        $expiresAt = $this->expiresAt($ttl, $now);
        if ($expiresAt <= $now) {
            throw new \InvalidArgumentException('A cache lock needs a positive time to live. Give seconds or a DateInterval that expires in the future.');
        }
        $token = bin2hex(random_bytes(16));
        if (!$this->store->add($key, $token, $expiresAt, $now)) {
            return null;
        }
        return new Lock($this->store, $key, $token, $this->now(...));
    }

    public function remember(string $key, null|int|\DateInterval $ttl, \Closure $make): mixed
    {
        $entry = $this->store->get($this->key($key), $this->now());
        if ($entry !== null) {
            return unserialize($entry);
        }
        $value = $make();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function delete(string $key): bool
    {
        $this->store->delete($this->key($key));
        return true;
    }

    public function clear(): bool
    {
        $this->store->clear();
        return true;
    }

    public function has(string $key): bool
    {
        return $this->store->get($this->key($key), $this->now()) !== null;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }
        return $values;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            // A key made of digits arrives from an array as an integer.
            $this->set((string) $key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    private function key(mixed $key, string $namespace = ''): string
    {
        if (!is_string($key) || $key === '' || strpbrk($key, '{}()/\\@:') !== false) {
            $shown = is_string($key) ? "\"{$key}\"" : get_debug_type($key);
            throw new InvalidKey("{$shown} cannot be a cache key. A key is text that is not empty and has none of {}()/\\@: in it; separate its parts with a dot or a dash.");
        }
        return $this->prefix . $namespace . $key;
    }

    private function expiresAt(null|int|\DateInterval $ttl, int $now): ?int
    {
        $ttl ??= $this->ttl;
        if ($ttl === null) {
            return null;
        }
        if ($ttl instanceof \DateInterval) {
            // Past a hundred million of any part, PHP's date arithmetic wraps round without a word.
            if (max(abs($ttl->y), abs($ttl->m), abs($ttl->d), abs($ttl->h), abs($ttl->i), abs($ttl->s), abs($ttl->f)) <= 100_000_000) {
                return (new \DateTimeImmutable('@' . $now))->add($ttl)->getTimestamp();
            }
        } else {
            $expiresAt = $now + $ttl;
            if (is_int($expiresAt)) {
                return $expiresAt;
            }
        }
        throw new \InvalidArgumentException('A cache time to live is too long. Its expiry must fit between Unix timestamps ' . PHP_INT_MIN . ' and ' . PHP_INT_MAX . '. Give fewer seconds or a shorter DateInterval.');
    }

    private function now(): int
    {
        return $this->clock?->now()->getTimestamp() ?? time();
    }
}
