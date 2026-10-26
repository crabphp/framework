<?php

declare(strict_types=1);

namespace Crab\Database;

/**
 * Where MySQL's SQL differs.
 */
final class MySqlGrammar extends Grammar
{
    protected function insertOrIgnoreInto(): string
    {
        return 'insert ignore into';
    }

    protected function onConflict(array $uniqueBy, array $update): string
    {
        // MySQL finds the existing row by whichever unique key the new one breaks, so $uniqueBy is not written.
        $sets = [];
        foreach ($update as $column) {
            $name = $this->quote($column);
            $sets[] = "{$name} = values({$name})";
        }
        return 'on duplicate key update ' . implode(', ', $sets);
    }

    protected function noLimit(): string
    {
        return '18446744073709551615';
    }

    protected function lock(): string
    {
        return ' for update';
    }

    protected function json(string $column, string $path): string
    {
        return "json_unquote(json_extract({$column}, {$path}))";
    }

    protected function jsonNull(string $column, string $path, bool $not): string
    {
        // A JSON null is a value to MySQL, unlike a key that is not there; both are null here.
        $value = "json_extract({$column}, {$path})";
        return $not
            ? "({$value} is not null and json_type({$value}) <> 'NULL')"
            : "({$value} is null or json_type({$value}) = 'NULL')";
    }

    protected function jsonBool(string $column, string $path, string $operator, bool $value): string
    {
        // Unquoted, true is the text "true"; compared as JSON, it is true.
        return "json_extract({$column}, {$path}) {$operator} " . ($value ? 'true' : 'false');
    }
}
