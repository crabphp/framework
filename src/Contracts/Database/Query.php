<?php

declare(strict_types=1);

namespace Crab\Contracts\Database;

/**
 * Builds and runs one statement on one table, made by Connection::table().
 *
 * Values are always bound and names are always quoted, so both may come
 * from a request. Only the methods ending in Raw put SQL in as written;
 * never pass them anything a user typed.
 *
 * The methods that build return the query itself, changed. The methods
 * that run (get(), first(), count(), insert(), update(), ...) leave it as
 * it was, so one query can be run several ways; `clone` it to branch.
 */
interface Query
{
    /**
     * The columns to read, replacing any chosen before; every column when
     * never called. A column may be "name", "users.name" or "name as alias".
     */
    public function select(string ...$columns): static;

    /**
     * Adds columns to the ones already chosen.
     */
    public function addSelect(string ...$columns): static;

    /**
     * Adds an expression to the columns, as written: selectRaw('price * ? as total', [1.2]).
     *
     * @param list<mixed> $bindings
     */
    public function selectRaw(string $sql, array $bindings = []): static;

    /**
     * Leaves out rows that repeat.
     */
    public function distinct(): static;

    /**
     * Joins a table: join('posts', 'posts.user_id', '=', 'users.id'), or
     * without the operator when it is "=". For more than one condition
     * pass a closure, which receives a query to put where conditions on:
     * join('posts', fn (Query $on) => $on->whereColumn('posts.user_id', 'users.id')->where('posts.draft', false)).
     *
     * @param string|\Closure(Query): mixed $first
     */
    public function join(
        string $table,
        string|\Closure $first,
        ?string $operator = null,
        ?string $second = null,
    ): static;

    /**
     * The same, keeping the rows that have no match in the joined table.
     *
     * @param string|\Closure(Query): mixed $first
     */
    public function leftJoin(
        string $table,
        string|\Closure $first,
        ?string $operator = null,
        ?string $second = null,
    ): static;

    /**
     * Keeps the rows that match, in one of four forms:
     *
     *     where('age', '>', 18)                 a comparison: = != <> < > <= >= like, not like
     *     where('name', 'Ada')                  equals; a null value means "is null"
     *     where(['name' => 'Ada', 'age' => 36]) every pair equals
     *     where(fn (Query $q) => $q->...)       a group in parentheses
     *
     * @param string|array<string, mixed>|\Closure(Query): mixed $column
     */
    public function where(string|array|\Closure $column, mixed $operator = null, mixed $value = null): static;

    /**
     * The same, joined to the conditions before it with "or".
     *
     * @param string|array<string, mixed>|\Closure(Query): mixed $column
     */
    public function orWhere(string|array|\Closure $column, mixed $operator = null, mixed $value = null): static;

    /**
     * Keeps the rows whose column is one of the values, or one of the
     * rows of another query. An empty list matches nothing.
     *
     * @param list<mixed>|Query $values
     */
    public function whereIn(string $column, array|Query $values): static;

    /**
     * @param list<mixed>|Query $values
     */
    public function orWhereIn(string $column, array|Query $values): static;

    /**
     * @param list<mixed>|Query $values
     */
    public function whereNotIn(string $column, array|Query $values): static;

    /**
     * @param list<mixed>|Query $values
     */
    public function orWhereNotIn(string $column, array|Query $values): static;

    public function whereNull(string $column): static;

    public function orWhereNull(string $column): static;

    public function whereNotNull(string $column): static;

    public function orWhereNotNull(string $column): static;

    /**
     * Keeps the rows whose column is from $from to $to, both included.
     */
    public function whereBetween(string $column, mixed $from, mixed $to): static;

    public function orWhereBetween(string $column, mixed $from, mixed $to): static;

    public function whereNotBetween(string $column, mixed $from, mixed $to): static;

    public function orWhereNotBetween(string $column, mixed $from, mixed $to): static;

    /**
     * Compares two columns: whereColumn('updated_at', '>', 'created_at'),
     * or without the operator when it is "=".
     */
    public function whereColumn(string $first, string $operator, ?string $second = null): static;

    public function orWhereColumn(string $first, string $operator, ?string $second = null): static;

    /**
     * A condition as written, in parentheses: whereRaw('length(name) > ?', [3]).
     *
     * @param list<mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = []): static;

    /**
     * @param list<mixed> $bindings
     */
    public function orWhereRaw(string $sql, array $bindings = []): static;

    public function groupBy(string ...$columns): static;

    /**
     * Keeps the groups that match: having('total', '>', 100), or without
     * the operator when it is "=".
     */
    public function having(string $column, mixed $operator = null, mixed $value = null): static;

    /**
     * @param list<mixed> $bindings
     */
    public function havingRaw(string $sql, array $bindings = []): static;

    /**
     * @param string $direction "asc" or "desc".
     */
    public function orderBy(string $column, string $direction = 'asc'): static;

    public function orderByDesc(string $column): static;

    /**
     * @param list<mixed> $bindings
     */
    public function orderByRaw(string $sql, array $bindings = []): static;

    /**
     * Newest first by a date column.
     */
    public function latest(string $column = 'created_at'): static;

    /**
     * Oldest first by a date column.
     */
    public function oldest(string $column = 'created_at'): static;

    public function limit(int $limit): static;

    public function offset(int $offset): static;

    /**
     * Limits to one page of results; pages are numbered from 1.
     */
    public function forPage(int $page, int $perPage = 15): static;

    /**
     * Locks the rows read until the transaction ends, where the database
     * has row locks. Use it inside transaction().
     */
    public function lockForUpdate(): static;

    /**
     * Applies $then to the query when the condition holds, otherwise
     * $else when it is given: when($search !== '', fn (Query $q) => $q->where('name', 'like', "%{$search}%")).
     *
     * @param \Closure(static): mixed $then
     * @param (\Closure(static): mixed)|null $else
     */
    public function when(mixed $condition, \Closure $then, ?\Closure $else = null): static;

    /**
     * @return list<array<string, mixed>> Every matching row, as column name to value.
     */
    public function get(): array;

    /**
     * @return array<string, mixed>|null The first matching row, or null when there is none.
     */
    public function first(): ?array;

    /**
     * @return array<string, mixed>|null The row with this id, or null when there is none.
     */
    public function find(int|string $id, string $column = 'id'): ?array;

    /**
     * @return mixed One column of the first matching row, or null when there is none.
     */
    public function value(string $column): mixed;

    /**
     * @return array<int|string, mixed> One column of every matching row, keyed by the $key column when given.
     */
    public function pluck(string $column, ?string $key = null): array;

    public function exists(): bool;

    public function count(string $column = '*'): int;

    /**
     * @return int|float The total, 0 when no row matches.
     */
    public function sum(string $column): int|float;

    /**
     * @return float|null The average, null when no row matches.
     */
    public function avg(string $column): ?float;

    /**
     * @return mixed The smallest value, null when no row matches.
     */
    public function min(string $column): mixed;

    /**
     * @return mixed The largest value, null when no row matches.
     */
    public function max(string $column): mixed;

    /**
     * Reads a large result a part at a time, in order of a unique column,
     * and passes each part to the callback. Rows added or removed while it
     * runs do not shift the parts. Return false from the callback to stop.
     *
     * @param \Closure(list<array<string, mixed>>): mixed $callback
     */
    public function chunkById(int $size, \Closure $callback, string $column = 'id'): void;

    /**
     * One page of the matching rows and the count of them all, which is a
     * second statement. Pages are numbered from 1, and a page below 1 is
     * read as 1, so the number can come straight from a request:
     * paginate(20, (int) $request->query('page', 1)). Order the query, or
     * the pages are not stable.
     */
    public function paginate(int $perPage = 15, int $page = 1): Page;

    /**
     * The same without counting: the page knows whether another follows
     * but not the total, which is what a "next" link needs and is cheaper
     * on a large table.
     */
    public function simplePaginate(int $perPage = 15, int $page = 1): Page;

    /**
     * Inserts one row, given as column to value.
     *
     * @param array<string, mixed> $values
     * @return int|string The id the database generated for it.
     */
    public function insert(array $values): int|string;

    /**
     * Inserts several rows in one statement; each must have the same columns.
     *
     * @param list<array<string, mixed>> $rows
     * @return int The number of rows inserted.
     */
    public function insertMany(array $rows): int;

    /**
     * Inserts one row or a list of rows, skipping any that would break a
     * unique key.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $rows
     * @return int The number of rows inserted.
     */
    public function insertOrIgnore(array $rows): int;

    /**
     * Inserts one row or a list of rows; a row whose $uniqueBy columns
     * already exist is updated instead. $uniqueBy must name the columns of
     * a unique key. $update names the columns to overwrite then, every
     * other column when left out.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $rows
     * @param string|list<string> $uniqueBy
     * @param list<string>|null $update
     * @return int The affected-row count as the database reports it.
     */
    public function upsert(array $rows, string|array $uniqueBy, ?array $update = null): int;

    /**
     * Updates the matching rows; every row when the query has no where.
     *
     * @param array<string, mixed> $values
     * @return int The number of rows it affected.
     */
    public function update(array $values): int;

    /**
     * Adds to a number column in the database itself, so two requests
     * doing it at once both count. $extra sets other columns alongside.
     *
     * @param array<string, mixed> $extra
     * @return int The number of rows it affected.
     */
    public function increment(string $column, int|float $amount = 1, array $extra = []): int;

    /**
     * @param array<string, mixed> $extra
     * @return int The number of rows it affected.
     */
    public function decrement(string $column, int|float $amount = 1, array $extra = []): int;

    /**
     * Deletes the matching rows; every row when the query has no where.
     *
     * @return int The number of rows it deleted.
     */
    public function delete(): int;

    /**
     * The SELECT this query runs, with ? where its values go. For
     * debugging and for use as a subquery.
     */
    public function toSql(): string;

    /**
     * @return list<mixed> The values for the placeholders of toSql(), in order.
     */
    public function bindings(): array;
}
