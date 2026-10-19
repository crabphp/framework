# Deployment

- [Introduction](#introduction)
- [Running the server](#running-the-server)
    - [While developing](#while-developing)
    - [In a container](#in-a-container)
    - [On a server you manage](#on-a-server-you-manage)
- [Deploying new code](#deploying-new-code)
- [Finishing requests on stop](#finishing-requests-on-stop)
- [How many workers](#how-many-workers)
- [Behind a proxy](#behind-a-proxy)
- [The other processes](#the-other-processes)
- [A deploy, start to finish](#a-deploy-start-to-finish)
- [Before going live](#before-going-live)
- [How a background server holds together](#how-a-background-server-holds-together)

## Introduction

A Crab application is its own server. There is no PHP-FPM and no web server to configure in front of it for PHP's sake: `php cua serve` listens on a port and answers. What you deploy is one command to keep running, and a way to give it new code.

This page covers the three ways to run it, how to replace code while requests are in flight, and what else must be running.

## Running the server

```sh
php cua serve                # in the foreground, restarting when a file changes
php cua serve --no-watch     # in the foreground, as it is
php cua serve --daemon       # in the background, returning once it is up
```

| Option | |
| --- | --- |
| `--address=tcp://0.0.0.0:8080` | Where to listen; the default is `server.address` |
| `--workers=4` | Worker processes; the default is `server.workers` |
| `--single` | Serve in this one process, without the `pcntl` and `posix` extensions |
| `--watch=app,config` | What to watch, in place of `server.watch` |
| `--no-watch` | Do not restart when a file changes |
| `--daemon`, `-d` | Serve in the background |

### While developing

Plain `serve` is for your own machine. It starts the server again whenever a PHP file under `app`, `config` or `routes`, or `app.php` or `.env`, is added, changed or removed. When the code does not load, the error stays on the screen, and saving a fix starts the server again.

Set `server.watch` to other paths, or to `false` to watch nothing.

Foreground `serve` writes no background PID file; stop it through its terminal job or the process manager that started it.

### In a container

Run the server in the foreground and let the platform keep it alive:

```text
CMD ["php", "cua", "serve", "--no-watch", "--address=tcp://0.0.0.0:8080"]
```

A container is replaced, not reloaded: a deploy starts new containers and stops the old ones, and the platform moves traffic between them. On SIGTERM the server gives its requests up to `server.drain` seconds to finish, then cuts remaining connections and stops.

Point the platform's health check at the [health path](configuration.md#a-health-check), `GET /up`, after asking for it with `withRouting(health: '/up')` in `app.php`.

### On a server you manage

Choose the production settings and prepare the database in [A deploy, start to finish](#a-deploy-start-to-finish), then run it in the background:

```sh
php cua serve --daemon       # start
php cua status               # is it running? exit status 0 when it is
php cua reload               # new code, without refusing a connection
php cua restart              # stop, then start: a short gap
php cua stop                 # allow server.drain seconds for requests, then stop
```

`serve --daemon` returns when the server accepts connections, and fails with the reason when it cannot start, so a deploy script can trust its exit status.

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
ExecStart=/usr/bin/php cua serve --daemon
ExecReload=/usr/bin/php cua reload
ExecStop=/usr/bin/php cua stop
Restart=on-failure
```

## Deploying new code

A running server holds the code it started with. `reload` is how new code goes live:

```sh
php cua reload
```

The server starts a new process, with the code and configuration as they are now, on the socket it already listens on. When every worker of the new process accepts connections, the old process gives its requests up to its `server.drain` limit to finish and stops.

- **Connections keep being accepted.** The old and the new process accept from the same socket. Requests already in flight finish within the old workers' `server.drain` limit; connections still open at that limit are cut.
- **A reload that fails at startup leaves the old process serving.** Syntax errors, middleware construction and provider start hooks can fail here; lazily made action services can still fail after takeover.

The console loads the application before running a command. If broken files prevent that bootstrap, `reload`, `status`, `stop` and help cannot contact the existing server. Restore the last working application files and retry; with release directories, run the command from the previous working release using the same shared `storage/`. A bootstrap error does not mean the running server stopped.

> [!NOTE]
> Sending the server SIGUSR1 replaces its workers one at a time with the code it already has. It does not load new code; `reload` does.

## Finishing requests on stop

Set `server.drain` when a report request needs more than the default five seconds to finish during a deploy:

```php
// config/server.php
'drain' => 30.0,
```

After an HTTP worker stops accepting connections, it has this many seconds to finish its in-flight requests. Stop, reload, restart and a watched restart use the same limit. The setting accepts a finite number of seconds greater than zero; an invalid value stops `serve` at startup.

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

On one machine, run HTTP, queue and scheduler together with `serve`. No cron or separate queue/schedule process manager is needed.

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
$ php cua serve
Starting Shop server on http://127.0.0.1:8080

  Server    Workers  Runs
  --------  -------  -------------------------------
  http      2        http://127.0.0.1:8080
  queue     3        queue:work: mail x1, default x2
  schedule  1        schedule:work

Press Ctrl+C to stop
```

Each server running under `serve` has an id: `http`, `queue`, `schedule`. They are started, stopped and reloaded together: `reload` after a deploy gives every one of them the new code, each given its shutdown period to finish the work in hand before remaining workers are ended. Each has processes of its own, so a slow job never holds up a request. When something runs beside HTTP, registered servers that are switched off are listed as `off`. With HTTP alone, `serve` prints no server table.

| On | Switch on | Why |
| --- | --- | --- |
| Your own machine | nothing, most days | HTTP alone starts fastest and runs no job behind your back |
| One server | `queue` and `schedule` | One command, one reload |
| Several web servers | `queue`, and `schedule` on one of them | The schedule must run in one place, or mark its tasks `onOneServer()` |
| Web and workers apart | nothing on the web servers | The workers' machines run `queue:work` and `schedule:work` |

**Separate processes** suit workers on other machines, or development where you start work only as needed:

| Process | Command | How many |
| --- | --- | --- |
| The HTTP server | `php cua serve --daemon` | One per machine |
| Queue workers | `php cua queue:work` | One command per machine, or one per supervisor |
| The scheduler | `php cua schedule:work` | Exactly one, or mark tasks `onOneServer()` |

In this arrangement, leave those `server.with` switches off on the HTTP server. Keep the separate workers alive with systemd, Supervisor or containers and restart them after deployment; `reload` only covers workers managed by `serve`. Do not start a second scheduler alongside the integrated one.

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

Installation and migration must succeed before reload; `set -e` stops the script at a failed step. For the first deployment, use `php cua serve --daemon` in place of `php cua reload`. The one command manages HTTP, queue and scheduler, so there are no separate services to restart. If you deliberately run workers separately, restart those services only after the preceding steps succeed.

Run the migrations before the reload, and write them so that the old code still works on the new tables for the moment between the two: add a column in one deploy, start using it in the next.

## Before going live

- `APP_DEBUG=false`. Debug mode shows traces to clients.
- `APP_URL` set to the externally reachable HTTPS URL, so [named links](routing.md#named-routes) point to production.
- `LOG_LEVEL=info` or `warning`, and a `log.path` you collect or rotate.
- Choose `SERVER_WORKERS` explicitly; the skeleton starts with one for development.
- Set `SERVER_QUEUE=true` and `SERVER_SCHEDULE=true` for the work this machine must run, and apply the database queue migration before starting its workers. Run the scheduler in one place, or use `onOneServer()`.
- The `pcntl` and `posix` extensions, for workers and the background server.
- OPcache on, with `opcache.enable_cli=1`: the server runs under PHP's command line.
- A proxy with TLS in front, and the server on `127.0.0.1`.
- `TrustedProxies` in `app.php`, naming only the proxy addresses you control, so clients have their own address-based limits.
- `storage/` writable by the user the server runs as.
- `php cua status` and `GET /up` in your monitoring.

## How a background server holds together

```text
keeper            holds the listening socket; its process id is the server's and never changes
└─ server         the application, loaded once; a reload starts a new one beside it and retires this one
   ├─ worker      serves requests
   └─ worker
```

- **The keeper** binds the address once and passes the socket to each server process it starts. It serves nothing and loads none of your code, so it never needs replacing. Restart it only after upgrading the framework itself.
- **A server process that dies is started again** by the keeper, on the socket that never closed. Six deaths within a minute and the keeper gives up and stops.
- **The new process starts in the directory `reload` was run from.** Deploying into a new release folder and reloading from there works, as long as `storage/` is shared between the folders, since the process id file is how `reload` finds the server.
