# Contributing

Thank you for helping. This page says how to set up, what to run, and what a change needs before it is merged.

## Set up

Crab is five repositories that read each other from sibling folders until the first release, so clone them side by side:

```sh
mkdir crabphp && cd crabphp
for repo in framework crab sample clockwerk redis; do git clone git@github.com:crabphp/$repo.git; done
cd framework && composer install
```

You need PHP 8.4 with `mbstring`, `pcntl`, `posix`, `sockets` and `pdo_sqlite`, and Composer. MySQL 8.4+, Redis and Docker are needed only for the checks that use them.

## Test

```sh
composer test                                                      # the whole suite, on SQLite
CRAB_TEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=crab_test' composer test   # and on MySQL
composer test:linux                                                # in a Linux container (Docker)
composer test:linux --mysql                                        # with MySQL 8.4 and verified TLS
```

The MySQL tests use `root` with no password unless `CRAB_TEST_MYSQL_USER` and `CRAB_TEST_MYSQL_PASSWORD` say otherwise, and drop their own tables. Run the MySQL suite before changing `src/Database/`, and `composer test:linux` before changing process control (`src/Engine/Process/`, `src/Server.php`).

The `redis` package needs a Redis it may empty; never point it at one holding data:

```sh
redis-server --port 6399 --save '' --appendonly no --daemonize yes
CRAB_TEST_REDIS_URL=redis://127.0.0.1:6399 vendor/bin/phpunit
redis-cli -p 6399 shutdown nosave
```

A change to an HTTP or routing path that runs on every request is measured too; see [BENCHMARKS.md](BENCHMARKS.md).

## Format and lint

PHP is formatted and linted by [Mago](https://mago.carthage.software), installed with the dev dependencies:

```sh
composer format        # format every file
composer format:check  # what CI runs: fails on a file that is not formatted
composer lint
```

Each repository has the same `mago.toml`. A lint rule is turned off there only with a comment saying why.

## Documentation

`docs/` is the guide for application developers. A change to what an application writes, runs or configures changes its page in the same pull request.

```sh
composer docs        # preview at http://127.0.0.1:8900
composer docs:check  # links, class names, commands and settings named in the docs exist
```

## Conventions

Read [AGENTS.md](AGENTS.md) before writing code: it holds every decided convention, and a pull request that changes one changes that file too. [ARCHITECTURE.md](ARCHITECTURE.md) is the design and [COMPONENTS.md](COMPONENTS.md) the flow for building a component. In short:

- No new runtime dependency without asking first.
- What a package touches has a contract in `Crab\Contracts`.
- Plain statements that read top to bottom, and an error message that says what to do instead.
- Out of scope unless agreed: a model layer, sessions, roles and permissions, PostgreSQL.

## Pull requests

- CI is green: format, lint, docs and the tests on Linux and macOS.
- New behaviour has a test, and a fixed bug has a test that failed before the fix.
- The docs, the README and the skeleton (`crab`) follow the change.
- One change per pull request, with a commit message that says what changed for someone using Crab, as the history does ("Send text made in an action as a download").

Report a security problem privately; see [SECURITY.md](SECURITY.md).
