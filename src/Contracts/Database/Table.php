<?php

declare(strict_types=1);

namespace Crab\Contracts\Database;

/**
 * A table being created or changed. Each column method adds a column and
 * returns it, to say more about it: $table->string('email')->unique().
 * A column must have a value unless it is made nullable().
 */
interface Table
{
    /**
     * A whole number that counts up by itself and is the primary key.
     */
    public function id(string $name = 'id'): Column;

    /**
     * A column holding the id of a row in another table. Say which with
     * references(): $table->foreignId('user_id')->references('users').
     */
    public function foreignId(string $name): Column;

    public function integer(string $name): Column;

    /**
     * A whole number too large for integer(), beyond about two billion.
     */
    public function bigInteger(string $name): Column;

    /**
     * Short text, up to $length characters.
     */
    public function string(string $name, int $length = 255): Column;

    /**
     * Text of any length.
     */
    public function text(string $name): Column;

    public function boolean(string $name): Column;

    /**
     * An exact number, for money: $total digits in all, $places of them
     * after the point.
     */
    public function decimal(string $name, int $total = 10, int $places = 2): Column;

    /**
     * An approximate number with a fraction, for measurements.
     */
    public function float(string $name): Column;

    public function date(string $name): Column;

    public function dateTime(string $name): Column;

    /**
     * JSON text. Encode and decode it yourself; MySQL also checks it.
     */
    public function json(string $name): Column;

    /**
     * Raw bytes, of any length.
     */
    public function binary(string $name): Column;

    /**
     * The nullable date-and-time columns created_at and updated_at.
     */
    public function timestamps(): void;

    /**
     * An index over one column or several, to make looking rows up by
     * them fast. It is named {table}_{columns}_index unless named here.
     *
     * @param string|list<string> $columns
     */
    public function index(string|array $columns, ?string $name = null): void;

    /**
     * The same, also refusing two rows with the same values. It is named
     * {table}_{columns}_unique unless named here.
     *
     * @param string|list<string> $columns
     */
    public function unique(string|array $columns, ?string $name = null): void;

    /**
     * A primary key made of several columns, for a table without id().
     * Only when creating a table.
     *
     * @param list<string> $columns
     */
    public function primary(array $columns): void;

    /**
     * Only when changing a table.
     */
    public function dropColumn(string ...$columns): void;

    /**
     * Only when changing a table.
     */
    public function renameColumn(string $from, string $to): void;

    /**
     * Drops the index over these columns, or the index of this name.
     * Only when changing a table.
     *
     * @param string|list<string> $columns
     */
    public function dropIndex(string|array $columns): void;

    /**
     * Drops the unique index over these columns, or the one of this name.
     * Only when changing a table.
     *
     * @param string|list<string> $columns
     */
    public function dropUnique(string|array $columns): void;
}
