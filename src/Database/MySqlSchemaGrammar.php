<?php

declare(strict_types=1);

namespace Crab\Database;

/**
 * Where MySQL's tables differ.
 */
final class MySqlSchemaGrammar extends SchemaGrammar
{
    public function transactional(): bool
    {
        // MySQL commits by itself before and after each statement that changes a table.
        return false;
    }

    public function hasTable(): string
    {
        return 'select count(*) as found from information_schema.tables where table_schema = database() and table_name = ?';
    }

    public function hasColumn(): string
    {
        return 'select count(*) as found from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?';
    }

    protected function type(Column $column): string
    {
        $type = match ($column->type) {
            'foreignId' => 'bigint unsigned',
            'integer' => 'int',
            'bigInteger' => 'bigint',
            'string' => "varchar({$column->length})",
            'text' => 'text',
            'boolean' => 'tinyint(1)',
            'decimal' => "decimal({$column->length}, {$column->places})",
            'float' => 'double',
            'date' => 'date',
            'dateTime' => 'datetime',
            'json' => 'json',
            'binary' => 'blob',
        };
        $signed = in_array($column->type, ['integer', 'bigInteger', 'decimal', 'float'], true);
        return $column->unsigned && $signed ? $type . ' unsigned' : $type;
    }

    protected function id(): string
    {
        return 'bigint unsigned not null auto_increment primary key';
    }

    protected function dropIndex(string $table, string $name): string
    {
        return 'drop index ' . $this->quote($name) . ' on ' . $this->quote($table);
    }

    protected function addColumn(string $table, Column $column): array
    {
        $statements = parent::addColumn($table, $column);
        if ($column->references !== null) {
            // MySQL reads a reference written on the column itself and then ignores it.
            $statements[] =
                'alter table '
                . $this->quote($table)
                . ' add foreign key ('
                . $this->quote($column->name)
                . ') '
                . $this->references($column);
        }
        return $statements;
    }

    protected function literal(string|int|float|bool|null $value): string
    {
        // A backslash escapes the next character inside a MySQL string.
        return is_string($value)
            ? "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'"
            : parent::literal($value);
    }
}
