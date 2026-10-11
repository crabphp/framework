# Requests

- [Introduction](#introduction)
- [Getting the request](#getting-the-request)
- [Input](#input)
- [Headers and cookies](#headers-and-cookies)
- [Route parameters and attributes](#route-parameters-and-attributes)
- [The client's address](#the-clients-address)
- [Uploaded files](#uploaded-files)
    - [Large uploads](#large-uploads)
- [A client that has gone](#a-client-that-has-gone)

## Introduction

Most actions never touch the request: they declare the fields they take and receive them checked; see [Actions](actions.md). Take the request itself when you need what a signature cannot say, such as a header, a cookie, an uploaded file or the raw body.

The request is a PSR-7 server request with four additions. It is immutable: a method starting with `with` returns a changed copy.

## Getting the request

Add a parameter typed as the request, beside any others:

```php
use Crab\Contracts\Http\Request;

Route::post('/webhooks/stripe', static function (Request $request): array {
    $signature = $request->getHeaderLine('Stripe-Signature');
    $payload = (string) $request->getBody();

    return ['received' => true];
});
```

There is no `request()` helper. A worker serves overlapping requests, so the request is passed, never looked up; see [Request lifecycle](lifecycle.md#there-is-no-global-request-or-user).

## Input

```php
$page = $request->query('page', 1);            // from the query string
$name = $request->input('name');               // a form field or a JSON key
$all = $request->getParsedBody();              // the whole body, parsed
$raw = (string) $request->getBody();           // the body as it was sent
```

`input()` and `getParsedBody()` read JSON objects and arrays, urlencoded forms and `multipart/form-data` on first use. Malformed JSON and multipart bodies are answered `400`; urlencoded forms use PHP's permissive `parse_str()`.

PHP's `max_input_vars` can silently truncate query strings and urlencoded forms; multipart forms exceeding their field or part limits are answered `413`.

Prefer declared fields to `input()`: a declared field is validated, an `input()` value is whatever the client sent.

## Headers and cookies

```php
$request->getHeaderLine('Accept');             // "" when absent
$request->hasHeader('Authorization');
$request->getCookieParams()['theme'] ?? 'light';
$request->getMethod();                         // "POST"
$request->getUri()->getPath();                 // "/users/7"
```

## Route parameters and attributes

Route parameters reach an action by name. Middleware reads them, and anything other middleware added, as attributes:

```php
$id = $request->getAttribute('id');                     // the route parameter
$user = $request->getAttribute('user');                 // set by authentication middleware
$requestId = $request->getAttribute('request_id');      // set by RequestId
```

Attributes are how middleware hands something to what runs after it; see [Middleware](middleware.md#passing-data-to-the-action).

## The client's address

```php
$request->ip();          // "203.0.113.7"
```

It is the address at the other end of the connection. Behind a load balancer or a reverse proxy that is the proxy's, for every request. Say which proxies stand in front of the application, and the address becomes the client's:

```php
// app.php
use Crab\Http\TrustedProxies;

->withMiddleware(prepend: [new TrustedProxies(['10.0.0.0/8', '127.0.0.1'])])
```

A proxy passes the client's address on in `X-Forwarded-For`, a header anyone can also write themselves. So it is believed only on a request that comes from a proxy you named, and read from the right: the first address that is not one of your proxies. Without the middleware the header is never read.

> [!WARNING]
> Name only proxies you run. Trusting an address a client can connect from lets that client say it is anyone, which defeats a limit or a block by address.

## Uploaded files

```php
Route::post('/users/{id}/avatar', static function (int $id, Request $request): array {
    $file = $request->file('avatar');                   // a PSR-7 UploadedFileInterface, or null
    if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
        throw new HttpException(422, 'Send the picture as "avatar".');
    }
    $file->moveTo("/var/uploads/{$id}.png");

    return ['size' => $file->getSize(), 'name' => $file->getClientFilename()];
});
```

`getUploadedFiles()` returns the whole tree; fields named `photos[]` nest as they do in PHP. A form may have at most 1,000 parts, and its fields together 1 MiB.

> [!WARNING]
> `getClientFilename()` and `getClientMediaType()` are what the client claims. Never use the name as a path, and check the content yourself before trusting the type.

### Large uploads

A request body may be 1 MiB unless you raise the limit, and a client has 30 seconds to send it:

```php
// config/server.php
return [
    'max_body' => 50 * 1024 * 1024,
    'body_timeout' => 120,
];
```

These are the `server.max_body` and `server.body_timeout` settings. The server keeps small bodies in memory and spills large ones to disk; `moveTo()` copies an upload without loading the whole file, while `getBody()` materializes the whole request body. Copying a large file does take the worker's time while it lasts.

## A client that has gone

`$request->cancellation()` fires when the client disconnects before the answer is ready. Pass it to slow work that is pointless without a reader, such as a call to another API:

```php
$report = $this->http->request('GET', $url, cancellation: $request->cancellation());
```

See [HTTP client](http-client.md).
