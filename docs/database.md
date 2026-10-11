# Database: getting started

- [Introduction](#introduction)
- [Configuration](#configuration)
    - [Several databases](#several-databases)
- [Running SQL](#running-sql)
- [Transactions](#transactions)
- [Connections](#connections)
- [Errors](#errors)
- [What there is not](#what-there-is-not)

## Introduction

Crab's database layer is thin and explicit: pooled connections, SQL with bound values, a [query builder](queries.md) for the common statements, and [migrations](migrations.md) for the tables. Rows are plain arrays.

MySQL and SQLite are supported. SQLite needs no server and suits development, tests and small services; the skeleton uses it until you set a MySQL address. PostgreSQL is not supported. A SQLite file is shared by every process of the application, so it is opened with a write-ahead log and a transaction takes the write lock when it starts: writers wait their turn, for up to five seconds, where they would otherwise be refused with "database is locked".

## Configuration

```php
// app.php
use Crab\Database\DatabaseProvider;

->withProviders([
    DatabaseProvider::class,
    AppProvider::class,
])
```

```php
// config/database.php
return [
    'dsn' => env('DB_DSN', 'sqlite:' . dirname(__DIR__) . '/storage/database.sqlite'),
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
    'migrations' => dirname(__DIR__) . '/database/migrations',
];
```

```text
DB_DSN=mysql:host=127.0.0.1;dbname=orders;charset=utf8mb4
DB_USERNAME=orders
DB_PASSWORD=secret
```

| Setting | Default | |
| --- | --- | --- |
| `database.dsn` | none | `mysql:host=...;dbname=...` or `sqlite:/path/to/file.sqlite`. The kind of database is read from it. |
| `database.username`, `database.password` | none | For MySQL |
| `database.options` | `[]` | Further PDO attributes |
| `database.pool` | 10, or 1 for SQLite | The most connections each worker opens |
| `database.timeout` | `5.0` | Seconds to wait for a free connection |
| `database.migrations` | none | The folder that holds the migration files |
| `database.connections`, `database.default` | none | For several databases; see below |

### Several databases

Describe each under a name, and say which is the default:

```php
// config/database.php
return [
    'default' => 'main',
    'connections' => [
        'main' => [
            'dsn' => env('DB_DSN'),
            'username' => env('DB_USERNAME'),
            'password' => env('DB_PASSWORD'),
            'migrations' => dirname(__DIR__) . '/database/migrations',
        ],
        'reports' => [
            'dsn' => env('REPORTS_DSN'),
            'migrations' => dirname(__DIR__) . '/database/reports',
        ],
    ],
];
```

```php
$db->table('users')->count();                                  // the default
$db->connection('reports')->table('daily_totals')->get();      // another, by name
```

Each has its own connections and its own migrations; the migration commands take `--database=reports`. A transaction is on one database: neither MySQL nor SQLite can commit across two.

## Running SQL

Ask for the database in a constructor:

```php
use Crab\Contracts\Database\Database;

final readonly class UserReport
{
    public function __construct(private Database $db)
    {
    }
}
```

```php
$users = $db->select('select * from users where active = ?', [true]);               // every row
$user  = $db->first('select * from users where id = :id', ['id' => 7]);              // one row, or null
$id    = $db->insert('insert into users (name) values (?)', ['Ada']);                // the new id
$count = $db->execute('update users set name = ? where id = ?', ['Ada L.', $id]);    // rows affected
```

A row is an array of column name to value.

> [!WARNING]
> Never build a value into the SQL text. Write `?` or `:name` and pass the values as the second argument. That is the whole of the defence against SQL injection, and it is always enough.

For most statements the [query builder](queries.md) is shorter and writes the SQL for the database in use. Write SQL yourself for reports and for anything the builder does not say.

## Transactions

A transaction makes several statements succeed or fail together. It commits when the callback returns and rolls back when it throws:

```php
use Crab\Contracts\Database\Connection;

$db->transaction(function (Connection $tx) use ($from, $to, $amount): void {
    $tx->execute('update accounts set balance = balance - ? where id = ?', [$amount, $from]);
    $tx->execute('update accounts set balance = balance + ? where id = ?', [$amount, $to]);
});
```

> [!WARNING]
> Inside the callback use the connection it is given, `$tx`, not `$db`. A statement on `$db` runs on a different connection, outside the transaction.

`transaction()` returns what the callback returns. A transaction inside a transaction is a savepoint: the inner one can roll back alone.

`afterCommit()` runs something once the transaction has committed, and never when it, or the inner transaction that asked, rolls back. Use it for what must not happen for a write that was undone:

```php
$db->transaction(function (Connection $tx) use ($id): void {
    $tx->table('products')->where('id', $id)->update(['price' => 900]);

    $tx->afterCommit(fn () => $this->cache->delete("product.{$id}"));
});
```

To push a job with a write, use the queue's [`within()`](queues.md#with-a-database-write), which does this and better.

Do not call another server from inside a transaction. The transaction holds a connection, and on MySQL locks, for as long as the call takes.

## Connections

Connections are pooled per worker. Each statement on `$db` borrows one and returns it; a worker opens them on first use, at most `database.pool` of them.

To run several statements on one connection without a transaction, borrow one for the length of a callback:

```php
$db->run(function (Connection $connection): void {
    $connection->execute('set @rank := 0');
    $rows = $connection->select('select @rank := @rank + 1 as position, name from users order by score desc');
});
```

A connection the server closed while it sat idle is replaced, and the statement runs once on a new one. When a connection is lost in the middle of a statement or inside a transaction, the failure is reported and nothing is retried, so nothing is applied twice.

> [!NOTE]
> A query holds its worker until the database answers. Keep queries fast, give slow reports to a [queue](queues.md), and raise `server.workers` to run more queries at once.

## Errors

A failed statement throws `Crab\Database\DatabaseException`. Its message and its `$sql` property hold the SQL and never the bound values, so it is safe to log.

Catch it where a failure means something to the client, such as a unique key:

```php
try {
    $id = $db->table('users')->insert(['email' => $email]);
} catch (DatabaseException $error) {
    throw new HttpException(409, 'That email is already registered.', previous: $error);
}
```

## What there is not

There is no ORM: no models, no relationships, no lazy loading. This is deliberate. Rows are arrays, queries are visible where they run, and there is no query you did not write.

Where you want a type, give a row one yourself:

```php
final readonly class User
{
    public function __construct(public int $id, public string $email)
    {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self((int) $row['id'], (string) $row['email']);
    }
}

$users = array_map(User::fromRow(...), $db->table('users')->get());
```

To load related rows, query for them: fetch the posts, then `whereIn('id', $userIds)` for their authors. Two queries you can read are better than a hundred you cannot see.
