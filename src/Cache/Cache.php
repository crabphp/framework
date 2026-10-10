<?php

declare(strict_types=1);

namespace Crab\Cache;

use Crab\Contracts\Cache\Cache as CacheContract;
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

    public function add(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $now = $this->now();
        $expiresAt = $this->expiresAt($ttl, $now);
        if ($expiresAt !== null && $expiresAt <= $now) {
            return false;
        }
        return $this->store->add($this->key($key), serialize($value), $expiresAt, $now);
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

    private function key(mixed $key): string
    {
        if (!is_string($key) || $key === '' || strpbrk($key, '{}()/\\@:') !== false) {
            $shown = is_string($key) ? "\"{$key}\"" : get_debug_type($key);
            throw new InvalidKey("{$shown} cannot be a cache key. A key is text that is not empty and has none of {}()/\\@: in it; separate its parts with a dot or a dash.");
        }
        return $this->prefix . $key;
    }

    private function expiresAt(null|int|\DateInterval $ttl, int $now): ?int
    {
        $ttl ??= $this->ttl;
        if ($ttl === null) {
            return null;
        }
        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable('@' . $now))->add($ttl)->getTimestamp();
        }
        return $now + $ttl;
    }

    private function now(): int
    {
        return $this->clock?->now()->getTimestamp() ?? time();
    }
}
