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

The request extends the PSR-7 server request. It is immutable: a method starting with `with` returns a changed copy.

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

Each JSON object may have at most 1,000 members, including repeated names. This bound applies inside nested objects too; exceeding it is answered `413` before the object is decoded. Lists of smaller objects may contain more than 1,000 items, within `server.max_body`. Declared `Length` bounds on typed lists are checked before their items are validated.

PHP's `max_input_vars` can silently truncate query strings and urlencoded forms; multipart forms exceeding their field or part limits are answered `413`.

Prefer declared fields to `input()`: a declared field is validated, an `input()` value is whatever the client sent.

## Headers and cookies

```php
$request->getHeaderLine('Accept');             // "" when absent
$request->hasHeader('Authorization');
$request->cookie('theme', 'light');            // a cookie, or the default when it was not sent
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

It is the address at the other end of the connection. An IPv4 client of a server listening on `[::]` is given as its IPv4 address, not as `::ffff:203.0.113.7`. Behind a load balancer or a reverse proxy that is the proxy's, for every request. Say which proxies stand in front of the application, and the address becomes the client's:

```php
// app.php
use Crab\Http\TrustedProxies;

->withMiddleware(prepend: [new TrustedProxies(['10.0.0.0/8', '127.0.0.1'])])
```

A proxy passes the client's address on in `X-Forwarded-For`, a header anyone can also write themselves. So it is believed only on a request that comes from a proxy you named, and read from the right: the first address that is not one of your proxies. Without the middleware the header is never read.

> [!WARNING]
> Name only proxies you run. Trusting an address a client can connect from lets that client say it is anyone, which defeats a limit or a block by address.

## Uploaded files

A file the client uploads is a field of the body, like any other, typed as PSR-7's `UploadedFileInterface` in the action's [input class](actions.md#an-input-class). It is checked before the action runs:

```php
// app/Http/NewAvatar.php
namespace App\Http;

use Crab\Validation\FileSize;
use Crab\Validation\MediaType;
use Psr\Http\Message\UploadedFileInterface;

final readonly class NewAvatar
{
    public function __construct(
        #[FileSize(max: 2 * 1024 * 1024)]
        #[MediaType('image/png')]
        public UploadedFileInterface $avatar,
        public int $rotate = 0,
    ) {
    }
}
```

```php
// app/Actions/ReplaceAvatar.php
namespace App\Actions;

use App\Http\NewAvatar;
use Crab\Contracts\Storage\Disk;

final readonly class ReplaceAvatar
{
    public function __construct(private Disk $disk)
    {
    }

    public function handle(User $user, NewAvatar $input): array
    {
        $this->disk->write("avatars/{$user->id}.png", $input->avatar->getStream(), 'image/png');

        return ['avatar' => "avatars/{$user->id}.png"];
    }
}
```

```php
// routes/web.php
Route::post('/me/avatar', ReplaceAvatar::class)->middleware(TokenAuth::class);
```

The client sends a `multipart/form-data` form with a file field named `avatar`. The form's other fields are fields of the class too, read from text as a query string's are, so `int $rotate` takes `rotate=90`. The file is named after the logged-in [user](authentication.md), not an id in the URL, so no one can replace someone else's picture; [file storage](storage.md#storing-an-upload) keeps it on a disk, local or on S3.

- A file is required unless the property is nullable, `?UploadedFileInterface $attachment`, which is null when none was sent. A file input the user left empty counts as none.
- `#[FileSize]` bounds its bytes and `#[MediaType]` its kind, `'image/png'` or `'image/*'`. The kind is read from the file's first bytes, never from what the client says, so a script named `me.png` is refused; it needs PHP's `fileinfo` extension, and a route that uses it without one stops the server from starting.
- Several files under one name, `photos[]`, are `#[ListOf(UploadedFileInterface::class)] public array $photos`. `#[Length]` bounds how many; `#[FileSize]` and `#[MediaType]` hold for each file, and a problem is reported as `photos.1`.
- `Data` described with `#[Fields]` takes a file the same way: `'avatar' => UploadedFileInterface::class`.
- JSON carries no file: a field typed as a file is refused in a JSON body. A file is never an action's parameter of its own, and never a query field; either is refused when the route is added.

`$request->file('avatar')` returns the same file, or null, and `getUploadedFiles()` the whole tree, for an action that reads the request itself. A form may have at most 1,000 parts, and its fields together 1 MiB.

> [!WARNING]
> `getClientFilename()` and `getClientMediaType()` are what the client claims. Never use the name as a path, and check the kind with `#[MediaType]` before trusting the type.

### Large uploads

A request body may be 1 MiB unless you raise the limit, and a client has 30 seconds to send it:

```php
// config/server.php
return [
    'max_body' => 50 * 1024 * 1024,
    'body_timeout' => 120,
];
```

These are the `server.max_body` and `server.body_timeout` settings, and they bound every request; `#[FileSize]` is checked once a file has arrived. The server keeps small bodies in memory and spills large ones to disk; `moveTo()` copies an upload without loading the whole file, while `getBody()` materializes the whole request body. Copying a large file does take the worker's time while it lasts.

## A client that has gone

`$request->cancellation()` fires when the client disconnects before the answer is ready. Pass it to slow work that is pointless without a reader, such as a call to another API:

```php
$report = $this->http->request('GET', $url, cancellation: $request->cancellation());
```

See [HTTP client](http-client.md).
