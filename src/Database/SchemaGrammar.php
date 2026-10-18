<?php

declare(strict_types=1);

namespace Crab\Database;

/**
 * Writes the statements that create and change tables. Everything the
 * supported databases agree on is here; a subclass says only where its
 * database differs.
 */
abstract class SchemaGrammar
{
    /**
     * The grammar for a connection's driver.
     */
    public static function for(string $driver): self
    {
        return match ($driver) {
            'mysql' => new MySqlSchemaGrammar(),
            'sqlite' => new SqliteSchemaGrammar(),
            default => throw new DatabaseException(
                "The schema builder does not support the \"{$driver}\" driver; MySQL and SQLite are supported.",
            ),
        };
    }

    /**
     * Whether creating and changing tables can be rolled back with the
     * transaction they ran in.
     */
    abstract public function transactional(): bool;

    /**
     * A query, with one ? for the table, whose "found" is how many tables
     * of that name there are.
     */
    abstract public function hasTable(): string;

    /**
     * The same for a column, with a ? for the table and then the column.
     */
    abstract public function hasColumn(): string;

    /**
     * The database's type for a column, such as "varchar(255)".
     */
    abstract protected function type(Column $column): string;

    /**
     * Everything after the name of an id() column.
     */
    abstract protected function id(): string;

    abstract protected function dropIndex(string $table, string $name): string;

    /**
     * @return list<string>
     */
    public function create(Table $table): array
    {
        $lines = [];
        foreach ($table->columns as $column) {
            $lines[] = $this->column($column);
        }
        if ($table->primary !== []) {
            $lines[] = 'primary key (' . $this->names($table->primary) . ')';
        }
        foreach ($table->columns as $column) {
            if ($column->references === null) {
                continue;
            }

            $lines[] = 'foreign key (' . $this->quote($column->name) . ') ' . $this->references($column);
        }
        $statements = ['create table ' . $this->quote($table->name) . ' (' . implode(', ', $lines) . ')'];
        return [...$statements, ...$this->indexes($table)];
    }

    /**
     * In an order that works when one change touches what another did:
     * renames and drops first, then what is added.
     *
     * @return list<string>
     */
    public function alter(Table $table): array
    {
        $name = $this->quote($table->name);
        $statements = [];
        foreach ($table->renamedColumns as $from => $to) {
            $statements[] = "alter table {$name} rename column " . $this->quote($from) . ' to ' . $this->quote($to);
        }
        foreach ($table->droppedIndexes as $index) {
            $statements[] = $this->dropIndex($table->name, $index);
        }
        foreach ($table->droppedColumns as $column) {
            $statements[] = "alter table {$name} drop column " . $this->quote($column);
        }
        foreach ($table->columns as $column) {
            $statements = [...$statements, ...$this->addColumn($table->name, $column)];
        }
        return [...$statements, ...$this->indexes($table)];
    }

    public function drop(string $table, bool $ifExists): string
    {
        return ($ifExists ? 'drop table if exists ' : 'drop table ') . $this->quote($table);
    }

    public function rename(string $from, string $to): string
    {
        return 'alter table ' . $this->quote($from) . ' rename to ' . $this->quote($to);
    }

    /**
     * The statements that add one column to a table that exists.
     *
     * @return list<string>
     */
    protected function addColumn(string $table, Column $column): array
    {
        return ['alter table ' . $this->quote($table) . ' add column ' . $this->column($column)];
    }

    /**
     * A column as it is written inside create table or add column.
     */
    protected function column(Column $column): string
    {
        $sql = $this->quote($column->name) . ' ';
        if ($column->type === 'id') {
            return $sql . $this->id();
        }
        $sql .= $this->type($column);
        $sql .= $column->nullable ? ' null' : ' not null';
        if ($column->useCurrent) {
            $sql .= ' default current_timestamp';
        } elseif ($column->hasDefault) {
            $sql .= ' default ' . $this->literal($column->default);
        }
        return $sql;
    }

    /**
     * "references users (id) on delete cascade", for a column that points at another table.
     */
    protected function references(Column $column): string
    {
        $sql =
            'references '
            . $this->quote($column->references['table'])
            . ' ('
            . $this->quote($column->references['column'])
            . ')';
        if ($column->references['onDelete'] !== null) {
            $sql .= ' on delete ' . $column->references['onDelete'];
        }
        return $sql;
    }

    /**
     * A default value written into the statement, which cannot bind it.
     */
    protected function literal(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? '1' : '0',
            is_string($value) => "'" . str_replace("'", "''", $value) . "'",
            default => (string) $value,
        };
    }

    protected function quote(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * @param list<string> $columns
     */
    protected function names(array $columns): string
    {
        return implode(', ', array_map($this->quote(...), $columns));
    }

    /**
     * One statement for each index, those said on a column first.
     *
     * @return list<string>
     */
    private function indexes(Table $table): array
    {
        $indexes = [];
        foreach ($table->columns as $column) {
            if ($column->unique) {
                $indexes[] = [
                    'unique' => true,
                    'columns' => [$column->name],
                    'name' => $table->indexName([$column->name], 'unique'),
                ];
            }
            if ($column->index) {
                $indexes[] = [
                    'unique' => false,
                    'columns' => [$column->name],
                    'name' => $table->indexName([$column->name], 'index'),
                ];
            }
        }
        $statements = [];
        foreach ([...$indexes, ...$table->indexes] as $index) {
            $statements[] =
                ($index['unique'] ? 'create unique index ' : 'create index ')
                . $this->quote($index['name'])
                . ' on '
                . $this->quote($table->name)
                . ' ('
                . $this->names($index['columns'])
                . ')';
        }
        return $statements;
    }
}
