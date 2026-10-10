# Requests

- [Introduction](#introduction)
- [Getting the request](#getting-the-request)
- [Input](#input)
- [Headers and cookies](#headers-and-cookies)
- [Route parameters and attributes](#route-parameters-and-attributes)
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

`input()` and `getParsedBody()` read JSON objects and arrays, urlencoded forms and `multipart/form-data`. A body that claims one of these and is malformed is answered `400`.

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

These are the `server.max_body` and `server.body_timeout` settings. An uploaded file is never held in memory: the server keeps a large body on disk and `moveTo()` copies the file out of it, so a 40 MiB upload is handled within a few megabytes. Copying a large file does take the worker's time while it lasts.

## A client that has gone

`$request->cancellation()` fires when the client disconnects before the answer is ready. Pass it to slow work that is pointless without a reader, such as a call to another API:

```php
$report = $this->http->request('GET', $url, cancellation: $request->cancellation());
```

See [HTTP client](http-client.md).
