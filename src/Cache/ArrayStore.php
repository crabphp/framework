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

    public function get(string $key, int $now): ?string
    {
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

    public function add(string $key, string $value, ?int $expiresAt, int $now): bool
    {
        if ($this->get($key, $now) !== null) {
            return false;
        }
        $this->entries[$key] = [$value, $expiresAt];
        return true;
    }

    public function delete(string $key): void
    {
        unset($this->entries[$key]);
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
