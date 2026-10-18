# Responses

- [Introduction](#introduction)
- [Returning data](#returning-data)
- [Response factories](#response-factories)
- [Headers](#headers)
- [Cookies](#cookies)
- [Redirects](#redirects)
- [Files](#files)
- [Errors](#errors)

## Introduction

An action returns data, and Crab sends it as JSON with `200`. Build a response yourself only when the status, a header or the body needs saying. There is one place where status and headers are set, your code, and no attribute or configuration that says them a second time.

## Returning data

```php
return ['id' => 7, 'name' => 'Ada'];     // JSON, 200
return $user;                            // an object: JSON from its public properties
return 'pong';                           // a string: text/plain
return;                                  // nothing: 204 No Content
```

A `DateTimeInterface` is written as ISO 8601 text, a backed enum as its value, and a `JsonSerializable` as it says. A [page of query results](queries.md#pagination) is written with its items and its counts.

Give the shape of what you return a type when clients depend on it:

```php
final readonly class UserView
{
    public function __construct(public int $id, public string $name, public \DateTimeImmutable $joinedAt)
    {
    }
}
```

## Response factories

```php
use Crab\Http\Response;

Response::json($data);                                   // 200
Response::json($data, 202);                              // another status
Response::created($user, "/users/{$user->id}");          // 201, with a Location header
Response::noContent();                                   // 204
Response::text('pong');                                  // text/plain
Response::html('<h1>Hello</h1>');                        // text/html
Response::redirect('/login');                            // 302
Response::download($path, 'invoice.pdf');                // a file
Response::attachment($csv, 'orders.csv', 'text/csv');    // text made here, saved as a file
new Response(418, ['X-Reason' => 'teapot'], 'no coffee');
```

`Response` is a PSR-7 response, so every `with...()` method of the standard works on it.

## Headers

```php
return Response::json($user)
    ->withHeader('Cache-Control', 'max-age=60')
    ->withAddedHeader('Vary', 'Accept-Language');
```

A header every response should carry belongs in [middleware](middleware.md), not in each action.

## Cookies

```php
use Crab\Http\Cookie;

return Response::json(['ok' => true])
    ->withCookie(new Cookie('theme', 'dark', maxAge: 86400 * 30));

return Response::noContent()->withCookie(Cookie::forget('theme'));
```

A cookie is `HttpOnly` and `SameSite=Lax` unless you say otherwise:

```php
new Cookie('session', $id, maxAge: 3600, path: '/', domain: 'example.com', secure: true, httpOnly: true, sameSite: 'Strict');
```

Read cookies with `$request->getCookieParams()`.

## Redirects

```php
return Response::redirect('/login');                     // 302
return Response::redirect('/v2/users', 301);             // 301, 303, 307 or 308
```

## Files

```php
return Response::download('/var/reports/q3.pdf', 'q3-report.pdf', 'application/pdf');
```

The file is read as it is sent, not loaded into memory. The second argument is the name the client saves it under.

For content the action makes, such as a CSV built from a query, send it as it is, with no file in between:

```php
return Response::attachment($csv, 'orders.csv', 'text/csv');
```

## Errors

Do not build an error response by hand. Throw `HttpException`, and the error handler answers in the same form as every other error:

```php
use Crab\Http\HttpException;

throw new HttpException(404, 'No such user.');
throw new HttpException(429, 'Slow down.', ['Retry-After' => '30']);
```

See [Error handling](errors.md).
