# Deployment

- [Introduction](#introduction)
- [Running the server](#running-the-server)
    - [While developing](#while-developing)
    - [In a container](#in-a-container)
    - [On a server you manage](#on-a-server-you-manage)
- [Deploying new code](#deploying-new-code)
- [How many workers](#how-many-workers)
- [Behind a proxy](#behind-a-proxy)
- [The other processes](#the-other-processes)
- [A deploy, start to finish](#a-deploy-start-to-finish)
- [Before going live](#before-going-live)
- [How a background server holds together](#how-a-background-server-holds-together)

## Introduction

A Crab application is its own server. There is no PHP-FPM and no web server to configure in front of it for PHP's sake: `php cua serve` listens on a port and answers. What you deploy is one command to keep running, and a way to give it new code.

This page covers the three ways to run it, how to deploy without dropping a request, and what else must be running.

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

Plain `serve` is for your own machine. It starts the server again whenever a PHP file under `app`, `config` or `routes`, or `.env`, is added, changed or removed. When the code does not load, the error stays on the screen, and saving a fix starts the server again.

Set `server.watch` to other paths, or to `false` to watch nothing.

### In a container

Run the server in the foreground and let the platform keep it alive:

```text
CMD ["php", "cua", "serve", "--no-watch", "--address=tcp://0.0.0.0:8080"]
```

A container is replaced, not reloaded: a deploy starts new containers and stops the old ones, and the platform moves traffic between them. On SIGTERM the server finishes the requests it has and stops.

Point the platform's health check at the [health path](configuration.md#a-health-check), `GET /up`.

### On a server you manage

Run it in the background:

```sh
php cua serve --daemon       # start
php cua status               # is it running? exit status 0 when it is
php cua reload               # new code, without refusing a connection
php cua restart              # stop, then start: a short gap
php cua stop                 # finish the requests in hand, then stop
```

`serve --daemon` returns when the server accepts connections, and fails with the reason when it cannot start, so a deploy script can trust its exit status.

`stop` waits up to `--timeout=15` seconds for requests to finish; `--force` ends a server that has not stopped by then.

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

The server starts a new process, with the code and configuration as they are now, on the socket it already listens on. When every worker of the new process accepts connections, the old process finishes the requests it has and stops.

- **No connection is refused or cut short.** The old and the new process accept from the same socket.
- **A reload that fails changes nothing.** If the new code cannot start, because of a syntax error or a service that cannot be made, the old process goes on serving, and `reload` exits with a failure and shows what went wrong.

> [!NOTE]
> Sending the server SIGUSR1 replaces its workers one at a time with the code it already has. It does not load new code; `reload` does.

## How many workers

Start with one worker per CPU core:

```text
SERVER_WORKERS=4
```

A worker serves many requests at once as long as they wait through Crab, as calls made with the [HTTP client](http-client.md) do. A database query or heavy computation holds its worker until it is done. So:

- An API that mostly calls other services needs few workers.
- An API that mostly queries the database needs more: roughly as many as the queries you want running at once, and no more than the database allows connections. Each worker opens up to `database.pool` connections.

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

## The other processes

An application that uses the queue or the scheduler has more to keep running:

| Process | Command | How many |
| --- | --- | --- |
| The HTTP server | `php cua serve --daemon` | One per machine |
| Queue workers | `php cua queue:work --workers=4` | One command per machine, or one per queue |
| The scheduler | `php cua schedule:work` | Exactly one, or mark tasks `onOneServer()` |

To see all of it on one page, requests, the queue, scheduled tasks, workers and exceptions, install the `crabphp/watch` package, a dashboard served by the application itself.

`queue:work` and `schedule:work` run in the foreground: keep them alive with systemd, Supervisor or a container each. Unlike the HTTP server they have no `reload`; restart them after a deploy. Each finishes what it has in hand before it stops. See [Queues](queues.md#in-production) and [Task scheduling](scheduling.md#running-the-scheduler).

## A deploy, start to finish

```sh
git pull                                 # or unpack the new release
composer install --no-dev --optimize-autoloader
php cua migrate
php cua reload
systemctl restart app-queue app-schedule
```

Run the migrations before the reload, and write them so that the old code still works on the new tables for the moment between the two: add a column in one deploy, start using it in the next.

## Before going live

- `APP_DEBUG=false`. Debug mode shows traces to clients.
- `LOG_LEVEL=info` or `warning`, and a `log.path` you collect or rotate.
- The `pcntl` and `posix` extensions, for workers and the background server.
- OPcache on, with `opcache.enable_cli=1`: the server runs under PHP's command line.
- A proxy with TLS in front, and the server on `127.0.0.1`.
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
