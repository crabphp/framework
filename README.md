# Crab Framework

A minimal PHP 8.4 web framework built on the Crab networking engine. It is at an early stage: the engine is implemented and measured; the framework components around it are not written yet.

## Layout

| Path | Contents |
| --- | --- |
| `src/Engine/` | The networking engine: TCP, UDP, HTTP/1.1, WebSocket, coroutines, timers and supervised workers. It has its own `composer.json` and is also published as `crabphp/engine`. See its [README](src/Engine/README.md) and [architecture](src/Engine/ARCHITECTURE.md). |
| `src/` | Framework components, each a namespace beside `Engine`. |
| `tests/Engine/` | Engine tests. `tests/EngineBoundaryTest.php` keeps the engine free of framework dependencies. |
| `benchmarks/` | Engine benchmarks and their recorded results; start at [BENCHMARKS.md](BENCHMARKS.md). |
| `examples/` | Runnable engine examples. |

## Develop

~~~sh
composer install
composer test
php bin/crab examples/servers.php --single
~~~

`composer test:linux` and `composer soak` are manual checks, described in the engine README.
