<?php

declare(strict_types=1);

namespace Crab\Cache;

use Crab\Contracts\Cache\Store;

/**
 * Entries kept in this object's memory. They are gone when the worker
 * stops and no other worker sees them, so it suits tests and values that
 * each worker may as well make for itself.
 */
final class ArrayStore implements Store
{
    /**
     * @var array<string, array{string, ?int}> The entry and when it expires, by key.
     */
    private array $entries = [];
    private ?int $prunedAt = null;

    /**
     * Reclaim expired keys during activity, at most once per five seconds.
     * Cache supplies the time for writes, whose store contract has no clock.
     *
     * @internal
     */
    public function pruneIfDue(int $now): void
    {
        if ($this->prunedAt !== null && $now >= $this->prunedAt && ($now - $this->prunedAt) < 5) {
            return;
        }
        $this->prune($now);
    }

    public function get(string $key, int $now): ?string
    {
        $this->pruneIfDue($now);
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($entry[1] !== null && $entry[1] <= $now) {
            unset($this->entries[$key]);
            return null;
        }
        return $entry[0];
    }

    public function set(string $key, string $value, ?int $expiresAt): void
    {
        $this->entries[$key] = [$value, $expiresAt];
    }

    public function take(string $key, int $now): ?string
    {
        $this->pruneIfDue($now);
        $entry = $this->entries[$key] ?? null;
        unset($this->entries[$key]);
        return $entry === null || $entry[1] !== null && $entry[1] <= $now ? null : $entry[0];
    }

    public function add(string $key, string $value, ?int $expiresAt, int $now): bool
    {
        if ($this->get($key, $now) !== null) {
            return false;
        }
        $this->entries[$key] = [$value, $expiresAt];
        return true;
    }

    public function increment(string $key, int $by, ?int $expiresAt, int $now): int
    {
        $entry = $this->get($key, $now);
        $count = Counter::read($entry) + $by;
        $this->entries[$key] = [Counter::write($count), Counter::is($entry) ? $this->entries[$key][1] : $expiresAt];
        return $count;
    }

    public function delete(string $key): void
    {
        unset($this->entries[$key]);
    }

    public function deleteIf(string $key, string $value, int $now): bool
    {
        $this->pruneIfDue($now);
        $entry = $this->entries[$key] ?? null;
        if ($entry === null || $entry[1] !== null && $entry[1] <= $now || $entry[0] !== $value) {
            return false;
        }
        unset($this->entries[$key]);
        return true;
    }

    public function extendIf(string $key, string $value, int $expiresAt, int $now): bool
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null || $entry[1] !== null && $entry[1] <= $now || $entry[0] !== $value) {
            return false;
        }
        $this->entries[$key] = [$value, $expiresAt];
        return true;
    }

    public function prune(int $now): int
    {
        $this->prunedAt = $now;
        $removed = 0;
        foreach ($this->entries as $key => $entry) {
            if (!($entry[1] !== null && $entry[1] <= $now)) {
                continue;
            }

            unset($this->entries[$key]);
            ++$removed;
        }
        return $removed;
    }

    public function clear(string $prefix = ''): void
    {
        $this->prunedAt = null;
        if ($prefix === '') {
            $this->entries = [];
            return;
        }
        foreach (array_keys($this->entries) as $key) {
            if (!str_starts_with((string) $key, $prefix)) {
                continue;
            }
            unset($this->entries[$key]);
        }
    }
}
