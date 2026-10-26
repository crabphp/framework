# Query builder

- [Introduction](#introduction)
- [Reading rows](#reading-rows)
    - [Aggregates](#aggregates)
- [Conditions](#conditions)
    - [Groups of conditions](#groups-of-conditions)
    - [Conditional clauses](#conditional-clauses)
- [Selecting, joining and ordering](#selecting-joining-and-ordering)
- [Inserting](#inserting)
- [Updating](#updating)
- [Deleting](#deleting)
- [Pagination](#pagination)
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

A table may have an alias, `table('users as u')`. The list of a `whereIn()` may be another query: `whereIn('id', $db->table('posts')->select('user_id'))`.

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
