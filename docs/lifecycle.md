# Request lifecycle

- [Introduction](#introduction)
- [The four phases](#the-four-phases)
- [Where state lives](#where-state-lives)
- [What this changes in your code](#what-this-changes-in-your-code)
    - [Services hold no request state](#services-hold-no-request-state)
    - [There is no global request or user](#there-is-no-global-request-or-user)
    - [Blocking calls hold the worker](#blocking-calls-hold-the-worker)
- [Following one request](#following-one-request)

## Introduction

Under PHP-FPM every request starts from nothing: the framework boots, answers and is thrown away. Under Crab the application is loaded once and then serves requests for hours. That is where the speed comes from, and it changes a few habits. This page is the one to read before writing your first service, because the mistakes it prevents are hard to find later.

## The four phases

| Phase | Runs | What happens |
| --- | --- | --- |
| 1. Define | Once, in the parent process | `.env` and `config/` are loaded, providers are registered, routes, middleware and commands are declared |
| 2. Start | In each worker, as it starts | The middleware is made, and each provider that asked is started |
| 3. Request | In each worker, many times, overlapping | Request, middleware, router, action, response |
| 4. Stop | In each worker, as it stops | Requests have up to `server.drain` seconds to finish, then remaining connections close and providers stop |

Phase 1 runs when you type `php cua start`. The server then forks its workers, and each worker runs phase 2. Invalid routes, middleware construction and provider start hooks can fail startup; an action service or a setting it needs can still fail on its first request.

## Where state lives

Everything your application remembers belongs to one of three scopes:

| Scope | Lives | Examples | Rule |
| --- | --- | --- | --- |
| Process | The whole run, copied into every worker | Configuration, the routes, the provider list | Fixed after phase 1 |
| Worker | One worker's life | Services from the container, database connections, the logger | Shared by every request the worker serves |
| Request | One request | The request, its route parameters, the logged-in user | Travels on the request object, nowhere else |

A worker serves requests that **overlap**. While one waits for another API to answer, the same worker starts on the next. Two requests are then inside the same service object at the same moment.

## What this changes in your code

### Services hold no request state

The container keeps autowired classes and singleton bindings per worker; `bind()` makes a new value on each `get()`. A route keeps its resolved action callable for the worker’s life, including an action obtained from a transient binding; it does not ask the container again for each request. A property in a kept service that belongs to one request will be read by another.

```php
use App\User;
use Crab\Contracts\Database\Database;

// Wrong: the second request overwrites the first one's user.
final class StatefulInvoices
{
    private User $user;

    public function for(User $user): static
    {
        $this->user = $user;

        return $this;
    }
}

// Right: what belongs to one request is an argument.
final readonly class Invoices
{
    public function __construct(private Database $db)
    {
    }

    public function listFor(User $user): array
    {
        return $this->db->table('invoices')->where('user_id', $user->id)->get();
    }
}
```

Declaring services `final readonly class` prevents property reassignment; keep request state out of mutable objects those properties hold too. For the same reason, do not keep request data in a `static` property.

### There is no global request or user

There is no `request()` helper and no `Auth::user()`. An action receives what it needs as parameters, and passes it on as arguments:

```php
Route::get('/invoices', static fn (User $user): array => $invoices->listFor($user));
```

The only global helpers are `config()` and `env()`, because configuration is the same for every request.

### Blocking calls hold the worker

A worker handles many requests by switching between them whenever one waits. It can only switch when the waiting is done through Crab:

| Call | While it waits |
| --- | --- |
| The [HTTP client](http-client.md) | The worker serves other requests |
| A database query (PDO) | The worker waits with it |
| `file_get_contents()` on a URL, cURL, `sleep()` | The worker waits with it |

So call other APIs with the HTTP client, never with cURL. Database queries are short on a healthy database; run more workers (`server.workers`) for more queries at once. Work that takes seconds belongs in a [queue](queues.md), whose workers are separate processes.

## Following one request

1. A worker accepts the connection and reads the request.
2. It passes through the middleware around every request, outermost first. The default error handler catches failures beneath it, except cancellation; prepended middleware runs outside it.
3. The router finds the route. No route is a `404` or `405`, which travels back out through that middleware like any other response.
4. The request passes through the route's groups' middleware and its own.
5. The framework reads what the action's signature asks for, validates the request's data against it, and answers `422` itself when it does not fit.
6. The action runs. What it returns becomes the response: data as JSON, or a response object as it is.
7. The response travels back out through the same middleware, in reverse.

The action's signature was read in phase 1, so step 5 costs no reflection per request.
