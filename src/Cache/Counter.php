<?php

declare(strict_types=1);

namespace Crab\Cache;

/**
 * A whole number as a store keeps it: the text serialize() makes of it,
 * "i:5;", so that a count made by increment() reads back through get()
 * like any other value.
 */
final class Counter
{
    /**
     * Whether an entry is a whole number that can be counted on.
     */
    public static function is(?string $entry): bool
    {
        return $entry !== null && preg_match('/^i:-?\d+;$/', $entry) === 1;
    }

    /**
     * The number an entry holds; zero for one that is absent or is not a number.
     */
    public static function read(?string $entry): int
    {
        return self::is($entry) ? (int) substr((string) $entry, 2, -1) : 0;
    }

    public static function write(int $count): string
    {
        return 'i:' . $count . ';';
    }
}
