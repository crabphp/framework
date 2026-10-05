<?php

declare(strict_types=1);

namespace Crab\Contracts\Database;

/**
 * One column of a table being created or changed, as returned by the
 * column methods of Table.
 */
interface Column
{
    /**
     * Lets the column hold no value.
     */
    public function nullable(): static;

    /**
     * The value a row gets when it is inserted without one: a string, a
     * number, a boolean or null.
     */
    public function default(string|int|float|bool|null $value): static;

    /**
     * Makes the time of insertion the default of a date-and-time column.
     */
    public function useCurrent(): static;

    /**
     * Refuses negative numbers, where the database can.
     */
    public function unsigned(): static;

    /**
     * Refuses two rows with the same value in this column.
     */
    public function unique(): static;

    /**
     * Makes looking rows up by this column fast.
     */
    public function index(): static;

    /**
     * Says the column holds the $column of a row in $table, and what
     * happens to this row when that one is deleted: "cascade" deletes it
     * too, "set null" empties the column, "restrict" refuses the delete.
     */
    public function references(string $table, string $column = 'id', ?string $onDelete = null): static;
}
