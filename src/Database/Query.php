<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\CursorPage;
use Crab\Contracts\Database\InvalidCursor;
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
     * The table read, "users" or "users as u"; the name of the rows of $source when there is one.
     */
    public readonly string $table;
    /**
     * The query whose rows are read as the table, when it is not one.
     */
    public readonly ?QueryContract $source;
    /**
     * @var list<string|Expression|Subquery> Empty means every column.
     */
    public private(set) array $columns = [];
    public private(set) bool $distinct = false;
    /**
     * @var list<array{type: string, table: string|Subquery, wheres: list<array<string, mixed>>}>
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
     * @var list<string|Expression>
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
     * @param string|QueryContract $table A table, or a query whose rows are read as one.
     * @param Grammar|null $grammar The SQL dialect to write; left out, it follows the connection's driver.
     * @param string|null $as The table's alias; the name of a query's rows, which they must have.
     */
    public function __construct(
        private readonly Connection $connection,
        string|QueryContract $table,
        private ?Grammar $grammar = null,
        ?string $as = null,
    ) {
        if ($table instanceof QueryContract) {
            if ($as === null || trim($as) === '') {
                throw new \InvalidArgumentException(
                    "The rows of a query are read as a table with a name: table(\$query, 'totals').",
                );
            }
            $this->table = $as;
            $this->source = $table;
            return;
        }
        $this->table = $as === null ? $table : "{$table} as {$as}";
        $this->source = null;
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

    public function selectSub(QueryContract $query, string $as): static
    {
        $this->columns[] = new Subquery($query, $as);
        return $this;
    }

    public function distinct(): static
    {
        $this->distinct = true;
        return $this;
    }

    public function join(
        string $table,
        string|\Closure $first,
        ?string $operator = null,
        ?string $second = null,
    ): static {
        return $this->addJoin('inner', $table, $first, $operator, $second);
    }

    public function leftJoin(
        string $table,
        string|\Closure $first,
        ?string $operator = null,
        ?string $second = null,
    ): static {
        return $this->addJoin('left', $table, $first, $operator, $second);
    }

    public function joinSub(
        QueryContract $query,
        string $as,
        string|\Closure $first,
        ?string $operator = null,
        ?string $second = null,
    ): static {
        return $this->addJoin('inner', new Subquery($query, $as), $first, $operator, $second);
    }

    public function leftJoinSub(
        QueryContract $query,
        string $as,
        string|\Closure $first,
        ?string $operator = null,
        ?string $second = null,
    ): static {
        return $this->addJoin('left', new Subquery($query, $as), $first, $operator, $second);
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

    public function whereExists(QueryContract $query): static
    {
        $this->wheres[] = ['type' => 'exists', 'boolean' => 'and', 'query' => $query, 'not' => false];
        return $this;
    }

    public function orWhereExists(QueryContract $query): static
    {
        $this->wheres[] = ['type' => 'exists', 'boolean' => 'or', 'query' => $query, 'not' => false];
        return $this;
    }

    public function whereNotExists(QueryContract $query): static
    {
        $this->wheres[] = ['type' => 'exists', 'boolean' => 'and', 'query' => $query, 'not' => true];
        return $this;
    }

    public function orWhereNotExists(QueryContract $query): static
    {
        $this->wheres[] = ['type' => 'exists', 'boolean' => 'or', 'query' => $query, 'not' => true];
        return $this;
    }

    public function whereBetween(string $column, mixed $from, mixed $to): static
    {
        $this->wheres[] = [
            'type' => 'between',
            'boolean' => 'and',
            'column' => $column,
            'from' => $from,
            'to' => $to,
            'not' => false,
        ];
        return $this;
    }

    public function orWhereBetween(string $column, mixed $from, mixed $to): static
    {
        $this->wheres[] = [
            'type' => 'between',
            'boolean' => 'or',
            'column' => $column,
            'from' => $from,
            'to' => $to,
            'not' => false,
        ];
        return $this;
    }

    public function whereNotBetween(string $column, mixed $from, mixed $to): static
    {
        $this->wheres[] = [
            'type' => 'between',
            'boolean' => 'and',
            'column' => $column,
            'from' => $from,
            'to' => $to,
            'not' => true,
        ];
        return $this;
    }

    public function orWhereNotBetween(string $column, mixed $from, mixed $to): static
    {
        $this->wheres[] = [
            'type' => 'between',
            'boolean' => 'or',
            'column' => $column,
            'from' => $from,
            'to' => $to,
            'not' => true,
        ];
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
        $this->wheres[] = [
            'type' => 'raw',
            'boolean' => 'and',
            'expression' => new Expression($sql, array_values($bindings)),
        ];
        return $this;
    }

    public function orWhereRaw(string $sql, array $bindings = []): static
    {
        $this->wheres[] = [
            'type' => 'raw',
            'boolean' => 'or',
            'expression' => new Expression($sql, array_values($bindings)),
        ];
        return $this;
    }

    public function groupBy(string ...$columns): static
    {
        $this->groups = [...$this->groups, ...array_values($columns)];
        return $this;
    }

    public function groupByRaw(string $sql, array $bindings = []): static
    {
        $this->groups[] = new Expression($sql, array_values($bindings));
        return $this;
    }

    public function having(string $column, mixed $operator = null, mixed $value = null): static
    {
        $this->havings[] = self::comparison('and', func_num_args(), $column, $operator, $value);
        return $this;
    }

    public function havingRaw(string $sql, array $bindings = []): static
    {
        $this->havings[] = [
            'type' => 'raw',
            'boolean' => 'and',
            'expression' => new Expression($sql, array_values($bindings)),
        ];
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
                // The query's own conditions as one, so that an "or" among them does not swallow the cursor.
                if ($part->wheres !== []) {
                    $part->wheres = [['type' => 'nested', 'boolean' => 'and', 'wheres' => $part->wheres]];
                }
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
        return new Page($items, $perPage, $page, ($page * $perPage) < $total, $total);
    }

    public function simplePaginate(int $perPage = 15, int $page = 1): Page
    {
        $page = max(1, $page);
        // One row more than the page holds tells whether another page follows.
        $query = clone $this;
        $rows = $query
            ->forPage($page, $perPage)
            ->limit($perPage + 1)
            ->get();
        return new Page(array_slice($rows, 0, $perPage), $perPage, $page, count($rows) > $perPage);
    }

    public function cursorPaginate(int $perPage = 15, ?string $cursor = null, string $unique = 'id'): CursorPage
    {
        if ($perPage < 1) {
            throw new \InvalidArgumentException('A page holds at least one row.');
        }
        $orders = $this->cursorOrder($unique);
        // Read as a table of its own, so that an order by an alias or a grouped column can be compared in a where.
        $inner = clone $this;
        $inner->orders = [];
        $inner->limit = null;
        $inner->offset = null;
        $inner->lock = false;
        $page = new self($this->connection, $inner, $this->grammar, 'crab_cursor');
        $page->lock = $this->lock;
        if ($cursor !== null) {
            $page->where(static fn(self $after) => self::after($after, $orders, self::decode($cursor, $orders)));
        }
        foreach ($orders as $name => $direction) {
            $page->orderBy($name, $direction);
        }
        // One row more than the page holds tells whether another page follows.
        $rows = $page->limit($perPage + 1)->get();
        if (count($rows) <= $perPage) {
            return new CursorPage($rows, $perPage, null);
        }
        $rows = array_slice($rows, 0, $perPage);
        return new CursorPage($rows, $perPage, self::encode($rows[$perPage - 1], $orders));
    }

    public function insert(array $values): int|string
    {
        $this->readsOnly('insert');
        if ($values === [] || array_is_list($values)) {
            throw new \InvalidArgumentException(
                'insert() takes one row as column => value; use insertMany() for a list of rows.',
            );
        }
        $statement = $this->grammar()->insert($this, [$values]);
        return $this->connection->insert($statement->sql, $statement->bindings);
    }

    public function insertMany(array $rows): int
    {
        $this->readsOnly('insertMany');
        $rows = self::rows($rows);
        if ($rows === []) {
            return 0;
        }
        $statement = $this->grammar()->insert($this, $rows);
        return $this->connection->execute($statement->sql, $statement->bindings);
    }

    public function insertOrIgnore(array $rows): int
    {
        $this->readsOnly('insertOrIgnore');
        $rows = self::rows($rows);
        if ($rows === []) {
            return 0;
        }
        $statement = $this->grammar()->insertOrIgnore($this, $rows);
        return $this->connection->execute($statement->sql, $statement->bindings);
    }

    public function upsert(array $rows, string|array $uniqueBy, ?array $update = null): int
    {
        $this->readsOnly('upsert');
        $rows = self::rows($rows);
        if ($rows === []) {
            return 0;
        }
        $uniqueBy = (array) $uniqueBy;
        if ($uniqueBy === []) {
            throw new \InvalidArgumentException(
                'upsert() needs the columns of a unique key to tell an existing row by.',
            );
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
        foreach (array_keys($values) as $column) {
            self::wholeColumn((string) $column);
        }
        $statement = $this->grammar()->update($this, $values, []);
        return $this->connection->execute($statement->sql, $statement->bindings);
    }

    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        $this->wheresOnly('increment');
        self::wholeColumn($column);
        foreach (array_keys($extra) as $other) {
            self::wholeColumn((string) $other);
        }
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

    private function addWhere(
        string $boolean,
        int $arguments,
        string|array|\Closure $column,
        mixed $operator,
        mixed $value,
    ): static {
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
        $this->wheres[] = [
            'type' => 'in',
            'boolean' => $boolean,
            'column' => $column,
            'values' => $values,
            'not' => $not,
        ];
        return $this;
    }

    private function addJoin(
        string $type,
        string|Subquery $table,
        string|\Closure $first,
        ?string $operator,
        ?string $second,
    ): static {
        $on = $this->nested();
        $name = $table instanceof Subquery ? $table->as : $table;
        if ($first instanceof \Closure) {
            $first($on);
        } elseif ($operator === null) {
            throw new \InvalidArgumentException(
                "A join needs a condition: join('{$name}', '{$name}.user_id', '=', 'users.id').",
            );
        } else {
            $on->whereColumn($first, $operator, $second);
        }
        if ($on->wheres === []) {
            throw new \InvalidArgumentException("The closure given to join('{$name}', ...) added no condition.");
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
    private static function comparison(
        string $boolean,
        int $arguments,
        string $column,
        mixed $operator,
        mixed $value,
    ): array {
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
        if ($value instanceof QueryContract) {
            return [
                'type' => 'sub',
                'boolean' => $boolean,
                'column' => $column,
                'operator' => $operator,
                'query' => $value,
            ];
        }
        if (is_array($value)) {
            throw new \InvalidArgumentException("\"{$column}\" was compared with a list; use whereIn() for that.");
        }
        return [
            'type' => 'basic',
            'boolean' => $boolean,
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];
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
        return [
            'type' => 'column',
            'boolean' => $boolean,
            'first' => $first,
            'operator' => self::operator($operator),
            'second' => $second,
        ];
    }

    private static function operator(mixed $operator): string
    {
        $known = is_string($operator) ? strtolower($operator) : null;
        if ($known === null || !in_array($known, self::OPERATORS, true)) {
            $given = is_string($operator) ? "\"{$operator}\"" : get_debug_type($operator);
            throw new \InvalidArgumentException(
                "{$given} is not a comparison; use one of: " . implode(' ', self::OPERATORS) . '.',
            );
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
     * The order a cursor follows, as column name to direction, ending with
     * the unique column, which it is given when the query has no order.
     *
     * @return non-empty-array<string, string>
     */
    private function cursorOrder(string $unique): array
    {
        $key = self::name($unique);
        $orders = [];
        foreach ($this->orders as $order) {
            $column = $order['column'];
            if (!is_string($column) || str_contains($column, '->')) {
                throw new \LogicException(
                    'A cursor follows an order by columns; select the expression under an alias, '
                    . "selectRaw('lower(title) as sort_title'), and order by the alias.",
                );
            }
            $orders[self::name($column)] = $order['direction'];
        }
        $orders = $orders === [] ? [$key => 'asc'] : $orders;
        $named = $this->columns === [] ? null : [];
        foreach ($this->columns as $column) {
            if (!is_string($column) || str_ends_with($column, '*')) {
                // What an expression or every column holds is the database's to say.
                $named = null;
                break;
            }
            $named[] = self::name($column);
        }
        foreach ($named === null ? [] : array_diff(array_keys($orders), $named) as $missing) {
            throw new \LogicException(
                "The query is ordered by \"{$missing}\" but does not select it, and a cursor is made of what is selected.",
            );
        }
        if (array_key_last($orders) !== $key) {
            throw new \LogicException(
                "A cursor's order must end with a column no two rows share, or rows that tie are skipped or read twice; "
                . "end it with orderBy('{$unique}'), or name the unique column cursorPaginate() should expect.",
            );
        }
        return $orders;
    }

    /**
     * Keeps the rows after the one the cursor's values are from: those
     * equal to it in the first columns of the order and past it in the
     * next. Null is before every value ascending and after it descending.
     *
     * @param array<string, string> $orders
     * @param list<scalar|null> $values
     */
    private static function after(self $query, array $orders, array $values): void
    {
        $names = array_keys($orders);
        foreach ($names as $index => $name) {
            $value = $values[$index];
            $ascending = $orders[$name] === 'asc';
            if ($value === null && !$ascending) {
                // Descending, null is last: no value of this column is past it.
                continue;
            }
            $query->orWhere(static function (self $term) use (
                $names,
                $values,
                $index,
                $name,
                $value,
                $ascending,
            ): void {
                for ($before = 0; $before < $index; $before++) {
                    $term->where($names[$before], $values[$before]);
                }
                match (true) {
                    $value === null => $term->whereNotNull($name),
                    $ascending => $term->where($name, '>', $value),
                    default => $term->where(
                        static fn(self $past) => $past->where($name, '<', $value)->orWhereNull($name),
                    ),
                };
            });
        }
    }

    /**
     * The cursor of the row a page ends with: its values in the order, and
     * which order they are for.
     *
     * @param array<string, mixed> $row
     * @param array<string, string> $orders
     */
    private static function encode(array $row, array $orders): string
    {
        $values = [self::fingerprint($orders)];
        foreach (array_keys($orders) as $name) {
            if (!array_key_exists($name, $row)) {
                throw new \LogicException(
                    "The query is ordered by \"{$name}\" but does not select it, and a cursor is made of what is selected.",
                );
            }
            $values[] = $row[$name];
        }
        $json = json_encode($values, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @param array<string, string> $orders
     * @return list<scalar|null>
     */
    private static function decode(string $cursor, array $orders): array
    {
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $values = $json === false ? null : json_decode($json, true);
        if (
            !is_array($values)
            || !array_is_list($values)
            || count($values) !== (count($orders) + 1)
            || array_shift($values) !== self::fingerprint($orders)
        ) {
            throw new InvalidCursor();
        }
        foreach ($values as $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new InvalidCursor();
            }
        }
        if (end($values) === null) {
            // The unique column is never null in a row a cursor was made from.
            throw new InvalidCursor();
        }
        return $values;
    }

    /**
     * @param array<string, string> $orders
     */
    private static function fingerprint(array $orders): string
    {
        $order = '';
        foreach ($orders as $name => $direction) {
            $order .= "{$name} {$direction},";
        }
        return hash('crc32b', $order);
    }

    /**
     * A column set by update() or increment(), which is written whole.
     */
    private static function wholeColumn(string $column): void
    {
        if (str_contains($column, '->')) {
            throw new \InvalidArgumentException(
                "\"{$column}\" is a path inside a JSON column, which a query reads but does not write; set the whole column.",
            );
        }
    }

    /**
     * Rows read from another query are not a table that can be written to.
     */
    private function readsOnly(string $method): void
    {
        if ($this->source !== null) {
            throw new \LogicException("{$method}() writes to a table, and this query reads the rows of another query.");
        }
    }

    /**
     * An UPDATE or DELETE is written from the where conditions alone;
     * anything else on the query would be dropped without a word.
     */
    private function wheresOnly(string $method): void
    {
        $this->readsOnly($method);
        $extra =
            $this->joins !== []
            || $this->groups !== []
            || $this->havings !== []
            || $this->orders !== []
            || $this->limit !== null
            || $this->offset !== null;
        if ($extra) {
            throw new \LogicException(
                "{$method}() uses only the where conditions, and this query also has a join, group, order or limit.",
            );
        }
    }
}
