# Deployment

- [Introduction](#introduction)
- [Running the server](#running-the-server)
    - [While developing](#while-developing)
    - [In a container](#in-a-container)
    - [On a server you manage](#on-a-server-you-manage)
- [Deploying new code](#deploying-new-code)
    - [Services that cannot be made](#services-that-cannot-be-made)
    - [When the new code does not load](#when-the-new-code-does-not-load)
- [Finishing requests on stop](#finishing-requests-on-stop)
- [How many workers](#how-many-workers)
- [How many connections](#how-many-connections)
- [Behind a proxy](#behind-a-proxy)
- [The other processes](#the-other-processes)
- [A deploy, start to finish](#a-deploy-start-to-finish)
- [Before going live](#before-going-live)
- [How a background server holds together](#how-a-background-server-holds-together)

## Introduction

A Crab application is its own server. There is no PHP-FPM and no web server to configure in front of it for PHP's sake: `php cua start` listens on a port and answers. What you deploy is one command to keep running, and a way to give it new code.

This page covers the three ways to run it, how to replace code while requests are in flight, and what else must be running.

## Running the server

```sh
php cua dev                  # while developing: restarts when a file is saved
php cua start                # in the foreground, as it is
php cua start --daemon       # in the background, returning once it is up
```

| Option | |
| --- | --- |
| `--address=tcp://0.0.0.0:8080` | Where to listen; the default is `server.address` |
| `--workers=4` | Worker processes; the default is `server.workers` |
| `--single` | Serve in this one process, without the `pcntl` and `posix` extensions |
| `--daemon`, `-d` | Serve in the background; `start` only |
| `--watch=app,config` | What to watch, in place of `server.watch`; `dev` only |

Value options are checked before server control: `--workers` and `SERVER_WORKERS` in the skeleton are positive whole numbers within PHP's integer range; `--address` is `tcp://host:port` (bracket IPv6 hosts); and `--watch` lists existing readable local files or folders without empty entries. Mistyped values fail before startup, reload or restart contacts the running server.

### While developing

`dev` is for your own machine. It runs `start` as a child process and starts it again whenever a PHP file under `app`, `config` or `routes`, or `app.php` or `.env`, is added, changed or removed. When the code does not load, the error stays on the screen, and saving a fix starts the server again.

Set `server.watch` to other paths. `start` never watches, so a deploy that writes files does not restart a production server.

`dev` and foreground `start` write no background PID file; stop them through their terminal job or the process manager that started them.

### In a container

Run the server in the foreground and let the platform keep it alive:

```text
CMD ["php", "cua", "start", "--address=tcp://0.0.0.0:8080"]
```

A container is replaced, not reloaded: a deploy starts new containers and stops the old ones, and the platform moves traffic between them. On SIGTERM the server gives its requests up to `server.drain` seconds to finish, then cuts remaining connections and stops.

Point the platform's health check at the [health path](configuration.md#a-health-check), `GET /up`, after asking for it with `withRouting(health: '/up')` in `app.php`.

#### Ready for traffic

A platform that tells *alive* from *ready* restarts a container that fails the first check, and only stops sending traffic to one that fails the second. Keep the two apart. `/up` says the worker answers. A readiness action can also ask the database and the cache:

```php
namespace App\Actions;

use Crab\Contracts\Cache\Cache;
use Crab\Contracts\Database\Database;
use Crab\Http\HttpException;

final readonly class Ready
{
    public function __construct(
        private Database $db,
        private Cache $cache,
    ) {}

    public function handle(): array
    {
        try {
            $this->db->select('SELECT 1');
            $this->cache->get('ready');
        } catch (\Throwable $error) {
            // Logged with its cause; the client is told no more than this.
            throw new HttpException(503, 'Not ready.', previous: $error);
        }
        return ['status' => 'ready'];
    }
}
```

```php
// routes/web.php
Route::get('/ready', Ready::class);
```

Point the readiness probe at `GET /ready` and the liveness probe at `GET /up`. When the database is down, every container stops taking traffic and none is restarted, since restarting would not bring the database back. Ask only what every request needs: a mail provider or a payment API that is down should fail the requests that use it, not take the whole service out of rotation.

Give the probe a short timeout of its own, a second or two. The database's `timeout` setting bounds connecting and waiting for a free connection; a query has no deadline of its own, so a database that accepts the connection and then hangs is caught by the probe's timeout.

### On a server you manage

Choose the production settings and prepare the database in [A deploy, start to finish](#a-deploy-start-to-finish), then run it in the background:

```sh
php cua start --daemon       # start
php cua status               # its workers and what they have handled; exit status 0 when it runs
php cua reload               # new code, without refusing a connection
php cua restart              # stop, then start: a short gap
php cua stop                 # allow server.drain seconds for requests, then stop
```

`start --daemon` returns when the server accepts connections, and fails with the reason when it cannot start, so a deploy script can trust its exit status.

`stop` uses the running server's longest remaining stop allowance, with time to deliver the signal and collect its processes. Changing `server.drain` on disk, even to an invalid value, does not shorten that wait. `restart` drains the old server the same way, then uses the new configuration to start its replacement. An older server without a recorded allowance uses the configuration fallback, at least 15 seconds by default.

`--timeout` sets a graceful stop's wait exactly, including `--timeout=15`. Give a finite number of seconds at least zero; an invalid value is refused before the server is contacted. A shorter explicit timeout can return before draining finishes. Use `--force` to cut requests and end the background server, including registered programs. When the keeper does not lead its own session, force ends only the keeper, reports that workers may remain and exits non-zero; whatever started the server must end them.

```sh
php cua stop --timeout=15     # give a graceful stop exactly this wait
php cua stop --force          # cut requests and end the whole server now
```

The process id is in `server.pid` (`storage/crab.pid`) and everything the server prints goes to `server.log` (`storage/logs/server.log`).

With systemd:

```text
[Service]
Type=forking
PIDFile=/srv/app/storage/crab.pid
WorkingDirectory=/srv/app
ExecStart=/usr/bin/php cua start --daemon
ExecReload=/usr/bin/php cua reload
ExecStop=/usr/bin/php cua stop
Restart=on-failure
```

### What a server is doing

`status` shows every server of the application that is running, in the background or the foreground, `queue:work` and `schedule:work` included:

```text
$ php cua status
In the background on http://127.0.0.1:8080, pid 4120, up 3h 12m, reloaded 25m ago

  Worker       Pid   Up      Handled                 Connections  Memory  State
  -----------  ----  ------  ----------------------  -----------  ------  ---------------------
  http #0      4188  25m 3s  48,210 requests         12           6 MB    ready
  http #1      4201  4m 9s   7,032 requests          9            6 MB    ready, restarted once
  queue #0     4190  25m 3s  1,204 jobs, 3 failed    0            8 MB    ready
  schedule #0  4191  25m 3s  50 runs                 0            4 MB    ready

  Its output goes to /srv/app/storage/logs/server.log.
```

- **Handled** counts since the worker started: HTTP requests, queue jobs tried and those that failed, scheduled task runs. A reload or a restart starts the count again, so the numbers are for the code now running.
- **restarted** says how many times the server started that worker again after it died. A worker that dies keeps dying for a reason worth reading in the log.
- **State** is `starting` until the worker accepts work, then `ready`; `retiring` while it finishes what it has before a reload or a stop replaces it.
- A server that has not said how it is doing for five seconds is marked as *not heard from*: it is stuck, or too busy to run its loop.

`status --json` prints the same for a monitor or a script. Each server rewrites a file about once a second in `storage/crab.stats/`, beside `server.pid`, and removes it when it stops; `status` reads those files and passes over any left by a server that was killed.

## Deploying new code

A running server holds the code it started with. `reload` is how new code goes live:

```sh
php cua reload
```

The server starts a new process, with the code and configuration as they are now, on the socket it already listens on. When every worker of the new process accepts connections, the old process gives its requests up to its `server.drain` limit to finish and stops.

- **Connections keep being accepted.** The old and the new process accept from the same socket. Requests already in flight finish within the old workers' `server.drain` limit; connections still open at that limit are cut.
- **A reload that fails at startup leaves the old process serving.** Settings that do not fit are refused before the running server is touched. So is a service the code names and the container cannot make, as described in [Services that cannot be made](#services-that-cannot-be-made). Syntax errors, middleware construction and provider start hooks fail here too. What a constructor or a closure binding does when it runs is still found by the first request that needs it.
- **It starts the server as it was started.** An `--address` or `--workers` typed to `start` is kept by every `reload` and `restart` after it; one left to `server.address` or `server.workers` follows the configuration as it is now. Typing one to `reload` changes it without refusing a connection, and it is kept from then on:

```sh
php cua start --daemon --workers=4   # four workers, whatever server.workers says
php cua reload                       # still four
php cua reload --workers=8           # eight from now on, with no gap
```

A new `--address` is bound before the new process starts, and the old one lets the old address go when it stops.

### Services that cannot be made

Before a server starts, and before a reload hands over, the framework follows what each piece of unattended code needs from the container, without making any of it:

- each route's action, and the middleware given by name;
- every listener;
- every scheduled task, job and command.

An interface nothing binds, a class that no longer exists, or a constructor parameter that cannot be autowired stops the start or the reload. Every problem is listed at once, each with where it is named:

```text
The server was not reloaded, and goes on serving as it was: 2 services cannot be made:
  Route POST /roms: App\Actions\ImportRom needs App\RomScanner, which has no binding and is not a class that can be constructed.
  A listener for App\Events\RomImported: App\Listeners\IndexRom needs App\Search\Index, which is bound to App\Search\Engine, which takes $url, which cannot be autowired; bind App\Search\Engine with a closure.
```

`php cua describe app` lists the same problems, so a CI step can find them before a deploy. Nothing is constructed, so no constructor runs and nothing opens a connection. A closure binding is taken on trust, since only running it would tell. A job pushed from code is not named anywhere until it is pushed, so it is not checked. Commands typed at the console are not checked either: they fail in front of whoever typed them.

### When the new code does not load

`php cua` loads the application before it runs a command. When the new code cannot be loaded, because of a syntax error or a provider that throws, `status` and `stop` still run, from the files the server keeps in `storage/`:

```sh
php cua status
# The application cannot be loaded, so this is from the files in storage/ alone: ParseError: ...
# In the background on http://127.0.0.1:8080, pid 4120, up 2h 5m
```

Every other command, `reload` among them, says why the application did not load and stops. The server in the background is not touched: it goes on serving the code it started with until you fix the files and `reload`, or `stop` it.

Without the application, `status` and `stop` cannot read `server.pid`, so they look in `storage/crab.pid`. When the setting puts the file elsewhere, give the path with `--pid`:

```sh
php cua stop --pid=/srv/api/shared/crab.pid
```

Both commands check that the process named in the file is the server it recorded, started at the time it recorded. A stale file whose number now belongs to another process is refused, not signalled.

> [!NOTE]
> An application whose `cua` predates this still loads the application first, and `status` fails with it. Replace the last line of `cua` with `exit(Crab\Cua::run(__DIR__ . '/app.php', array_slice($argv, 1)));`.

> [!NOTE]
> Sending the server SIGUSR1 replaces its workers one at a time with the code it already has. It does not load new code; `reload` does.

## Finishing requests on stop

Set `server.drain` when a report request needs more than the default five seconds to finish during a deploy:

```php
// config/server.php
'drain' => 30.0,
```

After an HTTP worker stops accepting connections, it has this many seconds to finish its in-flight requests. Stop, reload, restart and a watched restart use the same limit. The setting accepts a finite number of seconds greater than zero; an invalid value stops `start` at startup.

At the limit, the worker cuts its remaining connections and logs one warning with its process id and the number of connections still reading, handling or answering a request. Idle keep-alive connections do not add to that count. If the logger throws, the same warning goes to standard error and the logger's failure is reported separately; worker providers still stop.

The HTTP supervisor allows `server.drain` plus one second for cleanup, regardless of another worker group's stop allowance. The background keeper and watcher allow additional time for the generation to exit. On reload, the old generation keeps its old allowance and the new generation uses the new setting. The keeper continues enforcing retiring generations' deadlines during startup and failed-start cleanup. A stop during startup includes the starting generation in the same shutdown pass. Request and response timeouts still apply while draining.

Give your container or process manager enough termination time for the drain and process cleanup. Blocking PHP code holds the worker's event loop. If the worker cannot stop, its supervisor ends it and reports the group, process id and seconds allowed once. A worker blocked in PHP cannot count its connections, so that supervisor notice is the only record in that case.

## How many workers

Start with one worker per CPU core; environment names such as `SERVER_WORKERS`, `APP_DEBUG` and `LOG_LEVEL` work only when your configuration files read them:

```text
SERVER_WORKERS=4
```

A worker serves many requests at once as long as they wait through Crab, as calls made with the [HTTP client](http-client.md) do. A database query or heavy computation holds its worker until it is done. So:

- An API that mostly calls other services needs few workers.
- An API that mostly queries the database needs more: roughly as many as the queries you want running at once, and no more than the database allows connections. Each worker opens up to its database connection's `pool`.

Workers share nothing. Anything two requests must both see, a cache entry, a rate limit, a session, belongs in the database or the cache, never in a worker's memory.

## How many connections

Every open connection is a file descriptor, and so is every open file, database or Redis connection and log. Each worker is its own process, so these limits apply per worker; four workers hold four times as many connections.

On the default `select` loop a worker holds at most 900 connections (`server.max_connections`), whatever the system allows. PHP's `stream_select()` cannot watch a descriptor numbered 1024 or higher (FD_SETSIZE is compiled into PHP), and the rest of the 1024 is left for files, the database and Redis. Raising the system's descriptor limit does not change this; more workers do.

For more connections per worker, install `ev`, `event` or `uv`, set `server.loop` to it, raise `server.max_connections`, and raise the per-process descriptor limit too, which is often 1024 by default. Allow `server.max_connections` plus a hundred or so for everything else:

```text
# systemd, in [Service]
LimitNOFILE=65536

# a shell that starts the server
ulimit -n 65536

# Docker
docker run --ulimit nofile=65536:65536 ...
```

Each connection also costs memory for its buffers; see the [WebSockets](websockets.md#configuration) limits when sockets stay open.

## Behind a proxy

The server speaks HTTP, not HTTPS. In production, put a proxy that terminates TLS in front of it: Nginx, Caddy, or your platform's load balancer.

```text
server {
    listen 443 ssl;
    server_name api.example.com;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $remote_addr;
        client_max_body_size 50m;
    }
}
```

Keep the server on `127.0.0.1` so that only the proxy reaches it. Match the proxy's body limit to `server.max_body`.

Tell the application which proxy you trust, so `ip()` and address-based rate limits see the client:

```php
// app.php
use Crab\Http\TrustedProxies;

->withMiddleware(prepend: [new TrustedProxies(['127.0.0.1'])])
```

Name only the addresses of proxies you control; use your proxy's address if it is on another machine.

## The other processes

On one machine, run HTTP, queue and scheduler together with `start`. No cron or separate queue/schedule process manager is needed.

The skeleton switches each on through `server.with`, reading its value from `.env`:

```php
// config/server.php
'with' => [
    'queue' => env('SERVER_QUEUE', false),
    'schedule' => env('SERVER_SCHEDULE', false),
],
```

```text
# .env, in production
APP_DEBUG=false
APP_URL=https://api.example.com
LOG_LEVEL=info
SERVER_QUEUE=true
SERVER_SCHEDULE=true
```

```text
$ php cua start
Starting Shop server on http://127.0.0.1:8080

  Server    Workers  Runs
  --------  -------  -------------------------------
  http      2        http://127.0.0.1:8080
  queue     3        queue:work: mail x1, default x2
  schedule  1        schedule:work

Press Ctrl+C to stop
```

Each server running under `start` has an id: `http`, `queue`, `schedule`. They are started, stopped and reloaded together: `reload` after a deploy gives every one of them the new code, each given its shutdown period to finish the work in hand before remaining workers are ended. A server registered with `reloads: false` is the exception: `reload` leaves it running, and only `restart` and `stop` end it; see [Keeping a server across reloads](providers.md#keeping-a-server-across-reloads). Each has processes of its own, so a slow job never holds up a request. When something runs beside HTTP, `start` lists the servers running; one switched off is left out, and `php cua describe` lists every server registered with whether it is on. With HTTP alone, `start` prints no server table.

| On | Switch on | Why |
| --- | --- | --- |
| Your own machine | nothing, most days | HTTP alone starts fastest and runs no job behind your back |
| One server | `queue` and `schedule` | One command, one reload |
| Several web servers | `queue`, and `schedule` on one of them | The schedule must run in one place, or mark its tasks `onOneServer()` |
| Web and workers apart | nothing on the web servers | The workers' machines run `queue:work` and `schedule:work` |

**Separate processes** suit workers on other machines, or development where you start work only as needed:

| Process | Command | How many |
| --- | --- | --- |
| The HTTP server | `php cua start --daemon` | One per machine |
| Queue workers | `php cua queue:work` | One command per machine, or one per supervisor |
| The scheduler | `php cua schedule:work` | Exactly one, or mark tasks `onOneServer()` |

In this arrangement, leave those `server.with` switches off on the HTTP server. Keep the separate workers alive with systemd, Supervisor or containers and restart them after deployment; `reload` only covers workers managed by `start`. Do not start a second scheduler alongside the integrated one.

`--single` serves HTTP alone whatever is switched on, as one process has room for nothing else. To add a server of your own to the list, a WebSocket server, a command of yours, or a program such as `npm run dev`, see [Service providers](providers.md#a-server-of-your-own-beside-http).

To see all of it on one page, requests, the queue, scheduled tasks, workers and exceptions, install the `crabphp/clockwerk` package, a dashboard served by the application itself at `/clockwerk`.

See [Queues](queues.md#in-production) and [Task scheduling](scheduling.md#running-the-scheduler).

## A deploy, start to finish

For one machine running HTTP, queue and scheduler together, set these production values in `.env`. Choose `SERVER_WORKERS` for the machine; four is an example. Leave the queue or schedule switch off when the application does not use it.

```text
APP_DEBUG=false
APP_URL=https://api.example.com
LOG_LEVEL=info
SERVER_WORKERS=4
SERVER_QUEUE=true
SERVER_SCHEDULE=true
```

Set `APP_URL` to the public application URL: [named links](routing.md#named-routes) use it in actions, jobs and commands alike.

When using the database queue, generate its migration once with `php cua queue:install` and ship it with the application. Then run this deploy script from the application directory:

```sh
set -e
git pull                                 # or unpack the new release
composer install --no-dev --optimize-autoloader
php cua migrate
php cua reload
```

Installation and migration must succeed before reload; `set -e` stops the script at a failed step. For the first deployment, use `php cua start --daemon` in place of `php cua reload`. The one command manages HTTP, queue and scheduler, so there are no separate services to restart. If you deliberately run workers separately, restart those services only after the preceding steps succeed.

Run the migrations before the reload, and write them so that the old code still works on the new tables for the moment between the two: add a column in one deploy, start using it in the next.

## Before going live

- `APP_DEBUG=false`. Debug mode shows traces to clients.
- `APP_URL` set to the externally reachable HTTPS URL, so [named links](routing.md#named-routes) point to production.
- `LOG_LEVEL=info` or `warning`, and a `log.path` you collect or rotate.
- Choose `SERVER_WORKERS` explicitly; the skeleton starts with one for development.
- Set `SERVER_QUEUE=true` and `SERVER_SCHEDULE=true` for the work this machine must run, and apply the database queue migration before starting its workers. Run the scheduler in one place, or use `onOneServer()`.
- The `pcntl` and `posix` extensions, for workers and the background server.
- Connections per worker within the loop's reach: at most 900 on `select`; beyond that a native `server.loop` and a raised descriptor limit, as in [How many connections](#how-many-connections).
- OPcache on, with `opcache.enable_cli=1`: the server runs under PHP's command line.
- The JIT on, with `opcache.jit=tracing` and a `opcache.jit_buffer_size` such as `64M`: about 12% more requests on a small JSON response. PHP 8.4 ships with `opcache.jit=disable`, which cannot be changed once PHP has started, so set it in `php.ini` or with `php -d opcache.jit=tracing cua start`.
- A proxy with TLS in front, and the server on `127.0.0.1`.
- `TrustedProxies` in `app.php`, naming only the proxy addresses you control, so clients have their own address-based limits.
- `storage/` writable by the user the server runs as.
- `php cua status --json` and `GET /up` in your monitoring.

## How a background server holds together

```text
keeper            holds the listening socket; its process id is the server's and never changes
├─ server         the application, loaded once; a reload starts a new one beside it and retires this one
│  ├─ worker      serves requests
│  └─ worker
└─ held server    only with servers kept across reloads; a reload leaves it, a restart replaces it
   └─ worker
```

- **The keeper** binds the address once and passes the socket to each server process it starts. It serves nothing and loads none of your code, so it never needs replacing. Restart it only after upgrading the framework itself.
- **A server process that dies is started again** by the keeper, on the socket that never closed. Six deaths within a minute and the keeper gives up and stops. The held server is looked after the same way, and `status` lists it as `kept across reloads`.
- **The new process starts in the directory `reload` was run from.** Deploying into a new release folder and reloading from there works, as long as `storage/` is shared between the folders, since the process id file is how `reload` finds the server.
