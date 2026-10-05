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
}
