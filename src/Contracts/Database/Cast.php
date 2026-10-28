<?php

declare(strict_types=1);

namespace Crab\Contracts\Database;

/**
 * Turns a column's value into a record's property and back, for a type
 * the mapper does not know by itself. Written as an attribute on the
 * constructor parameter, as #[Json] is:
 *
 *     #[\Attribute(\Attribute::TARGET_PARAMETER)]
 *     final readonly class AsMoney implements Cast { ... }
 *
 *     public function __construct(#[AsMoney] public Money $price) {}
 *
 * Null never reaches a cast: it is null in the column and in the property.
 */
interface Cast
{
    /**
     * What the database holds, as the property's value.
     *
     * @throws \UnexpectedValueException When the value cannot be read; say what was expected, never the value.
     */
    public function read(mixed $value): mixed;

    /**
     * The property's value, as the database stores it: a string, an int, a float or a bool.
     */
    public function write(mixed $value): mixed;
}
