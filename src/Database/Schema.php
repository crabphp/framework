<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Schema as SchemaContract;

/**
 * Collects what a migration says about a table in a Table, has a
 * SchemaGrammar write it for the connection's database, and runs the
 * statements.
 */
final class Schema implements SchemaContract
{
    /**
     * @param SchemaGrammar|null $grammar The SQL dialect to write; left out, it follows the connection's driver.
     */
    public function __construct(private readonly Connection $connection, private ?SchemaGrammar $grammar = null)
    {
    }

    public function create(string $table, \Closure $callback): void
    {
        $definition = new Table($table);
        $callback($definition);
        if ($definition->columns === []) {
            throw new \LogicException("The table \"{$table}\" was given no columns.");
        }
        if ($definition->droppedColumns !== [] || $definition->renamedColumns !== [] || $definition->droppedIndexes !== []) {
            throw new \LogicException("The table \"{$table}\" is being created, so it has nothing to rename or drop; that belongs in table().");
        }
        $this->run($this->grammar()->create($definition));
    }

    public function table(string $table, \Closure $callback): void
    {
        $definition = new Table($table);
        $callback($definition);
        if ($definition->primary !== []) {
            throw new \LogicException("The primary key of \"{$table}\" is chosen when the table is created and cannot be changed in table().");
        }
        foreach ($definition->columns as $column) {
            if ($column->type === 'id') {
                throw new \LogicException("An id() column cannot be added to \"{$table}\" afterwards; it is part of creating the table.");
            }
        }
        $this->run($this->grammar()->alter($definition));
    }

    public function drop(string $table): void
    {
        $this->connection->execute($this->grammar()->drop($table, false));
    }

    public function dropIfExists(string $table): void
    {
        $this->connection->execute($this->grammar()->drop($table, true));
    }

    public function rename(string $from, string $to): void
    {
        $this->connection->execute($this->grammar()->rename($from, $to));
    }

    public function hasTable(string $table): bool
    {
        $row = $this->connection->first($this->grammar()->hasTable(), [$table]);
        return (int) $row['found'] > 0;
    }

    public function hasColumn(string $table, string $column): bool
    {
        $row = $this->connection->first($this->grammar()->hasColumn(), [$table, $column]);
        return (int) $row['found'] > 0;
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    /**
     * @param list<string> $statements
     */
    private function run(array $statements): void
    {
        foreach ($statements as $statement) {
            $this->connection->execute($statement);
        }
    }

    private function grammar(): SchemaGrammar
    {
        return $this->grammar ??= SchemaGrammar::for($this->connection->driver());
    }
}
