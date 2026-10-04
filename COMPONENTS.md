# Building a component

The flow for adding a component to Crab. It turns the design in [ARCHITECTURE.md](ARCHITECTURE.md) and the rules in [AGENTS.md](AGENTS.md) into steps, so that every component is built the same way and the tenth is as easy as the first.

The running example is a cache component. It is an illustration; Cache is not built yet.

## 1. Answer five questions first

Write the answers down before any code. They decide everything that follows.

| Question | Why it matters | Cache example |
| --- | --- | --- |
| Is there a PSR for it? | The contract extends it, or the PSR is used directly | PSR-16 `CacheInterface` |
| Is it core or optional? | Core is bound by `App`; optional ships a provider | Optional |
| What scope is its state? | Process, worker or request; see the table in step 4 | Worker: one store connection per worker |
| Does it do I/O? | I/O components expose a pool and open nothing in the parent | Yes for Redis, no for an in-memory array |
| Which components does it build on? | Each one must be listed in `LayerBoundaryTest::ALLOWED` | None |

A component is **core** only if the application cannot run without it. Everything else is optional, including everything of ours still to be built.

## 2. Contract first

Decide what a package will type-hint, before writing the class.

- **A PSR covers it completely:** use the PSR interface directly and add no Crab contract. Cache does this with `Psr\SimpleCache\CacheInterface`.
- **A PSR covers part of it:** add `Crab\Contracts\<Component>\<Name>` extending the PSR interface, with only the extra methods.
- **No PSR exists:** add `Crab\Contracts\<Component>\<Name>` on its own.

A contract references PSR interfaces, other contracts and engine types, nothing else. Keep it to what a package needs; methods only the glue uses do not belong in it.

Adding a PSR interface package to `composer.json` is allowed. Any other dependency needs the owner's agreement first.

## 3. Build it standalone

The class lives in `src/<Component>/` and works with plain `new`.

~~~php
namespace Crab\Cache;

use Psr\SimpleCache\CacheInterface;

final class FileCache implements CacheInterface
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

## 5. Test it without the application

Tests go in `tests/<Component>/` and construct the class directly. No `App`, no container, no sockets. Cover the failure paths (missing key, unwritable directory, expired entry), not only the success path. If a public conformance suite exists for the PSR, run the component against it.

## 6. Write the provider

An optional component ships `<Component>Provider` in its own folder. This is the only place the component meets configuration and the container.

~~~php
namespace Crab\Cache;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Provider;
use Psr\SimpleCache\CacheInterface;

final class CacheProvider implements Provider
{
    public function register(Container $container): void
    {
        $container->singleton(CacheInterface::class, static function (Container $container): FileCache {
            $config = $container->get(Config::class);
            return new FileCache(
                $config->get('cache.directory', sys_get_temp_dir() . '/crab-cache'),
                $config->get('cache.ttl', 3600),
            );
        });
    }
}
~~~

- `register()` only binds. The closure runs later, in the worker, when the entry is first asked for.
- Bind the **interface**. Read every setting with a default, so the component works with no configuration file.
- Reach other parts of the application through their contracts in the container: `Contracts\Routing\Router` to add routes, `Contracts\Console\Console` to add commands.
- If something must be open for the worker's whole life, or released when it stops, implement `Contracts\WorkerProvider` as well. `start(Worker $worker)` opens it and `stop(Worker $worker)` releases it; `stop()` must not suspend.

The application then opts in with one line: `$app->register(CacheProvider::class)`.

## 7. Give every entry point an error path

If the component runs code outside a request (a timer, a consumer loop, a spawned task), a failure there must reach the logger. Work spawned with `$worker->spawn()` already does. Anything else catches its own failures and logs them through `Psr\Log\LoggerInterface`. Nothing fails silently.

## 8. Check the boundaries

Run `composer test`. `tests/LayerBoundaryTest.php` fails if the component references `App`, the built-in commands, or another component that is not listed for it. If the dependency is by design, add it to `ALLOWED` in that test, in the same change, and say why in the commit. Do not work around the test.

## 9. Measure if it is on the request path

A component that runs on every request (middleware, anything the router or the request touches) is measured against the engine alone, with JIT on, in the manner of `benchmarks/request-path.php`. Report the cost per request with the change. A component used only by some handlers needs no measurement.

## 10. Document it

In the same change:

- **README.md:** a short section with the one-line registration, the configuration keys and their defaults, and one usage example.
- **ARCHITECTURE.md:** the contract in the contracts table, and the build-order step marked done.
- **AGENTS.md:** the component's name in the component list, and any new rule that was decided while building it.

## Definition of done

- [ ] The five questions are answered and reflected in the code.
- [ ] The contract, or the decision to use a PSR directly, is in place before the class.
- [ ] The class works with `new`, opens nothing in its constructor and holds no request state.
- [ ] Tests in `tests/<Component>/` pass without `App`, and cover failures.
- [ ] An optional component has a provider that only binds, with defaults for every setting.
- [ ] Per-worker resources are opened in `start()` and released in `stop()`.
- [ ] Every entry point outside a request logs its failures.
- [ ] `composer test` passes, including the boundary tests.
- [ ] Request-path code is measured and the cost reported.
- [ ] README, ARCHITECTURE and AGENTS are updated.

## Common mistakes

| Mistake | What goes wrong | Do this |
| --- | --- | --- |
| Opening a connection in `register()` or a constructor | It is opened in the parent and shared by every forked worker | Open on first use, or in `start()` |
| Storing the current request or user on a service | Two overlapping requests in one worker read each other's data | Pass it as an argument |
| Type-hinting a component class from another package | The package breaks when the class changes | Type-hint the contract or PSR interface |
| Calling `env()` outside a config file | Configuration has two sources and cannot be cached | Read `config()` |
| Putting the interface inside the component folder | Packages must depend on the implementation to use it | Put it in `Crab\Contracts\<Component>` |
| Adding a `boot()` step | It reintroduces ordering between providers | Bind closures; they run after every provider has registered |
