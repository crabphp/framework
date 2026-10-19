# Cache

- [Introduction](#introduction)
- [Configuration](#configuration)
    - [Choosing a store](#choosing-a-store)
    - [The database store](#the-database-store)
- [Using the cache](#using-the-cache)
    - [Remembering a value](#remembering-a-value)
    - [Storing and reading](#storing-and-reading)
    - [Removing](#removing)
    - [Taking a value once](#taking-a-value-once)
    - [Several keys at once](#several-keys-at-once)
- [Keys and time to live](#keys-and-time-to-live)
- [Counting](#counting)
- [Locks](#locks)
- [Cleaning up](#cleaning-up)
- [A store of your own](#a-store-of-your-own)
- [In tests](#in-tests)

## Introduction

The cache keeps the result of slow work so that the next request does not repeat it: exchange rates fetched from another API, a count that takes a second to compute, a rendered report. You give a value a key and a time to live, and read it back until it expires.

Use it for values you can make again. A cache may be emptied at any time, so nothing should exist only there.

It is the PSR-16 cache, so a library that asks for `Psr\SimpleCache\CacheInterface` is given the same one.

## Configuration

The cache is registered by default by `App::configure()`, so it needs nothing in `app.php`.

An application with a base path keeps cache files in its own `storage/cache` directory, even without a cache configuration file. `App::configure($basePath)` exposes that base as `app.path`. An application without a base path sets `cache.directory`; the file store is never kept in a shared temporary folder, where another account could put entries first. The file store refuses a folder that another user can write to or owns: `chmod 700` it, or set `cache.directory`. The skeleton's `config/cache.php` reads `CACHE_STORE`; add a directory when you want to override the default:

```php
// config/cache.php
return [
    'store' => env('CACHE_STORE', 'file'),
    'directory' => dirname(__DIR__) . '/storage/cache',
    'prefix' => 'orders.',
];
```

| Setting | Default | |
| --- | --- | --- |
| `cache.store` | `file` | Where entries are kept: `file`, `database` or `array`, or `redis` with the `crabphp/redis` package |
| `cache.directory` | `null`: `storage/cache` under `app.path`; required without a base path | The file store's folder; an explicit path overrides the default |
| `cache.table` | `cache` | The database store's table |
| `cache.database` | `null` | The name of the database the table is in; the default one when null |
| `cache.prefix` | none | Put before every key, to keep two applications apart in one store |
| `cache.ttl` | `null` | Seconds an entry lives when you give none; forever when null |

A `cache.prefix` cannot contain `:lock:`: that text is kept for the cache's locks.

### Choosing a store

| Store | Entries are kept | Use it when |
| --- | --- | --- |
| `file` | As files under `cache.directory` | The application runs on one machine. Every worker on it shares the entries. |
| `database` | In a table of your database | It runs on several machines, which must see the same entries |
| `array` | In the worker's memory | In tests. Each worker has its own, and it is gone when the worker stops. |

### The database store

```php
// config/cache.php
return ['store' => 'database'];
```

```sh
php cua cache:install        # writes the cache table's migration into your migrations
php cua migrate
```

Configure a [database connection](database.md#configuration) for this store. The migration is yours to read and change. An entry is stored as text, which on MySQL holds about 48 kB; keep larger values in files.

> [!NOTE]
> Reading the file or database store holds the worker for the moment it takes, as any file or database access does. That is far less than the work you are caching, but do not cache what is already fast.

## Using the cache

Ask for it in a constructor:

```php
use Crab\Contracts\Cache\Cache;

final readonly class ShowRates
{
    public function __construct(private Cache $cache, private Bank $bank)
    {
    }

    public function handle(): array
    {
        return $this->cache->remember('rates', 3600, fn (): array => $this->bank->rates());
    }
}
```

### Remembering a value

`remember()` is the method you will use most. It returns the value at the key; when there is none, it calls the closure, keeps what it returns for the time to live, and returns that:

```php
$count = $cache->remember('users.count', 60, fn (): int => $db->table('users')->count());
```

Two requests that miss at the same moment may both run the closure. That is fine for a value that is merely slow; where the work must not run twice, take a [lock](#locks).

### Storing and reading

```php
$cache->set('user.7', $user, 600);             // for ten minutes
$cache->set('settings', $settings);            // for the default time to live

$user = $cache->get('user.7');                 // null when missing or expired
$user = $cache->get('user.7', $guest);         // with a default
$cache->has('user.7');                         // true or false
```

Anything PHP can serialize may be cached, objects included.

> [!NOTE]
> `get()` cannot tell a cached `null` from a missing key. Use `has()`, or do not cache `null`.

### Removing

```php
$cache->delete('user.7');
$cache->clear();                               // everything
```

Delete an entry when what it was made from changes: after updating user 7, delete `user.7`.

```sh
php cua cache:clear
```

empties the cache from the command line, for example after a deploy that changes what is cached. An `array` store lives inside each worker and is emptied by restarting the server.

`cache.prefix` does not scope `clear()` or `cache:clear` on the array, file and database stores: they remove every entry in the shared store. Redis clears only entries under its store's own prefix.

> [!WARNING]
> `clear()` and `cache:clear` remove active locks too. Do not clear a store whose locks are in use: another worker could take the same lock while its first holder is still working.

### Taking a value once

Use `take()` for a short-lived value that only one caller may consume, such as the identity id behind a browser authentication ticket:

```php
$cache->add('ticket.' . hash('sha256', $ticket), $user->id, 30);
$id = $cache->take('ticket.' . hash('sha256', $ticket));
```

Taking returns the value and removes it atomically. Concurrent callers have at most one winner; a missing or expired entry returns `null`, or the default you pass as the second argument. A stored `null` is returned as `null` and still consumed. Use a shared database or Redis store across machines; file storage shares entries between workers on one machine, and array storage is local to one worker. For browser sockets, use the framework's [ticket authentication](websockets.md#authenticating-a-browser), which also creates the random ticket and resolves its user.

### Several keys at once

```php
$cache->setMultiple(['a' => 1, 'b' => 2], 300);
$values = $cache->getMultiple(['a', 'b', 'c'], 0);     // ['a' => 1, 'b' => 2, 'c' => 0]
$cache->deleteMultiple(['a', 'b']);
```

## Keys and time to live

A key is nonempty text without any of `{}()/\@:` in it, as PSR-16 requires. Separate its parts with a dot or a dash: `user.7.orders`, not `user:7:orders`. A key that breaks the rule throws, with a message that says what a key may be.

A time to live is a whole number of seconds, a `DateInterval`, or left out or `null` for `cache.ttl` (forever only when that default is null). With zero or a negative time to live, `set()` deletes the entry and `add()` returns `false` without changing it:

```php
$cache->set('report', $report, 3600);
$cache->set('report', $report, new \DateInterval('P1D'));
```

Give every entry a time to live unless you delete it yourself when its source changes. An entry that lives forever and is never deleted is a bug waiting for the day the data changes.

A time to live too long for a timestamp throws `InvalidArgumentException`, and so does a `DateInterval` with more than a hundred million of any part.

## Counting

`increment()` adds to the whole number at a key and returns what it now is. It is one step, so of several callers at the same moment each is told a different count and none is lost:

```php
$attempts = $cache->increment('login.' . sha1($email), ttl: 900);

if ($attempts > 5) {
    throw new HttpException(429, 'Too many attempts. Try again in a quarter of an hour.');
}
```

A key that holds nothing counts from zero. The time to live belongs to the count as it starts and is not moved by later additions, so the example counts attempts in fifteen minutes, not since the last one. Give a second argument to add more than one, or less than zero to take away: `$cache->increment('stock.7', -2)`.

To limit how often a client may call a route, use the [`RateLimit` middleware](middleware.md#rate-limiting), which is built on this.

## Locks

Take a lock when only one worker should build a report at a time. `lock()` returns a lock you can release, or `null` when someone holds it:

```php
$lock = $cache->lock('reports.monthly', 600);
if ($lock === null) {
    return;                                    // someone else is already building it
}

try {
    $this->buildMonthlyReport();
} finally {
    $lock->release();                           // false if it expired or was already released
}
```

A positive time to live is required, in seconds or as a `DateInterval`. Give it longer than the work takes: expiry lets another worker start, even if the first is still running. If the process dies, the lock still expires. A late `release()` returns `false` and leaves a new holder's lock alone. Locks use keys of their own, so storing or deleting a value with the same key does not affect them.

Clearing the store removes its locks as well. Do not run `clear()` or `cache:clear` while work relies on those locks.

Across machines, use a shared database or Redis store. An array store coordinates only callers inside one worker. `add()` remains available to set an ordinary value only when its key holds nothing, returning whether it did.

## Cleaning up

Expired file and database entries stop being returned, but still occupy space until replaced or removed. Run the cleanup command regularly when keys keep changing, such as rate limits for each client and minute:

```sh
php cua cache:prune
```

It prints how many expired entries it removed and keeps live entries and those with no expiry. To run it hourly, declare it on the [schedule](scheduling.md):

```php
$schedule->command('cache:prune')->hourly();
```

Redis drops expired entries itself and needs no scheduled cleanup; its prune command reports zero. An array store lives inside each worker, so a separate command cannot prune the other workers' entries.

## A store of your own

For Redis, install the `crabphp/redis` package, add its `RedisProvider` in `app.php` and set `cache.store` to `redis`. To keep entries somewhere else again, implement `Crab\Contracts\Cache\Store` and bind it after the cache provider:

```php
// app/Providers/AppProvider.php
use App\CacheStore;
use Crab\Contracts\Cache\Store;

$container->singleton(Store::class, CacheStore::class);
```

A store only stores text by key with an expiry time: `get()`, `take()`, `set()`, `add()`, `increment()`, `delete()`, `deleteIf()`, `prune()` and `clear()`. Checking keys, serializing values and reading the clock stay with the cache in front of it. `add()`, `take()`, `increment()` and `deleteIf()` must each be one step with respect to every other caller, or the credentials, locks and limits built on them do not hold. `take(string $key, int $now): ?string` returns and removes a live entry atomically, or returns null when missing or expired; never implement it as separate unlocked get and delete calls. `deleteIf()` removes only a live entry holding the exact given text; `prune()` removes expired entries and returns the count.

## In tests

Set the store to `array` in your test configuration, or build a cache directly:

```php
use Crab\Cache\ArrayStore;
use Crab\Cache\Cache;
use Crab\Testing\FrozenClock;

$clock = new FrozenClock('2026-01-31 12:00:00');
$cache = new Cache(new ArrayStore(), clock: $clock);

$cache->set('rates', $rates, 60);
$clock->advance('+61 seconds');

self::assertNull($cache->get('rates'));
```

See [Testing](testing.md).
