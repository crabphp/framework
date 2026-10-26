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
            default => throw new DatabaseException(
                "The query builder does not support the \"{$driver}\" driver; MySQL and SQLite are supported.",
            ),
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

    /**
     * The value at a path of a JSON column, as it is compared and read.
     *
     * @param string $column Quoted.
     * @param string $path A JSON path as an SQL string, '$."actor"."id"'.
     */
    abstract protected function json(string $column, string $path): string;

    /**
     * Whether a path of a JSON column holds nothing, or JSON's null.
     */
    protected function jsonNull(string $column, string $path, bool $not): string
    {
        return $this->json($column, $path) . ($not ? ' is not null' : ' is null');
    }

    /**
     * A path of a JSON column compared with true or false, which JSON
     * holds as its own kind of value.
     */
    protected function jsonBool(string $column, string $path, string $operator, bool $value): string
    {
        return $this->json($column, $path) . " {$operator} " . ($value ? '1' : '0');
    }

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
        $shaped =
            $query->distinct
            || $query->groups !== []
            || $query->havings !== []
            || $query->limit !== null
            || $query->offset !== null;
        // Over rows worked out first, a column is known by its own name: its table's is not in scope out there.
        $named = $shaped && $column !== '*' && str_contains($column, '.')
            ? substr($column, strrpos($column, '.') + 1)
            : $column;
        $sql = "select {$function}(" . $this->wrap($named) . ') as ' . $this->quote('aggregate');
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
        $sql =
            'update '
            . $this->wrap($query->table)
            . ' set '
            . implode(', ', $sets)
            . $this->wheres($query->wheres, $bindings);
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
        $json = $this->jsonPath($name);
        if ($json !== null) {
            return $this->json(...$json);
        }
        $parts = [];
        foreach (explode('.', trim($name)) as $part) {
            $parts[] = $part === '*' ? '*' : $this->quote($part);
        }
        return implode('.', $parts);
    }

    /**
     * A column named with a path into its JSON, "data->actor->id", as the
     * column quoted and the path as an SQL string; null for any other name.
     * The path is written into the SQL, so each key is held to letters,
     * digits, _ and -, with [0] for an element of a list.
     *
     * @return array{string, string}|null
     */
    private function jsonPath(string $name): ?array
    {
        if (!str_contains($name, '->')) {
            return null;
        }
        $keys = explode('->', trim($name));
        $column = array_shift($keys);
        $path = '$';
        foreach ($keys as $key) {
            if (preg_match('/^([A-Za-z0-9_-]+)((?:\[\d+\])*)$/', $key, $match) !== 1) {
                throw new \InvalidArgumentException(
                    "\"{$key}\" in \"{$name}\" is not a key of a JSON path: "
                    . 'letters, digits, _ and -, then [0] for an element of a list.',
                );
            }
            $path .= ".\"{$match[1]}\"{$match[2]}";
        }
        return [$this->wrap($column), "'{$path}'"];
    }

    /**
     * A query inside this one, in parentheses, its values bound where it stands.
     *
     * @param list<mixed> $bindings
     */
    private function subquery(QueryContract $query, array &$bindings): string
    {
        if ($query instanceof Query) {
            // Written in this statement's dialect, whichever connection it was started from.
            return '(' . $this->selectSql($query, $bindings) . ')';
        }
        array_push($bindings, ...$query->bindings());
        return '(' . $query->toSql() . ')';
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
     * @param list<string|Expression|Subquery> $columns
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
        $sql =
            ' from '
            . (
                $query->source === null
                    ? $this->wrap($query->table)
                    : $this->subquery($query->source, $bindings) . ' as ' . $this->quote($query->table)
            );
        foreach ($query->joins as $join) {
            $table = $join['table'];
            $sql .=
                " {$join['type']} join "
                . (
                    $table instanceof Subquery
                        ? $this->subquery($table->query, $bindings) . ' as ' . $this->quote($table->as)
                        : $this->wrap($table)
                )
                . ' on '
                . $this->conditions($join['wheres'], $bindings);
        }
        $sql .= $this->wheres($query->wheres, $bindings);
        if ($query->groups !== []) {
            $groups = [];
            foreach ($query->groups as $group) {
                $groups[] = $this->written($group, $bindings);
            }
            $sql .= ' group by ' . implode(', ', $groups);
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
                $json = is_bool($condition['value']) ? $this->jsonPath($condition['column']) : null;
                if ($json !== null) {
                    return $this->jsonBool($json[0], $json[1], $condition['operator'], $condition['value']);
                }
                $bindings[] = $condition['value'];
                return $this->wrap($condition['column']) . " {$condition['operator']} ?";
            case 'sub':
                return (
                    $this->wrap($condition['column'])
                    . " {$condition['operator']} "
                    . $this->subquery($condition['query'], $bindings)
                );
            case 'exists':
                return (
                    ($condition['not'] ? 'not exists ' : 'exists ') . $this->subquery($condition['query'], $bindings)
                );
            case 'column':
                return (
                    $this->wrap($condition['first']) . " {$condition['operator']} " . $this->wrap($condition['second'])
                );
            case 'null':
                $json = $this->jsonPath($condition['column']);
                if ($json !== null) {
                    return $this->jsonNull($json[0], $json[1], $condition['not']);
                }
                return $this->wrap($condition['column']) . ($condition['not'] ? ' is not null' : ' is null');
            case 'between':
                $bindings[] = $condition['from'];
                $bindings[] = $condition['to'];
                return (
                    $this->wrap($condition['column'])
                    . ($condition['not'] ? ' not between ? and ?' : ' between ? and ?')
                );
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
            return $this->wrap($condition['column']) . rtrim($keyword, '(') . $this->subquery($values, $bindings);
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
    private function written(string|Expression|Subquery $column, array &$bindings): string
    {
        if (is_string($column)) {
            return $this->wrap($column);
        }
        if ($column instanceof Subquery) {
            return $this->subquery($column->query, $bindings) . ' as ' . $this->quote($column->as);
        }
        array_push($bindings, ...$column->bindings);
        return $column->sql;
    }

    private function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, '?'));
    }
}
