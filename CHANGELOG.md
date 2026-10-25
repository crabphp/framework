# Changelog

Crab follows [semantic versioning](https://semver.org). Before 1.0, a minor version may break what an application writes; the entry says what to change.

## Unreleased

- **File storage:** named disks behind `Contracts\Storage\Disk`, with `local` (the folder `storage/files`, by default) and `memory` for tests. A file is written whole or not at all, read as a stream, listed a page at a time, copied and moved; a path is checked before a disk is touched. `crabphp/s3` adds `s3`, for Amazon S3, Cloudflare R2, MinIO and the rest, on the HTTP client, with signed download and upload URLs. See [File storage](docs/storage.md).
- **Your own error format:** bind `Crab\Contracts\Http\ErrorRenderer` and it writes every error response, returned or thrown: the router's 404 and 405, validation's 422, middleware's 401, 429 and CORS refusals, `HttpException` and a failure's 500, keeping the headers middleware gave them. Replacing the error handler is no longer needed to change the format. `ErrorResponse::make()` takes `cause:` and `debug:` in place of `$detail`.
- **`text()` and `binary()` hold any length on MySQL,** as documented: they create `longtext` and `longblob`, where `text` and `blob` stopped at 64 KB. Tables already created keep their columns; change one with a migration of its own if it needs more.
- **The testing guide's database settings** now match the skeleton (`DB_DRIVER` and `DB_DATABASE`, not `DB_DSN`, which nothing read), and the skeleton's `phpunit.xml` sets them, so tests never reach the development database.

## 0.1.0 — 2026-10-24

The first public release: a long-running JSON API framework with its own engine.

- **Server:** `dev` serves and restarts when a file is saved; `start` serves for production, in the foreground or with `--daemon` in the background, where `reload` replaces it without refusing a connection, keeping the options it was started with. `status` shows every running server, its workers and what each has handled.
- **Settings** are checked when the application starts, against rules declared beside their defaults; every problem is said at once, and `describe app` lists them.
- **Routing, actions and validation** from typed parameters, PSR-7 and PSR-15 middleware, errors as JSON, logging, authentication, cache, events, queues, scheduling, an HTTP client, database queries and migrations, WebSockets and broadcasting.
- **`describe`** prints the whole application for coding agents.
