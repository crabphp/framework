# Sessions

- [Introduction](#introduction)
- [Configuration](#configuration)
    - [Choosing a driver](#choosing-a-driver)
- [Using the session](#using-the-session)
    - [Reading and writing](#reading-and-writing)
    - [Flash messages](#flash-messages)
    - [Logging in and out](#logging-in-and-out)
- [How sessions work in a long-running worker](#how-sessions-work-in-a-long-running-worker)
- [Removing expired sessions](#removing-expired-sessions)
- [Testing](#testing)

## Introduction

A session keeps a little data for one browser between its requests: the step a visitor has reached in a form of several pages, a "Profile saved." message to show after a redirect, the page to return to after logging in. A cookie names the session, and a driver keeps what it holds.

Reach for a session for temporary data about a visit. Data that matters to the business, a cart, an order, a checkout, belongs in tables of its own, where it can be found again from another device and read by a job. Who is logged in is not kept in the session either: that is [cookie authentication](authentication.md#cookie-authentication), with tokens that can be revoked.

## Configuration

Register the plugin in `app.php`, and put `StartSession` around the routes that use a session:

```php
// app.php
use Crab\Session\SessionPlugin;

->withPlugins([
    SessionPlugin::class,
])
```

```php
// routes/web.php
use Crab\Route;
use Crab\Session\StartSession;

Route::middleware(StartSession::class)->group(static function (): void {
    Route::get('/profile', ShowProfile::class);
    Route::post('/profile', SaveProfile::class);
});
```

`StartSession` can also go around every request, with `withMiddleware()` in `app.php`. A request that never uses its session costs no reading and no writing, so it does no harm on routes that do not need one.

| Setting | Default | |
| --- | --- | --- |
| `session.driver` | `file` | Where sessions are kept: `file`, `cookie` or `memory`, or `redis` with crabphp/redis |
| `session.lifetime` | `7200` | Seconds a session lasts without being used |
| `session.directory` | `storage/sessions` | The file driver's folder |
| `session.cookie` | `__Host-session` | The cookie's name; `session` when the cookie is not secure |
| `session.secure` | `true` | Whether the cookie is sent only over HTTPS |
| `session.lottery` | `[2, 100]` | The chances that writing a session sweeps expired ones |

The cookie is `HttpOnly` and `SameSite=Lax`. A session lasts while its browser keeps using it: once it is past half its lifetime, the next request that uses it starts the lifetime again.

### Choosing a driver

| Driver | Keeps sessions | Use it when |
| --- | --- | --- |
| `file` | In files under `session.directory`, on this machine | One machine; the default |
| `redis` | In Redis, shared by every worker on every machine | More than one machine. Install crabphp/redis and list its `RedisPlugin` after `SessionPlugin` |
| `cookie` | In the cookie itself, signed | Nothing on the server at all, for small data that is not secret |
| `memory` | In one worker's memory | Tests, and development with one worker |

The `file`, `redis` and `memory` drivers keep what a session holds on the server, and the cookie carries only a random id. The `cookie` driver puts the whole session in the cookie, signed with HMAC-SHA256 under the [application key](configuration.md#the-application-key), `app.key`, so that the browser cannot change it. Write one before using it:

```sh
php cua key:generate
```

> [!WARNING]
> The cookie driver signs its cookie but does not encrypt it: the visitor can read everything the session holds. Keep nothing secret in it. A cookie carries about 3,800 bytes; a session that holds more is refused with an error. And since nothing is kept on the server, a copy of the cookie keeps working until it expires: invalidating the session only clears the cookie in the browser that asked. Replacing `app.key` ends every cookie session at once.

The `memory` driver keeps sessions in the worker that wrote them, and a browser's next request may reach another worker, so the server refuses to start with it when more than one worker serves. Every session is lost when the worker restarts.

The file driver uses the cache's file store in a folder of its own, so `php cua cache:clear` never ends a session. To keep sessions somewhere else, bind `Crab\Contracts\Session\Driver` to a driver of your own after `SessionPlugin`.

## Using the session

### Reading and writing

An action takes the session as a parameter typed `Crab\Contracts\Session\Session`, the way it takes the user:

```php
// app/Actions/ShowCheckoutStep.php
use Crab\Contracts\Session\Session;

final readonly class ShowCheckoutStep
{
    public function handle(Session $session): array
    {
        return ['step' => $session->get('checkout.step', 1)];
    }
}
```

| Method | |
| --- | --- |
| `get($key, $default)` | The value, or the default when there is none |
| `has($key)` | Whether there is a value |
| `put($key, $value)` | Keeps a value until it is forgotten or the session ends |
| `forget($key)` | Removes a value |
| `pull($key, $default)` | The value, which is then forgotten |
| `all()` | Every value, by key |

A session keeps JSON values: `null`, booleans, numbers, strings, and lists and maps of them. An object is refused with an error when it is put: keep its id, or an array of its fields. What is put reads back exactly as it was, `1.0` as a float and `"7"` as a string.

An action that takes the session where no `StartSession` runs around its route fails with an error that says to add it. There is no other way to reach the session: no `$_SESSION`, no `session()` helper. A worker serves many requests at once, so the session travels with its request and nowhere else.

### Flash messages

`flash()` keeps a value for the next request that uses the session, and no longer. It is the message shown after a form posts and redirects:

```php
// app/Actions/SaveProfile.php
use Crab\Contracts\Session\Session;
use Crab\Http\Response;

final readonly class SaveProfile
{
    public function handle(ProfileInput $input, Session $session): Response
    {
        // ... save the profile ...
        $session->flash('status', 'Profile saved.');

        return Response::redirect('/profile', 303);
    }
}
```

The page the redirect lands on reads it with `get('status')`, and the request after that finds nothing. Putting the key again with `put()` keeps it like any other value.

### Logging in and out

Call `regenerate()` when a user logs in: the session keeps what it holds under a new id, so an id someone else knew stops working. Call `invalidate()` when they log out: it forgets everything and clears the cookie.

```php
$session->regenerate();   // after logging in
$session->invalidate();   // when logging out
```

An id the server did not give out is never adopted: a browser that sends one it made up gets a new session under a new id.

## How sessions work in a long-running worker

A Crab worker serves many requests at the same time, so nothing about a session is kept in the worker between requests. On each request, `StartSession` makes a session object for that request alone and puts it on the request, where the action receives it. The driver is read the first time the action uses the session, and written after the action only when something changed, a flash was seen, the id must change, or the lifetime should start again.

Two requests from one browser at the same time, two tabs, each change their own copy. When each is written, its changes are laid over what the driver holds by then, so one tab's `put('a', …)` does not undo another's `put('b', …)`. Where both change the same key, the request that finishes last wins. The cookie driver cannot do this, since each response carries the whole session: there, the last response wins outright.

## Removing expired sessions

The `cookie` and `redis` drivers need no cleaning: a cookie expires in the browser, and Redis drops a key when it expires. The `file` and `memory` drivers keep an expired session until it is swept. An expired session is never read, so sweeping only frees space.

Sweeping happens by lottery: after writing a session, `StartSession` sweeps on 2 requests in 100, with the default `session.lottery` of `[2, 100]`. No scheduled task is needed. A busy site sweeps more often; a quiet one rarely needs to. A sweep that fails is logged and does not fail the request.

To sweep at a time of your choosing instead, set `session.lottery` to `[0, 1]` and [schedule](scheduling.md) the command:

```sh
php cua session:prune
```

> [!NOTE]
> A sweep of the file driver reads its whole folder in the request that drew it, and the worker serves nothing else meanwhile. With very many sessions on one machine, lower the chances, use the command at a quiet hour, or keep sessions in Redis.

## Testing

Set `session.driver` to `memory` in your tests, and send back the cookie a response sets, as a browser would. [Testing](testing.md) shows how to send requests with headers.
