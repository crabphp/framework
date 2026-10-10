<?php

declare(strict_types=1);

namespace Crab\Contracts\Cache;

/**
 * Where a cache keeps its entries: text by key, each with the time it
 * expires. The cache in front of it checks keys, turns values into text
 * and reads the clock, so a store only stores. Times are Unix timestamps;
 * an entry whose time has come is as good as absent.
 */
interface Store
{
    /**
     * @return string|null The entry, or null when there is none or it has expired.
     */
    public function get(string $key, int $now): ?string;

    /**
     * @param int|null $expiresAt When the entry expires; never when null.
     */
    public function set(string $key, string $value, ?int $expiresAt): void;

    /**
     * Sets the entry only when there is none that has not expired, as one
     * step with respect to every other caller of the same store.
     *
     * @return bool Whether it was set.
     */
    public function add(string $key, string $value, ?int $expiresAt, int $now): bool;

    public function delete(string $key): void;

    /**
     * Removes every entry of this store.
     */
    public function clear(): void;
}
