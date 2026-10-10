# Cache

- [Introduction](#introduction)
- [Configuration](#configuration)
    - [Choosing a store](#choosing-a-store)
    - [The database store](#the-database-store)
- [Using the cache](#using-the-cache)
    - [Remembering a value](#remembering-a-value)
    - [Storing and reading](#storing-and-reading)
    - [Removing](#removing)
    - [Several keys at once](#several-keys-at-once)
- [Keys and time to live](#keys-and-time-to-live)
- [Locks](#locks)
- [A store of your own](#a-store-of-your-own)
- [In tests](#in-tests)

## Introduction

The cache keeps the result of slow work so that the next request does not repeat it: exchange rates fetched from another API, a count that takes a second to compute, a rendered report. You give a value a key and a time to live, and read it back until it expires.

Use it for values you can make again. A cache may be emptied at any time, so nothing should exist only there.

It is the PSR-16 cache, so a library that asks for `Psr\SimpleCache\CacheInterface` is given the same one.

## Configuration

Register the provider:

```php
// app.php
use Crab\Cache\CacheProvider;

->withProviders([
    CacheProvider::class,
    AppProvider::class,
])
```

It works with no configuration, keeping entries in files. To change that, add `config/cache.php`:

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
| `cache.store` | `file` | Where entries are kept: `file`, `database` or `array` |
| `cache.directory` | the system's temporary folder | The file store's folder |
| `cache.table` | `cache` | The database store's table |
| `cache.database` | `null` | The name of the database the table is in; the default one when null |
| `cache.prefix` | none | Put before every key, to keep two applications apart in one store |
| `cache.ttl` | `null` | Seconds an entry lives when you give none; forever when null |

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

Register `DatabaseProvider` as well. The migration is yours to read and change. An entry is stored as text, which on MySQL holds about 48 kB; keep larger values in files.

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

### Several keys at once

```php
$cache->setMultiple(['a' => 1, 'b' => 2], 300);
$values = $cache->getMultiple(['a', 'b', 'c'], 0);     // ['a' => 1, 'b' => 2, 'c' => 0]
$cache->deleteMultiple(['a', 'b']);
```

## Keys and time to live

A key is text without any of `{}()/\@:` in it, as PSR-16 requires. Separate its parts with a dot or a dash: `user.7.orders`, not `user:7:orders`. A key that breaks the rule throws, with a message that says what a key may be.

A time to live is a number of seconds, a `DateInterval`, or left out for `cache.ttl`:

```php
$cache->set('report', $report, 3600);
$cache->set('report', $report, new \DateInterval('P1D'));
```

Give every entry a time to live unless you delete it yourself when its source changes. An entry that lives forever and is never deleted is a bug waiting for the day the data changes.

## Locks

`add()` sets a key only when it holds nothing, and tells you whether it did. Of several callers at the same moment, exactly one is told `true`. That makes it a lock:

```php
if (!$cache->add('reports.monthly.running', true, 600)) {
    return;                                    // someone else is already building it
}

try {
    $this->buildMonthlyReport();
} finally {
    $cache->delete('reports.monthly.running');
}
```

Always give the lock a time to live, longer than the work takes: if the process dies before the `finally`, the lock still goes away. Across machines this needs a store they share, which is the database store.

## A store of your own

To keep entries somewhere else, such as Redis, implement `Crab\Contracts\Cache\Store` and bind it after the cache provider:

```php
// app/Providers/AppProvider.php
use Crab\Contracts\Cache\Store;

$container->singleton(Store::class, static fn (): Store => new RedisStore(config('redis.host')));
```

A store only stores text by key with an expiry time: `get()`, `set()`, `add()`, `delete()` and `clear()`. Checking keys, serializing values and reading the clock stay with the cache in front of it. `add()` must be one step with respect to every other caller, or locks built on it do not hold.

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
