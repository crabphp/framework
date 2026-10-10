# Building a component

The flow for adding a component to Crab. It turns the design in [ARCHITECTURE.md](ARCHITECTURE.md) and the rules in [AGENTS.md](AGENTS.md) into steps, so that every component is built the same way and the tenth is as easy as the first.

The running example is a cache component, cut down to what shows the flow. The real one is in `src/Cache/`, where the class in front and the stores behind it are separate.

## 1. Answer five questions first

Write the answers down before any code. They decide everything that follows.

| Question | Why it matters | Cache example |
| --- | --- | --- |
| Is there a PSR for it? | The Crab contract extends it | PSR-16 `CacheInterface` |
| Is it core or optional? | Core is bound by `App`; optional ships a provider | Optional |
| What scope is its state? | Process, worker or request; see the table in step 4 | Worker: one store connection per worker |
| Does it do I/O? | I/O components expose a pool and open nothing in the parent | Yes for Redis, no for an in-memory array |
| Which components does it build on? | Each one must be listed in `LayerBoundaryTest::ALLOWED` | None |

A component is **core** only if the application cannot run without it. Everything else is optional, including everything of ours still to be built.

## 2. Contract first

Decide what a package will type-hint, before writing the class.

- **A PSR exists:** add `Crab\Contracts\<Component>\<Name>` extending the PSR interface, with the extra methods Crab needs, or none. Cache does this with `Crab\Contracts\Cache\Cache extends Psr\SimpleCache\CacheInterface`.
- **No PSR exists:** add `Crab\Contracts\<Component>\<Name>` on its own.

Every service has a Crab contract, even one that adds nothing to its PSR interface: it is the name applications ask for, and the place a method goes when Crab needs one. The provider binds the contract and also binds the PSR interface to the same object.

A contract references PSR interfaces, other contracts and engine types, nothing else. Keep it to what a package needs; methods only the glue uses do not belong in it.

Adding a PSR interface package to `composer.json` is allowed. Any other dependency needs the owner's agreement first.

## 3. Build it standalone

The class lives in `src/<Component>/` and works with plain `new`.

~~~php
namespace Crab\Cache;

use Crab\Contracts\Cache\Cache;

final class FileCache implements Cache
{
    public function __construct(private readonly string $directory, private readonly int $defaultTtl = 3600)
    {
    }

    // get(), set(), delete(), ...
}
~~~

- The constructor takes plain values, PSR interfaces and contracts. It never takes `App` or the container, and never calls `config()` or `env()`.
- It opens nothing in the constructor. A file, socket or connection is opened on first use, so the object is safe to create anywhere.
- It keeps no state of a single request.

## 4. Put state in the right scope

| Scope | Lives | Created | Example |
| --- | --- | --- | --- |
| Process | Whole run, copied to every worker | In the parent, before `serve` | Config values, the list of cache stores |
| Worker | One worker's life | In the worker, on first use or in `start()` | The Redis connection, the cache object |
| Request | One request | During the request | The cache key built from a route parameter |

Request-scope data is passed as an argument or carried on the PSR-7 request. If a class needs a property that changes per request, the design is wrong; make it an argument.

The container has three ways to hold an entry, and the scope decides which:

| Method | Gives | Use for |
| --- | --- | --- |
| `singleton()` | One object per worker, made on first use | Almost everything: services, stores, clients |
| `bind()` | A new object on every `get()` | An object that carries state for one piece of work, such as a query builder |
| `instance()` | An object that already exists | Values made in the parent that are safe to copy into workers, such as configuration |

There is no per-request container scope. A `bind()` entry is new each time it is asked for, not once per request.

## 5. Test it without the application

Tests go in `tests/<Component>/` and construct the class directly. No `App`, no container, no sockets. Cover the failure paths (missing key, unwritable directory, expired entry), not only the success path. If a public conformance suite exists for the PSR, run the component against it.

A provider with `start()` and `stop()` is tested with `Testing\WorkerHarness`, which runs it through a real worker's life in the test process:

~~~php
$hits = WorkerHarness::run([new CacheProvider()], static function (Worker $worker, Container $container): int {
    $cache = $container->get(Cache::class);
    $cache->set('key', 'value');
    Async::sleep($worker->loop, 0.01);   // the body may wait
    return $cache->get('key') === 'value' ? 1 : 0;
});
~~~

It registers the providers, starts them, runs the body, stops them, and throws again anything the body, a provider or a timer threw.

## 6. Write the provider

An optional component ships `<Component>Provider` in its own folder. This is the only place the component meets configuration and the container.

~~~php
namespace Crab\Cache;

use Crab\Contracts\Cache\Cache;
use Crab\Contracts\Config\Config;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Provider;
use Psr\SimpleCache\CacheInterface;

final class CacheProvider implements Provider
{
    public function register(Container $container): void
    {
        $container->get(Config::class)->defaults('cache', [
            'directory' => sys_get_temp_dir() . '/crab-cache',
            'ttl' => 3600,
        ]);
        $container->singleton(Cache::class, static function (Container $container): FileCache {
            $config = $container->get(Config::class);
            return new FileCache($config->get('cache.directory'), $config->get('cache.ttl'));
        });
        // The same object for code written against the standard.
        $container->singleton(CacheInterface::class, Cache::class);
    }
}
~~~

- `register()` only declares and binds. The closure runs later, in the worker, when the entry is first asked for.
- Declare every setting and its default once, with `Config::defaults()`. The component then works with no configuration file, a value the application sets wins, and `config('cache.ttl')` reads the same value everywhere.
- Bind the **interface**, not the class.
- Reach other parts of the application through their contracts in the container: `Contracts\Routing\Router` to add routes, `Contracts\Console\Console` to add commands, `Contracts\Http\MiddlewareStack` to add middleware around every route.
- If something must be open for the worker's whole life, or released when it stops, implement `Contracts\WorkerProvider` as well. `start(Worker $worker, Container $container)` opens it and `stop(Worker $worker, Container $container)` releases it; `stop()` must not suspend. The provider itself stays stateless, since both receive the container.

The application then opts in with one line: `$app->register(CacheProvider::class)`.

## 7. Give every entry point an error path

If the component runs code outside a request (a timer, a consumer loop, a spawned task), a failure there must reach the logger. A task spawned with `$worker->spawn()` and a timer or watcher on `$worker->loop` already do, and the worker keeps running. A loop that must survive its own failures, such as a queue consumer taking the next job, catches them itself and logs through `Crab\Contracts\Log\Logger`. Nothing fails silently.

## 8. Check the boundaries

Run `composer test`. `tests/LayerBoundaryTest.php` fails if the component references `App`, the built-in commands, or another component that is not listed for it. If the dependency is by design, add it to `ALLOWED` in that test, in the same change, and say why in the commit. Do not work around the test.

## 9. Measure if it is on the request path

A component that runs on every request (middleware, anything the router or the request touches) is measured against the engine alone, with JIT on, in the manner of `benchmarks/request-path.php`. Report the cost per request with the change. A component used only by some actions needs no measurement.

## 10. Document it

In the same change:

- **docs/:** a page for the component, or a section on the page it belongs to, written for someone building an application: what it is for, when to use it, how, with code. Run the `sync-docs` skill, which knows the pages' form and checks them against the code. Add a new page to `docs/documentation.md`.
- **README.md:** a short section with the one-line registration, the configuration keys and their defaults, one usage example, and a link to the page.
- **ARCHITECTURE.md:** the contract in the contracts table, and the build-order step marked done.
- **AGENTS.md:** the component's name in the component list, and any new rule that was decided while building it.

## Definition of done

- [ ] The five questions are answered and reflected in the code.
- [ ] The contract, or the decision to use a PSR directly, is in place before the class.
- [ ] The class works with `new`, opens nothing in its constructor and holds no request state.
- [ ] Tests in `tests/<Component>/` pass without `App`, and cover failures.
- [ ] An optional component has a provider that only declares defaults and binds.
- [ ] Per-worker resources are opened in `start()` and released in `stop()`, tested with `WorkerHarness`.
- [ ] Every entry point outside a request logs its failures.
- [ ] `composer test` passes, including the boundary tests.
- [ ] Request-path code is measured and the cost reported.
- [ ] docs/, README, ARCHITECTURE and AGENTS are updated.

## Common mistakes

| Mistake | What goes wrong | Do this |
| --- | --- | --- |
| Opening a connection in `register()` or a constructor | It is opened in the parent and shared by every forked worker | Open on first use, or in `start()` |
| Storing the current request or user on a service | Two overlapping requests in one worker read each other's data | Pass it as an argument |
| Type-hinting a component class from another package | The package breaks when the class changes | Type-hint the contract or PSR interface |
| Calling `env()` outside a config file | Configuration has two sources and cannot be cached | Read `config()` |
| Putting the interface inside the component folder | Packages must depend on the implementation to use it | Put it in `Crab\Contracts\<Component>` |
| Passing a default at every `config()` call | The same setting has different defaults in different places | Declare it once with `Config::defaults()` |
| Adding a `boot()` step | It reintroduces ordering between providers | Bind closures; they run after every provider has registered |
