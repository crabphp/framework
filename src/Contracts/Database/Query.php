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
 * A column of JSON is read at a path with ->, wherever a column is named:
 * where('data->actor->id', $id), whereNull('data->campaign'),
 * select('data->tags[0] as tag'). MySQL reads the value as text and SQLite
 * as what it is, so order by a number there with a cast in selectRaw().
 * A path is only read: update() takes whole columns.
 *
 * Another query can stand where a value, a column or a table would: in
 * selectSub(), whereExists(), whereIn(), a where() comparison, joinSub()
 * and Connection::table(). It may name the outer query's columns, which
 * makes it correlated: whereColumn('h.game_id', 'games.id'). Its values
 * are bound in the order its SQL is written.
 *
 * The methods that build return the query itself, changed. The methods
 * that run (get(), first(), count(), insert(), update(), ...) leave it as
 * it was, so one query can be run several ways; `clone` it to branch.
 *
 * Rows are arrays of column to value, or records after into(): objects
 * of a class whose constructor declares the columns with their types.
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
     * Adds a column worked out by another query, which must read one value:
     * selectSub($db->table('heartbeats')->selectRaw('max(occurred_at)')->whereColumn('heartbeats.game_id', 'games.id'), 'last_played_at').
     */
    public function selectSub(Query $query, string $as): static;

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
     * Joins the rows of another query, named $as, as join() joins a table:
     * joinSub($totals, 'totals', 'totals.user_id', '=', 'users.id').
     *
     * @param string|\Closure(Query): mixed $first
     */
    public function joinSub(
        Query $query,
        string $as,
        string|\Closure $first,
        ?string $operator = null,
        ?string $second = null,
    ): static;

    /**
     * @param string|\Closure(Query): mixed $first
     */
    public function leftJoinSub(
        Query $query,
        string $as,
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
     * The value may be another query that reads one value:
     * where('price', '>', $db->table('products')->selectRaw('avg(price)')).
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
     * Keeps the rows for which another query finds a row. It is meant to
     * name this query's columns: whereExists($db->table('heartbeats')->whereColumn('heartbeats.game_id', 'games.id')).
     * What it selects does not matter.
     */
    public function whereExists(Query $query): static;

    public function orWhereExists(Query $query): static;

    public function whereNotExists(Query $query): static;

    public function orWhereNotExists(Query $query): static;

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
     * Groups by an expression, as written: groupByRaw('date(created_at)').
     *
     * @param list<mixed> $bindings
     */
    public function groupByRaw(string $sql, array $bindings = []): static;

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
     * Reads rows into records of a class: get(), first(), find(), the
     * pages and chunkById() give objects of it. Its constructor's promoted
     * public properties are the columns, by name or by #[ColumnName], and
     * their types say how each value is read: int, float, string, bool,
     * a backed enum, DateTimeImmutable, #[Json] for an array, or a Cast
     * attribute. Unless select() chose the columns, the query reads the
     * record's. The writing methods then take records as well as values,
     * and write an enum, a date or a cast field's value as the record would.
     *
     *     $orders = $db->table('orders')->where('status', 'paid')->into(Order::class)->get();
     *
     * value(), pluck() and the aggregates read plain values, as before.
     *
     * @param class-string $class
     * @throws \LogicException When the class cannot be a record, saying what to change.
     */
    public function into(string $class): static;

    /**
     * @return list<array<string, mixed>|object> Every matching row, as column name to value, or as a record after into().
     */
    public function get(): array;

    /**
     * @return array<string, mixed>|object|null The first matching row, or null when there is none.
     */
    public function first(): array|object|null;

    /**
     * @return array<string, mixed>|object|null The row with this id, or null when there is none.
     */
    public function find(int|string $id, string $column = 'id'): array|object|null;

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
     * @param \Closure(list<array<string, mixed>|object>): mixed $callback
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
     * A page of the rows after the place a cursor marks, for a list a
     * client scrolls through: rows added or removed while it does so do
     * not shift the pages, and no row is counted. Pass the page's
     * nextCursor back to read the one after it; with no cursor it is the
     * first page.
     *
     * The order is what the cursor follows, so it must be by columns the
     * query selects, and end with $unique, a column no two rows share;
     * left unordered, it is ordered by $unique. To order by an expression,
     * select it under an alias and order by the alias:
     *
     *     selectRaw("lower(title) as sort_title")->orderBy('sort_title')->orderBy('id')
     *
     * Null comes first in an ascending order and last in a descending one,
     * as both databases sort it, and the pages follow that.
     *
     * @throws InvalidCursor When the cursor is not one this order gave: answered with a 422.
     */
    public function cursorPaginate(int $perPage = 15, ?string $cursor = null, string $unique = 'id'): CursorPage;

    /**
     * Inserts one row, given as column to value or as a record, whose
     * properties are written as its class maps them. A record need not be
     * the class into() named: one without the generated id inserts a new row.
     *
     * @param array<string, mixed>|object $values
     * @return int|string The id the database generated for it.
     */
    public function insert(array|object $values): int|string;

    /**
     * Inserts several rows in one statement; each must have the same columns.
     *
     * @param list<array<string, mixed>|object> $rows
     * @return int The number of rows inserted.
     */
    public function insertMany(array $rows): int;

    /**
     * Inserts one row or a list of rows, skipping any that would break a
     * unique key.
     *
     * @param array<string, mixed>|object|list<array<string, mixed>|object> $rows
     * @return int The number of rows inserted.
     */
    public function insertOrIgnore(array|object $rows): int;

    /**
     * Inserts one row or a list of rows; a row whose $uniqueBy columns
     * already exist is updated instead. $uniqueBy must name the columns of
     * a unique key. $update names the columns to overwrite then, every
     * other column when left out.
     *
     * @param array<string, mixed>|object|list<array<string, mixed>|object> $rows
     * @param string|list<string> $uniqueBy
     * @param list<string>|null $update
     * @return int The affected-row count as the database reports it.
     */
    public function upsert(array|object $rows, string|array $uniqueBy, ?array $update = null): int;

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
