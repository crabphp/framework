# Changelog

Crab follows [semantic versioning](https://semver.org). Before 1.0, a minor version may break what an application writes; the entry says what to change.

## Unreleased

## 0.1.0 — 2026-10-24

The first public release: a long-running JSON API framework with its own engine.

- **Server:** `dev` serves and restarts when a file is saved; `start` serves for production, in the foreground or with `--daemon` in the background, where `reload` replaces it without refusing a connection, keeping the options it was started with. `status` shows every running server, its workers and what each has handled.
- **Settings** are checked when the application starts, against rules declared beside their defaults; every problem is said at once, and `describe app` lists them.
- **Routing, actions and validation** from typed parameters, PSR-7 and PSR-15 middleware, errors as JSON, logging, authentication, cache, events, queues, scheduling, an HTTP client, database queries and migrations, WebSockets and broadcasting.
- **`describe`** prints the whole application for coding agents.
