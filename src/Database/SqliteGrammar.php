<?php

declare(strict_types=1);

namespace Crab\Database;

/**
 * Where SQLite's SQL differs.
 */
final class SqliteGrammar extends Grammar
{
    protected function insertOrIgnoreInto(): string
    {
        return 'insert or ignore into';
    }

    protected function onConflict(array $uniqueBy, array $update): string
    {
        $sets = [];
        foreach ($update as $column) {
            $name = $this->quote($column);
            $sets[] = "{$name} = excluded.{$name}";
        }
        $key = implode(', ', array_map($this->quote(...), $uniqueBy));
        return "on conflict ({$key}) do update set " . implode(', ', $sets);
    }

    protected function noLimit(): string
    {
        return '-1';
    }

    protected function lock(): string
    {
        // SQLite locks the whole database for a writing transaction; it has no row locks to ask for.
        return '';
    }

    protected function json(string $column, string $path): string
    {
        // A string comes out as text, a number as a number, true and false as 1 and 0, and JSON's null as null.
        return "json_extract({$column}, {$path})";
    }
}
