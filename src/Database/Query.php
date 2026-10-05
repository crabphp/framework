<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Page;
use Crab\Contracts\Database\Query as QueryContract;

/**
 * Collects what a statement should say and hands it to a Grammar to be
 * written as SQL when it runs. It holds no SQL of its own, so the same
 * query runs on MySQL and on SQLite.
 */
final class Query implements QueryContract
{
    /**
     * The comparisons where() and having() accept. An operator becomes
     * part of the SQL, so nothing outside this list is let through.
     */
    private const OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'like', 'not like'];

    /**
     * @var list<string|Expression> Empty means every column.
     */
    public private(set) array $columns = [];
    public private(set) bool $distinct = false;
    /**
     * @var list<array{type: string, table: string, wheres: list<array<string, mixed>>}>
     */
    public private(set) array $joins = [];
    /**
     * Conditions, each with its "type", the "boolean" that joins it to the
     * one before, and what that type needs.
     *
     * @var list<array<string, mixed>>
     */
    public private(set) array $wheres = [];
    /**
     * @var list<string>
     */
    public private(set) array $groups = [];
    /**
     * @var list<array<string, mixed>> Conditions, shaped as in $wheres.
     */
    public private(set) array $havings = [];
    /**
     * @var list<array{column: string|Expression, direction: string}>
     */
    public private(set) array $orders = [];
    public private(set) ?int $limit = null;
    public private(set) ?int $offset = null;
    public private(set) bool $lock = false;

    /**
     * @param Grammar|null $grammar The SQL dialect to write; left out, it follows the connection's driver.
     */
    public function __construct(
        private readonly Connection $connection,
        public readonly string $table,
        private ?Grammar $grammar = null,
    ) {
    }

    public function select(string ...$columns): static
    {
        $this->columns = array_values($columns);
        return $this;
    }

    public function addSelect(string ...$columns): static
    {
        $this->columns = [...$this->columns, ...array_values($columns)];
        return $this;
    }

    public function selectRaw(string $sql, array $bindings = []): static
    {
        $this->columns[] = new Expression($sql, array_values($bindings));
        return $this;
    }

    public function distinct(): static
    {
        $this->distinct = true;
        return $this;
    }

    public function join(string $table, string|\Closure $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->addJoin('inner', $table, $first, $operator, $second);
    }

    public function leftJoin(string $table, string|\Closure $first, ?string $operator = null, ?string $second = null): static
    {
        return $this->addJoin('left', $table, $first, $operator, $second);
    }

    public function where(string|array|\Closure $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->addWhere('and', func_num_args(), $column, $operator, $value);
    }

    public function orWhere(string|array|\Closure $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->addWhere('or', func_num_args(), $column, $operator, $value);
    }

    public function whereIn(string $column, array|QueryContract $values): static
    {
        return $this->addIn('and', $column, $values, false);
    }

    public function orWhereIn(string $column, array|QueryContract $values): static
    {
        return $this->addIn('or', $column, $values, false);
    }

    public function whereNotIn(string $column, array|QueryContract $values): static
    {
        return $this->addIn('and', $column, $values, true);
    }

    public function orWhereNotIn(string $column, array|QueryContract $values): static
    {
        return $this->addIn('or', $column, $values, true);
    }

    public function whereNull(string $column): static
    {
        $this->wheres[] = ['type' => 'null', 'boolean' => 'and', 'column' => $column, 'not' => false];
        return $this;
    }

    public function orWhereNull(string $column): static
    {
        $this->wheres[] = ['type' => 'null', 'boolean' => 'or', 'column' => $column, 'not' => false];
        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->wheres[] = ['type' => 'null', 'boolean' => 'and', 'column' => $column, 'not' => true];
        return $this;
    }

    public function orWhereNotNull(string $column): static
    {
        $this->wheres[] = ['type' => 'null', 'boolean' => 'or', 'column' => $column, 'not' => true];
        return $this;
    }

    public function whereBetween(string $column, mixed $from, mixed $to): static
    {
        $this->wheres[] = ['type' => 'between', 'boolean' => 'and', 'column' => $column, 'from' => $from, 'to' => $to, 'not' => false];
        return $this;
    }

    public function orWhereBetween(string $column, mixed $from, mixed $to): static
    {
        $this->wheres[] = ['type' => 'between', 'boolean' => 'or', 'column' => $column, 'from' => $from, 'to' => $to, 'not' => false];
        return $this;
    }

    public function whereNotBetween(string $column, mixed $from, mixed $to): static
    {
        $this->wheres[] = ['type' => 'between', 'boolean' => 'and', 'column' => $column, 'from' => $from, 'to' => $to, 'not' => true];
        return $this;
    }

    public function orWhereNotBetween(string $column, mixed $from, mixed $to): static
    {
        $this->wheres[] = ['type' => 'between', 'boolean' => 'or', 'column' => $column, 'from' => $from, 'to' => $to, 'not' => true];
        return $this;
    }

    public function whereColumn(string $first, string $operator, ?string $second = null): static
    {
        $this->wheres[] = self::columns('and', $first, $operator, $second);
        return $this;
    }

    public function orWhereColumn(string $first, string $operator, ?string $second = null): static
    {
        $this->wheres[] = self::columns('or', $first, $operator, $second);
        return $this;
    }

    public function whereRaw(string $sql, array $bindings = []): static
    {
        $this->wheres[] = ['type' => 'raw', 'boolean' => 'and', 'expression' => new Expression($sql, array_values($bindings))];
        return $this;
    }

    public function orWhereRaw(string $sql, array $bindings = []): static
    {
        $this->wheres[] = ['type' => 'raw', 'boolean' => 'or', 'expression' => new Expression($sql, array_values($bindings))];
        return $this;
    }

    public function groupBy(string ...$columns): static
    {
        $this->groups = [...$this->groups, ...array_values($columns)];
        return $this;
    }

    public function having(string $column, mixed $operator = null, mixed $value = null): static
    {
        $this->havings[] = self::comparison('and', func_num_args(), $column, $operator, $value);
        return $this;
    }

    public function havingRaw(string $sql, array $bindings = []): static
    {
        $this->havings[] = ['type' => 'raw', 'boolean' => 'and', 'expression' => new Expression($sql, array_values($bindings))];
        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $direction = strtolower($direction);
        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new \InvalidArgumentException("An order is \"asc\" or \"desc\", not \"{$direction}\".");
        }
        $this->orders[] = ['column' => $column, 'direction' => $direction];
        return $this;
    }

    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    public function orderByRaw(string $sql, array $bindings = []): static
    {
        $this->orders[] = ['column' => new Expression($sql, array_values($bindings)), 'direction' => ''];
        return $this;
    }

    public function latest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'desc');
    }

    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'asc');
    }

    public function limit(int $limit): static
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('A limit cannot be negative.');
        }
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): static
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('An offset cannot be negative.');
        }
        $this->offset = $offset;
        return $this;
    }

    public function forPage(int $page, int $perPage = 15): static
    {
        if ($page < 1 || $perPage < 1) {
            throw new \InvalidArgumentException('Pages are numbered from 1 and hold at least one row.');
        }
        $this->offset = ($page - 1) * $perPage;
        $this->limit = $perPage;
        return $this;
    }

    public function lockForUpdate(): static
    {
        $this->lock = true;
        return $this;
    }

    public function when(mixed $condition, \Closure $then, ?\Closure $else = null): static
    {
        if ($condition) {
            $then($this);
        } elseif ($else !== null) {
            $else($this);
        }
        return $this;
    }

    public function get(): array
    {
        $statement = $this->grammar()->select($this);
        return $this->connection->select($statement->sql, $statement->bindings);
    }

    public function first(): ?array
    {
        $one = clone $this;
        $one->limit = 1;
        $statement = $this->grammar()->select($one);
        return $this->connection->first($statement->sql, $statement->bindings);
    }

    public function find(int|string $id, string $column = 'id'): ?array
    {
        $query = clone $this;
        return $query->where($column, $id)->first();
    }

    public function value(string $column): mixed
    {
        $query = clone $this;
        $row = $query->select($column)->first();
        return $row === null ? null : $row[self::name($column)];
    }

    public function pluck(string $column, ?string $key = null): array
    {
        $query = clone $this;
        if ($key === null) {
            return array_column($query->select($column)->get(), self::name($column));
        }
        return array_column($query->select($column, $key)->get(), self::name($column), self::name($key));
    }

    public function exists(): bool
    {
        $statement = $this->grammar()->exists($this);
        $row = $this->connection->first($statement->sql, $statement->bindings);
        return (bool) $row['found'];
    }

    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('count', $column);
    }

    public function sum(string $column): int|float
    {
        $total = $this->aggregate('sum', $column);
        // MySQL returns a decimal as a string.
        return is_numeric($total) ? $total + 0 : 0;
    }

    public function avg(string $column): ?float
    {
        $average = $this->aggregate('avg', $column);
        return $average === null ? null : (float) $average;
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('min', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('max', $column);
    }

    public function chunkById(int $size, \Closure $callback, string $column = 'id'): void
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('A chunk holds at least one row.');
        }
        $key = self::name($column);
        $last = null;
        do {
            $part = clone $this;
            if ($last !== null) {
                $part->where($column, '>', $last);
            }
            $rows = $part->orderBy($column)->limit($size)->get();
            if ($rows === [] || $callback($rows) === false) {
                return;
            }
            $last = $rows[count($rows) - 1][$key];
        } while (count($rows) === $size);
    }

    public function paginate(int $perPage = 15, int $page = 1): Page
    {
        $page = max(1, $page);
        // A limit already on the query would cap the count; the page sets its own.
        $all = clone $this;
        $all->limit = null;
        $all->offset = null;
        $total = $all->count();
        $items = $total === 0 ? [] : $all->forPage($page, $perPage)->get();
        return new Page($items, $perPage, $page, $page * $perPage < $total, $total);
    }

    public function simplePaginate(int $perPage = 15, int $page = 1): Page
    {
        $page = max(1, $page);
        // One row more than the page holds tells whether another page follows.
        $query = clone $this;
        $rows = $query->forPage($page, $perPage)->limit($perPage + 1)->get();
        return new Page(array_slice($rows, 0, $perPage), $perPage, $page, count($rows) > $perPage);
    }

    public function insert(array $values): int|string
    {
        if ($values === [] || array_is_list($values)) {
            throw new \InvalidArgumentException('insert() takes one row as column => value; use insertMany() for a list of rows.');
        }
        $statement = $this->grammar()->insert($this, [$values]);
        return $this->connection->insert($statement->sql, $statement->bindings);
    }

    public function insertMany(array $rows): int
    {
        $rows = self::rows($rows);
        if ($rows === []) {
            return 0;
        }
        $statement = $this->grammar()->insert($this, $rows);
        return $this->connection->execute($statement->sql, $statement->bindings);
    }

    public function insertOrIgnore(array $rows): int
    {
        $rows = self::rows($rows);
        if ($rows === []) {
            return 0;
        }
        $statement = $this->grammar()->insertOrIgnore($this, $rows);
        return $this->connection->execute($statement->sql, $statement->bindings);
    }

    public function upsert(array $rows, string|array $uniqueBy, ?array $update = null): int
    {
        $rows = self::rows($rows);
        if ($rows === []) {
            return 0;
        }
        $uniqueBy = (array) $uniqueBy;
        if ($uniqueBy === []) {
            throw new \InvalidArgumentException('upsert() needs the columns of a unique key to tell an existing row by.');
        }
        $update ??= array_values(array_diff(array_keys($rows[0]), $uniqueBy));
        // With nothing to overwrite, an existing row is simply left alone.
        $statement = $update === []
            ? $this->grammar()->insertOrIgnore($this, $rows)
            : $this->grammar()->upsert($this, $rows, array_values($uniqueBy), array_values($update));
        return $this->connection->execute($statement->sql, $statement->bindings);
    }

    public function update(array $values): int
    {
        if ($values === [] || array_is_list($values)) {
            throw new \InvalidArgumentException('update() takes the new values as column => value.');
        }
        $this->wheresOnly('update');
        $statement = $this->grammar()->update($this, $values, []);
        return $this->connection->execute($statement->sql, $statement->bindings);
    }

    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        $this->wheresOnly('increment');
        $statement = $this->grammar()->update($this, $extra, [$column => $amount]);
        return $this->connection->execute($statement->sql, $statement->bindings);
    }

    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->increment($column, -$amount, $extra);
    }

    public function delete(): int
    {
        $this->wheresOnly('delete');
        $statement = $this->grammar()->delete($this);
        return $this->connection->execute($statement->sql, $statement->bindings);
    }

    public function toSql(): string
    {
        return $this->grammar()->select($this)->sql;
    }

    public function bindings(): array
    {
        return $this->grammar()->select($this)->bindings;
    }

    private function grammar(): Grammar
    {
        return $this->grammar ??= Grammar::for($this->connection->driver());
    }

    /**
     * An empty query on the same table, for the conditions of a group or a join.
     */
    private function nested(): self
    {
        return new self($this->connection, $this->table, $this->grammar);
    }

    private function aggregate(string $function, string $column): mixed
    {
        $statement = $this->grammar()->aggregate($this, $function, $column);
        $row = $this->connection->first($statement->sql, $statement->bindings);
        return $row['aggregate'];
    }

    private function addWhere(string $boolean, int $arguments, string|array|\Closure $column, mixed $operator, mixed $value): static
    {
        if (is_string($column)) {
            $this->wheres[] = self::comparison($boolean, $arguments, $column, $operator, $value);
            return $this;
        }
        $group = $this->nested();
        if ($column instanceof \Closure) {
            $column($group);
        } else {
            foreach ($column as $name => $equals) {
                $group->where($name, $equals);
            }
        }
        // A group that was given nothing adds nothing.
        if ($group->wheres !== []) {
            $this->wheres[] = ['type' => 'nested', 'boolean' => $boolean, 'wheres' => $group->wheres];
        }
        return $this;
    }

    /**
     * @param array<mixed>|QueryContract $values
     */
    private function addIn(string $boolean, string $column, array|QueryContract $values, bool $not): static
    {
        if (is_array($values)) {
            $values = array_values($values);
        }
        $this->wheres[] = ['type' => 'in', 'boolean' => $boolean, 'column' => $column, 'values' => $values, 'not' => $not];
        return $this;
    }

    private function addJoin(string $type, string $table, string|\Closure $first, ?string $operator, ?string $second): static
    {
        $on = $this->nested();
        if ($first instanceof \Closure) {
            $first($on);
        } elseif ($operator === null) {
            throw new \InvalidArgumentException("A join needs a condition: join('{$table}', '{$table}.user_id', '=', 'users.id').");
        } else {
            $on->whereColumn($first, $operator, $second);
        }
        if ($on->wheres === []) {
            throw new \InvalidArgumentException("The closure given to join('{$table}', ...) added no condition.");
        }
        $this->joins[] = ['type' => $type, 'table' => $table, 'wheres' => $on->wheres];
        return $this;
    }

    /**
     * A column compared with a value, for where() and having().
     *
     * @param int $arguments How many arguments the caller was given: with two, the second is the value and the comparison is "=".
     * @return array<string, mixed>
     */
    private static function comparison(string $boolean, int $arguments, string $column, mixed $operator, mixed $value): array
    {
        if ($arguments < 2) {
            throw new \InvalidArgumentException("The condition on \"{$column}\" has no value to compare with.");
        }
        if ($arguments === 2) {
            $value = $operator;
            $operator = '=';
        }
        $operator = self::operator($operator);
        if ($value === null) {
            if ($operator === '=') {
                return ['type' => 'null', 'boolean' => $boolean, 'column' => $column, 'not' => false];
            }
            if ($operator === '!=' || $operator === '<>') {
                return ['type' => 'null', 'boolean' => $boolean, 'column' => $column, 'not' => true];
            }
            throw new \InvalidArgumentException("\"{$column}\" cannot be compared with null using \"{$operator}\".");
        }
        if (is_array($value) || $value instanceof QueryContract) {
            throw new \InvalidArgumentException("\"{$column}\" was compared with a list; use whereIn() for that.");
        }
        return ['type' => 'basic', 'boolean' => $boolean, 'column' => $column, 'operator' => $operator, 'value' => $value];
    }

    /**
     * One column compared with another; with no $second, $operator is the
     * second column and the comparison is "=".
     *
     * @return array<string, mixed>
     */
    private static function columns(string $boolean, string $first, string $operator, ?string $second): array
    {
        if ($second === null) {
            $second = $operator;
            $operator = '=';
        }
        return ['type' => 'column', 'boolean' => $boolean, 'first' => $first, 'operator' => self::operator($operator), 'second' => $second];
    }

    private static function operator(mixed $operator): string
    {
        $known = is_string($operator) ? strtolower($operator) : null;
        if ($known === null || !in_array($known, self::OPERATORS, true)) {
            $given = is_string($operator) ? "\"{$operator}\"" : get_debug_type($operator);
            throw new \InvalidArgumentException("{$given} is not a comparison; use one of: " . implode(' ', self::OPERATORS) . '.');
        }
        return $known;
    }

    /**
     * One row or a list of rows, as a list of rows that all have the
     * columns of the first, in its order.
     *
     * @param array<mixed> $rows
     * @return list<array<string, mixed>>
     */
    private static function rows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        if (!array_is_list($rows)) {
            $rows = [$rows];
        }
        $first = $rows[0];
        if (!is_array($first) || $first === [] || array_is_list($first)) {
            throw new \InvalidArgumentException('A row is given as column => value.');
        }
        $ordered = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== count($first) || array_diff_key($first, $row) !== []) {
                throw new \InvalidArgumentException('Every row inserted together must have the same columns.');
            }
            $ordered[] = array_replace($first, $row);
        }
        return $ordered;
    }

    /**
     * The name a column has in a result row: its alias, or the part after
     * the table.
     */
    private static function name(string $column): string
    {
        $alias = stripos($column, ' as ');
        if ($alias !== false) {
            return trim(substr($column, $alias + 4));
        }
        $dot = strrpos($column, '.');
        return $dot === false ? $column : substr($column, $dot + 1);
    }

    /**
     * An UPDATE or DELETE is written from the where conditions alone;
     * anything else on the query would be dropped without a word.
     */
    private function wheresOnly(string $method): void
    {
        $extra = $this->joins !== [] || $this->groups !== [] || $this->havings !== [] || $this->orders !== []
            || $this->limit !== null || $this->offset !== null;
        if ($extra) {
            throw new \LogicException("{$method}() uses only the where conditions, and this query also has a join, group, order or limit.");
        }
    }
}
