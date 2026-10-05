<?php

declare(strict_types=1);

namespace Crab\Contracts\Database;

/**
 * Runs migration files in the order of their names and remembers, in the
 * database, which have run. A package adds its own files with path().
 */
interface Migrator
{
    /**
     * Adds a directory of migration files.
     */
    public function path(string $directory): void;

    /**
     * Runs every migration that has not run yet, as one batch.
     *
     * @return list<string> The names of those it ran, in order.
     */
    public function run(): array;

    /**
     * Takes back the most recent batch, newest first.
     *
     * @return list<string> The names of those it took back, in order.
     */
    public function rollback(): array;

    /**
     * @return array<string, int|null> Every migration by name, with the batch it ran in, or null when it has not run.
     */
    public function status(): array;

    /**
     * Writes a new, empty migration file named after the time and $name.
     *
     * @param string $name What it does, in words: "create users table".
     * @return string The path of the file.
     */
    public function make(string $name): string;
}
