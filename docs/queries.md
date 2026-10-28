# Query builder

- [Introduction](#introduction)
- [Reading rows](#reading-rows)
    - [Aggregates](#aggregates)
    - [Rows as records](#rows-as-records)
- [Conditions](#conditions)
    - [Groups of conditions](#groups-of-conditions)
    - [Conditional clauses](#conditional-clauses)
- [Selecting, joining and ordering](#selecting-joining-and-ordering)
- [Subqueries](#subqueries)
- [JSON columns](#json-columns)
- [Inserting](#inserting)
- [Updating](#updating)
- [Deleting](#deleting)
- [Pagination](#pagination)
    - [Cursor pagination](#cursor-pagination)
- [Large tables](#large-tables)
- [In a transaction](#in-a-transaction)
- [Raw SQL](#raw-sql)
- [Good to know](#good-to-know)

## Introduction

The query builder writes SQL for you from method calls. Use it for the statements you write every day, selects with conditions, inserts, updates, deletes. It binds every value and quotes every name, and it writes the SQL for the database in use, so the same code runs on MySQL and SQLite.

Start a query from a table:

```php
$query = $db->table('users');
```

Every method is listed, with what it does, in the contract `Crab\Contracts\Database\Query`.

## Reading rows

```php
$all    = $db->table('users')->get();                                         // every row
$adults = $db->table('users')->where('age', '>=', 18)->orderBy('name')->get();
$ada    = $db->table('users')->where('email', 'ada@example.com')->first();    // one row, or null
$user   = $db->table('users')->find(7);                                       // by id, or null
$email  = $db->table('users')->where('id', 7)->value('email');                // one value
$names  = $db->table('users')->pluck('name');                                 // a list of one column
$byId   = $db->table('users')->pluck('name', 'id');                           // [id => name]
$any    = $db->table('users')->where('role', 'admin')->exists();
```

A row is an array of column name to value. `get()` returns a list of rows, empty when none match.

### Aggregates

```php
$db->table('orders')->where('paid', true)->count();
$db->table('orders')->sum('total');
$db->table('orders')->avg('total');             // null when there are no rows
$db->table('orders')->min('created_at');
$db->table('orders')->max('total');
```

### Rows as records

A row can be read as an object of a class of yours instead of an array. Declare the columns in its constructor, with their types, and name the class with `into()`:

```php
use Crab\Database\Json;

final readonly class Order
{
    public function __construct(
        public int $id,
        public OrderStatus $status,           // a backed enum, stored as its value
        public int $total,
        public bool $paid,                    // 0 and 1 in SQLite, a bool here
        #[Json] public array $meta,           // a JSON column, decoded
        public \DateTimeImmutable $placed_at,
        public ?string $note,                 // nullable: the column may be null
    ) {}
}

$orders = $db->table('orders')->where('paid', true)->into(Order::class)->get();   // list<Order>
$order  = $db->table('orders')->into(Order::class)->find($id);                     // ?Order
```

`get()`, `first()`, `find()`, the pages of `paginate()`, `simplePaginate()` and `cursorPaginate()`, and the parts `chunkById()` passes, all hold records then. `value()`, `pluck()` and the aggregates still give plain values. Without `into()` nothing changes: rows are arrays.

- **Each promoted public property is a column of the same name.** Give one another name with `#[ColumnName('created_at')]`.
- **The types say how a value is read:** `int`, `float`, `string`, `bool`, a backed enum, `DateTimeImmutable`, `mixed` as it comes, and an array only with `#[Json]`. A value is never quietly made another: text that is not a number, a value the enum does not have and null in a property that is not nullable are each refused with the property and the column named, never the value.
- **The query reads the record's columns,** of the table or its alias, unless `select()` chose them. A column the class does not declare, a password hash for one, is not read at all. A class for each shape you read, `OrderSummary` beside `Order`, keeps every query to what it needs.
- **A column the query does not select** is refused, unless its parameter has a default.
- **A class that cannot be a record is refused when `into()` names it,** saying what to change: an untyped or union-typed parameter, an array without `#[Json]`, a `DateTime` (declare `DateTimeImmutable`), a parameter that is not a promoted public property, or two properties on one column.

Writing takes records too. `insert()`, `insertMany()`, `insertOrIgnore()` and `upsert()` accept objects, written as their class maps them, so a class without the id inserts a new row. After `into()`, the arrays given to them, to `update()` and to `increment()` are written as the record's fields would be:

```php
final readonly class NewOrder
{
    public function __construct(
        public OrderStatus $status,
        public int $total,
        #[Json] public array $meta,
        public \DateTimeImmutable $placed_at,
    ) {}
}

$id = $db->table('orders')->insert(new NewOrder(OrderStatus::Pending, 1250, [], $clock->now()));

$db->table('orders')->into(Order::class)->where('id', $id)->update(['status' => OrderStatus::Paid]);
```

An enum is written as its value, a date as `Y-m-d H:i:s` in PHP's default time zone, which is the zone a date is read in, and a `#[Json]` field as JSON.

For a type of your own, write a cast: an attribute that implements `Crab\Contracts\Database\Cast`, with `read()` from the column and `write()` back. Null never reaches it.

```php
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class AsMoney implements Cast
{
    public function read(mixed $value): mixed { return Money::cents((int) $value); }
    public function write(mixed $value): mixed { return $value->cents; }
}

public function __construct(#[AsMoney] public Money $price) {}
```

There are no models: a record does not load or save itself, has no relationships and changes nothing behind your back. A related row is read with a query of its own, a `whereIn()` on the ids you hold.

## Conditions

```php
$db->table('users')
    ->where('active', true)                         // equals
    ->where('age', '>', 18)                         // = != < > <= >= like
    ->where('deleted_at', null)                     // is null
    ->whereIn('role', ['admin', 'editor'])
    ->whereNotIn('id', $blocked)
    ->whereBetween('age', 18, 65)
    ->whereNull('banned_at')
    ->whereNotNull('email_verified_at')
    ->whereColumn('updated_at', '>', 'created_at')  // one column against another
    ->get();
```

Conditions are joined with `and`. Each has an `or` form, `orWhere()`, `orWhereIn()`, `orWhereNull()` and so on. An array gives several equalities at once: `where(['role' => 'admin', 'active' => true])`.

An unknown comparison is refused when the query is built, not by the database later.

### Groups of conditions

A closure puts conditions in brackets:

```php
use Crab\Contracts\Database\Query;

$db->table('users')
    ->where('active', true)
    ->where(function (Query $group): void {
        $group->where('name', 'like', 'A%')->orWhere('email', 'like', 'a%');
    })
    ->get();
// where active = ? and (name like ? or email like ?)
```

Use a group whenever you mix `and` with `or`. Without one, `or` applies to everything before it.

### Conditional clauses

`when()` adds to the query only when a condition holds, which keeps a search with optional filters in one chain:

```php
$db->table('users')
    ->when($search !== null, fn (Query $query) => $query->where('name', 'like', "%{$search}%"))
    ->when($role !== null, fn (Query $query) => $query->where('role', $role))
    ->get();
```

## Selecting, joining and ordering

```php
$db->table('posts')
    ->join('users', 'users.id', 'posts.user_id')
    ->leftJoin('images', 'images.post_id', 'posts.id')
    ->select('posts.id', 'posts.title', 'users.name as author')
    ->where('posts.published', true)
    ->orderBy('posts.created_at', 'desc')
    ->limit(20)
    ->offset(40)
    ->get();
```

```php
->distinct()
->groupBy('role')->having('total', '>', 10)
->latest()                      // order by created_at, newest first
->oldest('published_at')
->forPage(3, 20)                // the third page of 20
```

A table may have an alias, `table('users as u')` or `table('users', 'u')`. `groupByRaw('date(created_at)')` groups by an expression.

## Subqueries

Another query can stand where a value, a column, a condition or a table would. Build it from `$db` as usual and pass it in; it is written inside the outer statement, and its values are bound in the order its SQL appears. A subquery may name the outer query's columns, which ties each of its runs to one outer row:

```php
$heartbeats = static fn () => $db->table('heartbeats as h')->whereColumn('h.game_id', 'games.id');

$db->table('games')
    ->select('games.id', 'games.title')
    ->selectSub($heartbeats()->selectRaw('max(h.occurred_at)'), 'last_played_at')   // a column
    ->whereExists($heartbeats())                                                     // a condition
    ->where('user_id', $userId)
    ->orderByDesc('last_played_at')
    ->orderByDesc('games.id')
    ->limit(12)
    ->get();
```

That is this SQL, with `?` for `$userId`:

```sql
select `games`.`id`, `games`.`title`,
    (select max(h.occurred_at) from `heartbeats` as `h` where `h`.`game_id` = `games`.`id`) as `last_played_at`
from `games`
where exists (select * from `heartbeats` as `h` where `h`.`game_id` = `games`.`id`) and `user_id` = ?
order by `last_played_at` desc, `games`.`id` desc limit ?
```

| Method | Stands for |
| --- | --- |
| `selectSub($query, 'name')` | a column; the query reads one value |
| `whereExists($query)`, `whereNotExists()`, `orWhereExists()`, `orWhereNotExists()` | whether it finds a row; what it selects does not matter |
| `where('price', '>', $query)` | a value to compare with; the query reads one value |
| `whereIn('id', $query)`, `whereNotIn()` | a list; the query reads one column |
| `joinSub($query, 'name', 'name.user_id', '=', 'users.id')`, `leftJoinSub()` | a table to join |
| `$db->table($query, 'name')` | the table read from |

A query's rows read as a table answer the questions a grouped query cannot answer itself:

```php
$totals = $db->table('orders')->select('user_id')->selectRaw('sum(total) as spent')->groupBy('user_id');

$db->table($totals, 'totals')->where('spent', '>', 1000)->count();      // customers who spent over 1000
```

Rows read from a query are not a table that can be written to: `insert()`, `update()` and `delete()` on one are refused. `update()` and `delete()` on a table may still have subqueries among their conditions.

## JSON columns

A column of JSON is read at a path with `->`, wherever a column is named:

```php
$db->table('notifications')
    ->where('data->campaign', 'pro')
    ->where(fn (Query $q) => $q->whereNull('data->actor->id')->orWhereNotIn('data->actor->id', $blocked))
    ->where('data->seen', false)
    ->select('id', 'data->tags[0] as first_tag')
    ->get();
```

A key is letters, digits, `_` and `-`, and `[0]` reads an element of a list; anything else is refused, because the path is written into the SQL. `whereNull()` matches a key that is missing and one that holds JSON's `null` alike. `true` and `false` compare with JSON's own booleans.

MySQL reads the value as text, which compares with numbers as you expect but sorts `10` before `9`; to order by a number there, select it with a cast, `selectRaw("cast(json_extract(data, '$.score') as signed) as score")`, and order by that. SQLite reads each value as what it is.

A path is only read: `update(['data->seen' => true])` is refused. Set the whole column.

## Inserting

```php
$id = $db->table('users')->insert(['name' => 'Ada', 'email' => 'ada@example.com']);     // the new id

$db->table('users')->insertMany([
    ['name' => 'Grace', 'email' => 'grace@example.com'],
    ['name' => 'Linus', 'email' => 'linus@example.com'],
]);                                                                                     // rows inserted

$db->table('tags')->insertOrIgnore(['name' => 'php']);                                  // skips one that breaks a unique key
```

`upsert()` inserts a row, or updates it when its unique key already exists. It is the statement for "set this, whether or not it was there":

```php
$db->table('settings')->upsert(['user_id' => 7, 'theme' => 'dark'], 'user_id');
```

The second argument names the columns of a unique key. A third lists the columns to overwrite, every other column when left out. SQLite uses the named key as the conflict target; MySQL updates on any conflicting unique index and uses that argument only to choose default update columns.

## Updating

```php
$db->table('users')->where('id', 7)->update(['name' => 'Ada L.']);          // rows affected
$db->table('posts')->where('id', 3)->increment('views');
$db->table('accounts')->where('id', 9)->decrement('balance', 50, ['updated_at' => $now]);
```

> [!WARNING]
> `update()` without a `where()` updates every row. That is sometimes what you mean, so it is not refused.

## Deleting

```php
$db->table('users')->where('active', false)->delete();          // rows affected
```

`delete()` without a `where()` deletes every row.

## Pagination

`paginate()` runs the query for one page and counts the rest:

```php
use Crab\Contracts\Database\Page;
use Crab\Routing\FromQuery;

Route::get('/users', static function (#[FromQuery] int $page = 1) use ($db): Page {
    return $db->table('users')->orderBy('id')->paginate(20, $page);
});
```

It returns a `Crab\Contracts\Database\Page` with `items`, `total`, `perPage`, `currentPage`, `lastPage`, `hasMore`, `from` and `to`. Returned from an action it is written as JSON with the keys Laravel's paginator uses, and it can be looped over.

`simplePaginate()` skips the count and knows only `hasMore`. Use it on a large table, where counting every row costs more than the page.

The page number is an argument, a [query field](actions.md#query-fields) of the action's signature. Nothing reads the request behind your back.

### Cursor pagination

A list a client scrolls through, a feed or a library, is better paged by a cursor: each page carries the place it ends at, and the next starts after it. A row added or removed meanwhile does not shift what follows, nothing is counted, and a deep page costs what the first did.

```php
use Crab\Contracts\Database\CursorPage;

Route::get('/games', static function (#[FromQuery] ?string $cursor = null) use ($db): CursorPage {
    return $db->table('games')
        ->select('id', 'title')
        ->orderByDesc('id')
        ->cursorPaginate(24, $cursor);
});
```

It returns a `Crab\Contracts\Database\CursorPage` with `items`, `perPage`, `nextCursor` and `hasMore`, written as JSON with `data`, `per_page`, `next_cursor` and `has_more`. The client sends `next_cursor` back as `?cursor=` for the next page; on the last page it is `null`.

The order is what the cursor follows, so it has rules, each refused with the fix when broken:

- **It ends with a column no two rows share**, `id` unless you name another as the third argument, `cursorPaginate(24, $cursor, 'email')`. Without it, rows that tie on the other columns would be skipped or read twice. An unordered query is ordered by it.
- **It is by columns the query selects.** To order by an expression, select it under an alias and order by the alias:

```php
$db->table('games')
    ->select('id', 'title')
    ->selectRaw("lower(coalesce(nullif(trim(title), ''), '')) as sort_title")
    ->orderBy('sort_title')
    ->orderBy('id')
    ->cursorPaginate(24, $cursor);
```

- **A null sorts first ascending and last descending**, as MySQL and SQLite both sort it, and the pages follow that. Each column may go its own direction.

A grouped query can be paged too: end its order with the grouped column, which is unique among its rows.

A cursor is the order's values from the page's last row, encoded; it is not secret, and changing it only moves where the next page starts. One that is not from this order, cut short, made up, or from the list sorted another way, throws `Crab\Contracts\Database\InvalidCursor`, which is answered with a `422`. Clients should treat a cursor as opaque.

## Large tables

`chunkById()` reads a table in pieces without loading it whole, and is not thrown off by rows added or removed meanwhile:

```php
$db->table('users')->where('active', true)->chunkById(500, function (array $rows): void {
    foreach ($rows as $row) {
        // ...
    }
});
```

Use it in commands and jobs that walk a whole table, on an otherwise unordered query; select the cursor column and do not change it while walking the rows.

## In a transaction

Start from the transaction's connection, not from `$db`:

```php
$db->transaction(function (Connection $tx) use ($orderId): void {
    $order = $tx->table('orders')->where('id', $orderId)->lockForUpdate()->first();
    $tx->table('stock')->where('sku', $order['sku'])->decrement('quantity', $order['quantity']);
    $tx->table('orders')->where('id', $orderId)->update(['status' => 'reserved']);
});
```

On MySQL, `lockForUpdate()` holds the selected rows until the transaction ends. On SQLite it adds no SQL; a Crab transaction takes the database write lock when it starts.

## Raw SQL

Methods ending in `Raw` put SQL in as you write it. Each takes bindings; give placeholders, never a user's text:

```php
$db->table('orders')
    ->selectRaw('date(created_at) as day, sum(total) as revenue')
    ->whereRaw('total > ? * tax', [10])
    ->groupBy('day')
    ->orderByRaw('revenue desc')
    ->get();
```

Raw SQL is not rewritten for the database in use, so it may tie the query to one.

## Good to know

- **Building changes the query; running does not.** `get()`, `count()` and the rest leave it as it was, so one query can be counted and then fetched. `clone` it to branch into two.
- **Values are bound and names are quoted**, so both may come from a request. Only the `Raw` methods take SQL as written.
- **`update()` and `delete()` accept only WHERE conditions**; joins, grouping, HAVING, ordering, limits and offsets are refused.
- **`toSql()` and `bindings()`** show what would run, for debugging.
