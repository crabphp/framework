<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Database\Query as QueryContract;

/**
 * Writes a query as SQL. Everything the supported databases agree on is
 * here; a subclass says only where its database differs.
 *
 * Each method that writes part of a statement appends that part's values
 * to $bindings as it goes, so they end up in the order of their
 * placeholders.
 */
abstract class Grammar
{
    /**
     * The grammar for a connection's driver.
     */
    public static function for(string $driver): self
    {
        return match ($driver) {
            'mysql' => new MySqlGrammar(),
            'sqlite' => new SqliteGrammar(),
            default => throw new DatabaseException("The query builder does not support the \"{$driver}\" driver; MySQL and SQLite are supported."),
        };
    }

    /**
     * One name, quoted as an identifier. MySQL and SQLite both take
     * backticks, and SQLite reads a double-quoted name it does not know as
     * a string, which would turn a mistyped column into a silent mismatch.
     */
    protected function quote(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * The start of an INSERT that skips rows breaking a unique key.
     */
    abstract protected function insertOrIgnoreInto(): string;

    /**
     * The end of an INSERT that updates the row that is already there.
     *
     * @param list<string> $uniqueBy
     * @param list<string> $update
     */
    abstract protected function onConflict(array $uniqueBy, array $update): string;

    /**
     * What stands for "no limit" when a query has an offset alone.
     */
    abstract protected function noLimit(): string;

    /**
     * What locks the rows a SELECT reads, with its leading space; empty
     * where the database has no row locks.
     */
    abstract protected function lock(): string;

    public function select(Query $query): Statement
    {
        $bindings = [];
        $sql = $this->selectSql($query, $bindings);
        if ($query->lock) {
            $sql .= $this->lock();
        }
        return new Statement($sql, $bindings);
    }

    public function exists(Query $query): Statement
    {
        $bindings = [];
        $sql = 'select exists(' . $this->selectSql($query, $bindings) . ') as ' . $this->quote('found');
        return new Statement($sql, $bindings);
    }

    /**
     * @param string $function count, sum, avg, min or max.
     */
    public function aggregate(Query $query, string $function, string $column): Statement
    {
        $bindings = [];
        $sql = "select {$function}(" . $this->wrap($column) . ') as ' . $this->quote('aggregate');
        $shaped = $query->distinct || $query->groups !== [] || $query->havings !== [] || $query->limit !== null || $query->offset !== null;
        if ($shaped) {
            // These change which rows there are, so the rows are worked out first and aggregated after.
            $sql .= ' from (' . $this->selectSql($query, $bindings) . ') as ' . $this->quote('crab_aggregate');
        } else {
            $sql .= $this->from($query, $bindings);
        }
        return new Statement($sql, $bindings);
    }

    /**
     * @param non-empty-list<array<string, mixed>> $rows Rows that all have the same columns in the same order.
     */
    public function insert(Query $query, array $rows): Statement
    {
        $bindings = [];
        $sql = 'insert into ' . $this->values($query, $rows, $bindings);
        return new Statement($sql, $bindings);
    }

    /**
     * @param non-empty-list<array<string, mixed>> $rows
     */
    public function insertOrIgnore(Query $query, array $rows): Statement
    {
        $bindings = [];
        $sql = $this->insertOrIgnoreInto() . ' ' . $this->values($query, $rows, $bindings);
        return new Statement($sql, $bindings);
    }

    /**
     * @param non-empty-list<array<string, mixed>> $rows
     * @param non-empty-list<string> $uniqueBy
     * @param non-empty-list<string> $update
     */
    public function upsert(Query $query, array $rows, array $uniqueBy, array $update): Statement
    {
        $bindings = [];
        $sql = 'insert into ' . $this->values($query, $rows, $bindings) . ' ' . $this->onConflict($uniqueBy, $update);
        return new Statement($sql, $bindings);
    }

    /**
     * @param array<string, mixed> $values Columns to set.
     * @param array<string, int|float> $increments Columns to add an amount to.
     */
    public function update(Query $query, array $values, array $increments): Statement
    {
        $bindings = [];
        $sets = [];
        foreach ($increments as $column => $amount) {
            $name = $this->wrap($column);
            $sets[] = "{$name} = {$name} + ?";
            $bindings[] = $amount;
        }
        foreach ($values as $column => $value) {
            $sets[] = $this->wrap($column) . ' = ?';
            $bindings[] = $value;
        }
        $sql = 'update ' . $this->wrap($query->table) . ' set ' . implode(', ', $sets) . $this->wheres($query->wheres, $bindings);
        return new Statement($sql, $bindings);
    }

    public function delete(Query $query): Statement
    {
        $bindings = [];
        $sql = 'delete from ' . $this->wrap($query->table) . $this->wheres($query->wheres, $bindings);
        return new Statement($sql, $bindings);
    }

    /**
     * A column or table as the user wrote it, quoted part by part:
     * "users.name as n" becomes "users"."name" as "n", and * stays *.
     */
    protected function wrap(string $name): string
    {
        $alias = stripos($name, ' as ');
        if ($alias !== false) {
            return $this->wrap(substr($name, 0, $alias)) . ' as ' . $this->quote(trim(substr($name, $alias + 4)));
        }
        $parts = [];
        foreach (explode('.', trim($name)) as $part) {
            $parts[] = $part === '*' ? '*' : $this->quote($part);
        }
        return implode('.', $parts);
    }

    /**
     * A whole SELECT, without the lock.
     *
     * @param list<mixed> $bindings
     */
    private function selectSql(Query $query, array &$bindings): string
    {
        $sql = $query->distinct ? 'select distinct ' : 'select ';
        $sql .= $this->columns($query->columns, $bindings);
        $sql .= $this->from($query, $bindings);
        $sql .= $this->orders($query->orders, $bindings);
        $sql .= $this->limit($query, $bindings);
        return $sql;
    }

    /**
     * @param list<string|Expression> $columns
     * @param list<mixed> $bindings
     */
    private function columns(array $columns, array &$bindings): string
    {
        if ($columns === []) {
            return '*';
        }
        $written = [];
        foreach ($columns as $column) {
            $written[] = $this->written($column, $bindings);
        }
        return implode(', ', $written);
    }

    /**
     * Which rows: the table, its joins, the conditions and the grouping.
     *
     * @param list<mixed> $bindings
     */
    private function from(Query $query, array &$bindings): string
    {
        $sql = ' from ' . $this->wrap($query->table);
        foreach ($query->joins as $join) {
            $sql .= " {$join['type']} join " . $this->wrap($join['table']) . ' on ' . $this->conditions($join['wheres'], $bindings);
        }
        $sql .= $this->wheres($query->wheres, $bindings);
        if ($query->groups !== []) {
            $sql .= ' group by ' . implode(', ', array_map($this->wrap(...), $query->groups));
        }
        if ($query->havings !== []) {
            $sql .= ' having ' . $this->conditions($query->havings, $bindings);
        }
        return $sql;
    }

    /**
     * @param list<array<string, mixed>> $wheres
     * @param list<mixed> $bindings
     */
    private function wheres(array $wheres, array &$bindings): string
    {
        return $wheres === [] ? '' : ' where ' . $this->conditions($wheres, $bindings);
    }

    /**
     * @param non-empty-list<array<string, mixed>> $conditions
     * @param list<mixed> $bindings
     */
    private function conditions(array $conditions, array &$bindings): string
    {
        $sql = '';
        foreach ($conditions as $index => $condition) {
            if ($index > 0) {
                $sql .= " {$condition['boolean']} ";
            }
            $sql .= $this->condition($condition, $bindings);
        }
        return $sql;
    }

    /**
     * @param array<string, mixed> $condition
     * @param list<mixed> $bindings
     */
    private function condition(array $condition, array &$bindings): string
    {
        switch ($condition['type']) {
            case 'basic':
                $bindings[] = $condition['value'];
                return $this->wrap($condition['column']) . " {$condition['operator']} ?";
            case 'column':
                return $this->wrap($condition['first']) . " {$condition['operator']} " . $this->wrap($condition['second']);
            case 'null':
                return $this->wrap($condition['column']) . ($condition['not'] ? ' is not null' : ' is null');
            case 'between':
                $bindings[] = $condition['from'];
                $bindings[] = $condition['to'];
                return $this->wrap($condition['column']) . ($condition['not'] ? ' not between ? and ?' : ' between ? and ?');
            case 'in':
                return $this->in($condition, $bindings);
            case 'nested':
                return '(' . $this->conditions($condition['wheres'], $bindings) . ')';
            case 'raw':
                return '(' . $this->written($condition['expression'], $bindings) . ')';
        }
        throw new \LogicException("A query holds a condition of unknown type \"{$condition['type']}\".");
    }

    /**
     * @param array<string, mixed> $condition
     * @param list<mixed> $bindings
     */
    private function in(array $condition, array &$bindings): string
    {
        $values = $condition['values'];
        $keyword = $condition['not'] ? ' not in (' : ' in (';
        if ($values instanceof QueryContract) {
            array_push($bindings, ...$values->bindings());
            return $this->wrap($condition['column']) . $keyword . $values->toSql() . ')';
        }
        if ($values === []) {
            // "in ()" is not SQL: nothing is in an empty list, and everything is outside it.
            return $condition['not'] ? '1 = 1' : '0 = 1';
        }
        array_push($bindings, ...$values);
        return $this->wrap($condition['column']) . $keyword . $this->placeholders(count($values)) . ')';
    }

    /**
     * @param list<array{column: string|Expression, direction: string}> $orders
     * @param list<mixed> $bindings
     */
    private function orders(array $orders, array &$bindings): string
    {
        if ($orders === []) {
            return '';
        }
        $written = [];
        foreach ($orders as $order) {
            $written[] = rtrim($this->written($order['column'], $bindings) . ' ' . $order['direction']);
        }
        return ' order by ' . implode(', ', $written);
    }

    /**
     * @param list<mixed> $bindings
     */
    private function limit(Query $query, array &$bindings): string
    {
        if ($query->limit === null && $query->offset === null) {
            return '';
        }
        if ($query->limit === null) {
            $sql = ' limit ' . $this->noLimit();
        } else {
            $sql = ' limit ?';
            $bindings[] = $query->limit;
        }
        if ($query->offset !== null) {
            $sql .= ' offset ?';
            $bindings[] = $query->offset;
        }
        return $sql;
    }

    /**
     * The table, its columns and a row of placeholders for each row.
     *
     * @param non-empty-list<array<string, mixed>> $rows
     * @param list<mixed> $bindings
     */
    private function values(Query $query, array $rows, array &$bindings): string
    {
        $columns = array_keys($rows[0]);
        $row = '(' . $this->placeholders(count($columns)) . ')';
        foreach ($rows as $values) {
            array_push($bindings, ...array_values($values));
        }
        $names = implode(', ', array_map($this->quote(...), $columns));
        return $this->wrap($query->table) . " ({$names}) values " . implode(', ', array_fill(0, count($rows), $row));
    }

    /**
     * A column quoted, or an expression as written with its values bound.
     *
     * @param list<mixed> $bindings
     */
    private function written(string|Expression $column, array &$bindings): string
    {
        if (is_string($column)) {
            return $this->wrap($column);
        }
        array_push($bindings, ...$column->bindings);
        return $column->sql;
    }

    private function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, '?'));
    }
}
