# Database: getting started

- [Introduction](#introduction)
- [Configuration](#configuration)
    - [Several databases](#several-databases)
- [Running SQL](#running-sql)
- [Transactions](#transactions)
- [Connections](#connections)
- [Not blocking the worker](#not-blocking-the-worker)
- [Errors](#errors)
- [What there is not](#what-there-is-not)

## Introduction

Crab's database layer is thin and explicit: pooled connections, SQL with bound values, a [query builder](queries.md) for the common statements, and [migrations](migrations.md) for the tables. Rows are plain arrays.

MySQL and SQLite are supported. SQLite needs no server and suits development, tests and small services; the skeleton uses it until you set a MySQL address. PostgreSQL is not supported. A SQLite file is shared by every process of the application, so it is opened with a write-ahead log and a transaction takes the write lock when it starts: writers wait their turn, for up to five seconds, where they would otherwise be refused with "database is locked".

## Configuration

The database provider is registered by default by `App::configure()`, so it needs nothing in `app.php`; configure a connection before using it.

```php
// config/database.php
return [
    'default' => 'main',

    'connections' => [
        'main' => [
            'dsn' => env('DB_DSN', 'sqlite:' . dirname(__DIR__) . '/storage/database.sqlite'),
            'username' => env('DB_USERNAME'),
            'password' => env('DB_PASSWORD'),
            'migrations' => dirname(__DIR__) . '/database/migrations',
        ],
    ],
];
```

An application with no database configured still starts; asking for the database then names the missing configuration. A database is always described under `database.connections`, by a name of your choosing, whether you have one or several. `database.default` names the one you get when you name none; left out, it is the first.

```text
DB_DSN=mysql:host=127.0.0.1;dbname=orders;charset=utf8mb4
DB_USERNAME=orders
DB_PASSWORD=secret
```

Each connection takes these keys:

| Key | Default | |
| --- | --- | --- |
| `dsn` | none | `mysql:host=...;dbname=...` or `sqlite:/path/to/file.sqlite`. The kind of database is read from it. |
| `username`, `password` | none | For MySQL |
| `options` | `[]` | Further PDO attributes |
| `async` | `false` | Use the nonblocking MySQL driver inside workers |
| `pool` | 10, or 1 for SQLite | The most connections each worker opens |
| `timeout` | `5.0` | Seconds to wait for a free connection |
| `migrations` | none | The folder that holds this database's migration files |

### Several databases

Add another under a name of its own:

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
> Inside the callback use the connection it is given, `$tx`, not `$db`. Calling `$db` borrows another connection outside the transaction, or waits and can time out when none is free.

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

Connections are pooled per worker. Each statement on `$db` borrows one and returns it; a worker opens them on first use, at most `pool` of them.

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

## Not blocking the worker

Turn on `async` when MySQL queries keep your HTTP workers waiting while other requests could run. The driver suspends the calling fiber during socket I/O and lets the worker serve other requests. SQL, bound values, rows, transactions, migrations and the query builder keep their existing contracts.

```php
// config/database.php
'connections' => [
    'main' => [
        'dsn' => env('DB_DSN'),
        'username' => env('DB_USERNAME'),
        'password' => env('DB_PASSWORD'),
        'async' => (bool) env('DB_ASYNC', false),
        'pool' => 10,
    ],
],
```

With this enabled, `pool` is the number of queries one worker can have in flight. A pool of one queues queries even though the driver waits on the event loop. Borrow a separate connection for each concurrent operation; two fibers using one borrowed connection at once are refused. `timeout` bounds connection setup and waiting for a free connection. Queries have no deadline of their own.

`async` is each connection's own setting. SQLite and code outside a running provider worker, including ordinary console commands and `migrate`, keep using PDO.

TLS is off by default. Enable it with `'options' => ['ssl' => true]`, or supply PHP SSL stream options:

```php
// Inside config/database.php
'options' => ['ssl' => ['cafile' => '/etc/mysql/ca.pem']],
```

With `ssl => true`, `verify_peer` and `verify_peer_name` both default to true: the certificate and the DSN host name are verified. Explicit SSL context options can override those defaults. PHP's OpenSSL extension is needed for TLS and for full `caching_sha2_password` authentication over TCP without TLS. Unix sockets work through `unix_socket=/path/mysql.sock` in the DSN. Integer PDO options are ignored by the async driver.

The RSA public-key path trusts the key the server sends; it does not authenticate that server. Use verified TLS across an untrusted network, or a trusted local transport. The async driver does not support pinning a public-key file through PDO's integer options.

The driver is tested against MySQL 8.0, 8.4 and 9.7. Two things differ between them:

- `VECTOR` columns need MySQL 9 or newer.
- MySQL 8.4 and newer switch `mysql_native_password` off by default, and an account that still uses it is refused with error 1524. Move such accounts to `caching_sha2_password`.

A certificate MySQL generates for itself names no host, so trusting its CA alone does not pass the host name check. Give the server a certificate for the host in your DSN; the async driver also takes the name to expect as `peer_name`, which console commands on PDO cannot.

Console commands, including `migrate`, map `cafile`, `capath`, `local_cert`, `local_pk` and `ciphers` to PDO's SSL attributes. `ssl => true` requests TLS with the system's trust and both verification checks enabled. PDO couples certificate and name verification, so `verify_peer` and `verify_peer_name` must be equal; both default to true. Other context keys, including `peer_name`, and conflicting integer PDO SSL attributes are refused. Before lending the connection, Crab checks that the session reports a non-empty SSL cipher and refuses it if TLS was not established.

The driver targets MySQL 8.0–9.x, including VECTOR values returned as binary strings, with `caching_sha2_password` or `mysql_native_password` authentication. MariaDB, other authentication plugins, compression and `LOCAL INFILE` are outside its supported scope. Bind SQL values normally; an unsupported prepared statement falls back to a text command only when it has no bindings. An abandoned exchange drops its session, and worker teardown closes outstanding sessions at the drain deadline. Ordinary pooled task cancellation is cooperative: it does not promptly interrupt a query without a supplied cancellation token.

## Errors

A failed statement throws `Crab\Database\DatabaseException`, which keeps the SQL and the driver's own message; the driver may quote a value (MySQL names a duplicate entry). Crab never adds bindings to messages or log lines, but SQL literals are logged as written, so pass values as bindings.

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
