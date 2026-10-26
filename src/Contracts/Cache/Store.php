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
     * Returns and removes a live entry as one step with respect to every
     * caller of this store. Missing or expired entries return null.
     */
    public function take(string $key, int $now): ?string;

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

    /**
     * Adds to the whole number at the key and returns what it now is, as
     * one step with respect to every other caller of the same store. The
     * number is kept as the text serialize() makes of it, "i:5;", which
     * is what get() then returns. An entry that is absent, expired or not
     * such a number counts from zero and expires at the time given; one
     * that is counted on keeps the time it has.
     */
    public function increment(string $key, int $by, ?int $expiresAt, int $now): int;

    public function delete(string $key): void;

    /**
     * Deletes a live entry only when its text is exactly the given value,
     * as one step with respect to every other caller of the same store.
     * Returns whether this call removed it.
     */
    public function deleteIf(string $key, string $value, int $now): bool;

    /**
     * Gives a live entry a new expiry only while its text is exactly the
     * given value, as one step with respect to every other caller of the
     * same store. Returns whether this call extended it; an entry that
     * is missing, expired or holds other text is left as it is.
     */
    public function extendIf(string $key, string $value, int $expiresAt, int $now): bool;

    /**
     * Removes every expired entry of this store and returns how many
     * were removed. Entries that never expire are kept.
     */
    public function prune(int $now): int;

    /**
     * Removes every entry whose key starts with the prefix, expired or
     * not; every entry of this store when the prefix is empty.
     */
    public function clear(string $prefix = ''): void;
}
