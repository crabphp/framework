---
name: sync-docs
description: Bring the Crab framework's guide in docs/ in step with the code, and write or rewrite its pages in the house style (modelled on the Laravel documentation). Use after adding or changing a component, command, setting, contract or anything an application developer writes or runs; when asked to "sync the docs", "update the docs", "document X" or "write a docs page"; and to preview the guide in a browser.
---

# Sync docs

`docs/` is the guide for people building an application on Crab: one Markdown page per topic, listed in `docs/documentation.md`, read in a browser through `docs/index.html`. The code is the source of truth. This skill keeps the pages true to it.

Run everything from the framework's root, the folder with `composer.json`.

## Workflow

1. **Find what moved.** Run the check; it reports what the pages lack or get wrong:

   ```sh
   php .claude/skills/sync-docs/scripts/check.php
   ```

   For a change still in the working tree, also read `git status --short src` and `git diff src`, and map each changed folder to its page with the table below.

2. **Read the source, not the old page.** For each page to touch, read what an application developer meets:
   - the contract in `src/Contracts/<Component>/` (method names, parameters, docblocks),
   - the provider (`*Provider.php`): its `defaults()` are the settings and their defaults, its `console->add()` the commands,
   - each command's `description()`, which lists its arguments and options,
   - the component's tests in `tests/<Component>/`, which show real usage and the edge cases worth a sentence,
   - the rules for it in `AGENTS.md`: what it deliberately does not do belongs on the page too.

3. **Write the page** in the form given in [references/style.md](references/style.md). Read that file before writing or restructuring any page. Edit a page in place when only a fact changed; do not rewrite prose that is still true.

4. **Register a new page** in `docs/documentation.md`, under the section it belongs to, and link it from the related pages.

5. **Check again** until the check prints `The docs name what the code has`. It checks links, class names, commands and settings, not that an example behaves as the page says; read the examples against the code for that. Fix the page, not the check; change the check only when a rule in it is wrong.

6. **Preview** when the layout or a new page should be seen:

   ```sh
   composer docs                 # serves docs/ at http://127.0.0.1:8900 and opens it in the browser
   composer docs -- --no-open    # serve only; use this when checking the pages yourself
   ```

   It is `scripts/serve.php`. Run it in the background and tell the user the address. The page is `docs/index.html`; it draws the menu from `documentation.md` and needs no build.

7. **Keep the short forms in step.** The README has a short section per component that links to its page: update it when a registration line, a setting or a command changed.

## Which page holds what

| Source | Page |
| --- | --- |
| Skeleton (`../crab`), `bin/crab` | `installation.md`, `structure.md` |
| `src/Config/`, `src/AppBuilder.php` | `configuration.md` |
| `ARCHITECTURE.md` (lifecycle, scopes) | `lifecycle.md` |
| `src/Routing/`, `src/Route.php` | `routing.md`, `actions.md` |
| `src/Validation/` | `validation.md` |
| `src/Http/Request.php`, `Multipart`, `UploadedFile` | `requests.md` |
| `src/Http/Response.php`, `Cookie.php` | `responses.md` |
| `src/Http/` middleware, `Cors`, `RequestId`, `MiddlewareStack` | `middleware.md` |
| `src/Http/ErrorHandler.php`, `ErrorResponse.php`, `HttpException.php` | `errors.md` |
| `src/Log/` | `logging.md` |
| `src/Container/`, `src/Contracts/Provider.php`, `WorkerProvider.php`, `Runner.php` | `providers.md` |
| `src/Console/`, `src/Commands/`, `src/Command.php` | `console.md` |
| `src/Auth/` | `authentication.md` |
| `src/Database/` pool and query layer | `database.md` |
| `src/Database/Query.php`, `Grammar` | `queries.md` |
| `src/Database/Schema*`, `Migrator`, `Table`, `Column` | `migrations.md` |
| `src/Cache/` | `cache.md` |
| `src/Storage/`, `../s3` | `storage.md` |
| `src/Events/` | `events.md` |
| `src/Queue/` | `queues.md` |
| `src/Schedule/` | `scheduling.md` |
| `src/HttpClient/` | `http-client.md` |
| `src/Testing/`, `FakeClient`, `MemoryStore`, `ArrayStore` | `testing.md` |
| `src/Server.php`, `src/Commands/Start.php`, `Dev.php` and the rest | `deployment.md` |

A component with no row gets a page of its own and a row here.

## What the check verifies

- Every page is listed in `documentation.md`, and every listed page exists.
- Every link between pages, and every `#anchor`, points at something.
- Every `Crab\...` name on a page is a class, interface or enum that exists.
- Every console command a provider or `App` registers is documented, and every `php cua <command>` on a page is a real command or one the page itself declares.
- Every setting a provider declares with `defaults()`, and every key `App`, `Server` and `Serve` read, appears somewhere as `section.key`.

It cannot tell whether an explanation is right. Reading the source in step 2 is what does that.
