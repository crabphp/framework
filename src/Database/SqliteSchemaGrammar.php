<?php

declare(strict_types=1);

namespace Crab\Database;

/**
 * Where SQLite's tables differ.
 */
final class SqliteSchemaGrammar extends SchemaGrammar
{
    public function transactional(): bool
    {
        return true;
    }

    public function hasTable(): string
    {
        return "select count(*) as found from sqlite_master where type = 'table' and name = ?";
    }

    public function hasColumn(): string
    {
        return 'select count(*) as found from pragma_table_info(?) where name = ?';
    }

    public function tables(): string
    {
        return "select name, type as kind from sqlite_master where type in ('table', 'view') and name not like 'sqlite\\_%' escape '\\'";
    }

    public function foreignKeysOff(): array
    {
        // foreign_keys cannot change inside a transaction; deferred checks end with it, by which time nothing is left.
        return [['pragma defer_foreign_keys = on'], []];
    }

    protected function type(Column $column): string
    {
        // SQLite stores by the kind of value; these names choose how it compares and sorts.
        return match ($column->type) {
            'foreignId', 'integer', 'bigInteger', 'boolean' => 'integer',
            'string' => "varchar({$column->length})",
            'text', 'json' => 'text',
            'decimal' => 'numeric',
            'float' => 'real',
            'date' => 'date',
            'dateTime' => 'datetime',
            'binary' => 'blob',
        };
    }

    protected function id(): string
    {
        return 'integer primary key autoincrement';
    }

    protected function dropIndex(string $table, string $name): string
    {
        return 'drop index ' . $this->quote($name);
    }

    protected function addColumn(string $table, Column $column): array
    {
        $definition = $this->column($column);
        if ($column->references !== null) {
            // SQLite cannot add a foreign key to a table afterwards, only with the column.
            $definition .= ' ' . $this->references($column);
        }
        return ['alter table ' . $this->quote($table) . ' add column ' . $definition];
    }
}
