<?php

declare(strict_types=1);

namespace Crab\Contracts\Database;

/**
 * Creates and changes tables, written once for every supported database.
 * A migration receives one.
 */
interface Schema
{
    /**
     * Creates a table. The callback receives the table and declares its
     * columns and indexes:
     *
     *     $schema->create('users', function (Table $table) {
     *         $table->id();
     *         $table->string('email')->unique();
     *         $table->timestamps();
     *     });
     *
     * @param \Closure(Table): mixed $callback
     */
    public function create(string $table, \Closure $callback): void;

    /**
     * Changes a table that exists: the callback adds, renames and drops
     * columns and indexes.
     *
     * @param \Closure(Table): mixed $callback
     */
    public function table(string $table, \Closure $callback): void;

    public function drop(string $table): void;

    public function dropIfExists(string $table): void;

    public function rename(string $from, string $to): void;

    public function hasTable(string $table): bool;

    public function hasColumn(string $table, string $column): bool;

    /**
     * The connection the schema works on, for a migration that also has
     * rows to move or fill.
     */
    public function connection(): Connection;
}
