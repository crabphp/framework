# HTTP client

- [Introduction](#introduction)
- [Configuration](#configuration)
- [Making requests](#making-requests)
    - [Sending data](#sending-data)
    - [Reading the answer](#reading-the-answer)
- [Errors](#errors)
- [Timeouts](#timeouts)
- [Trying again](#trying-again)
- [Sending files](#sending-files)
- [A client for one API](#a-client-for-one-api)
- [Cookies](#cookies)
- [A proxy](#a-proxy)
- [What it does not do](#what-it-does-not-do)
- [In tests](#in-tests)

## Introduction

The HTTP client is how your application calls other servers: a payment provider, a weather API, another of your own services.

Always use it in place of cURL, Guzzle or `file_get_contents()`. Those hold the whole worker until the other server answers, so one slow API stalls every request the worker has. This client waits on the event loop: while a request is on its way, the worker serves other requests. That holds for every step, finding the server's address, connecting, encrypting, sending and waiting.

It is the PSR-18 client, so a library that asks for `Psr\Http\Client\ClientInterface` is given the same one.

## Configuration

The HTTP client is registered by default by `App::configure()`, so it needs nothing in `app.php`.

| Setting | Default | |
| --- | --- | --- |
| `http_client.timeout` | `30.0` | Seconds a whole call may take, retries included |
| `http_client.connect_timeout` | `5.0` | Seconds connecting may take |
| `http_client.redirects` | `5` | Redirects followed; `0` returns the redirect itself |
| `http_client.connections` | `8` | HTTP/1.1 connections to one server; HTTP/2 stream concurrency follows the peer's settings |
| `http_client.base_url` | none | Put before every relative URL of the default client |
| `http_client.headers` | none | Added to every request of the default client |
| `http_client.proxy` | none | `http://host:port`, with `user:password@` when it asks |
| `http_client.retries` | `0` | More tries for a request that may be tried again |
| `http_client.http2` | `true` | `false` to speak HTTP/1.1 with every server |
| `http_client.tls` | `[]` | PHP's `ssl` context options, such as `['cafile' => '/path/ca.pem']` |

`https` needs PHP's `openssl` extension.

## Making requests

Ask for the client in a constructor:

```php
use Crab\Contracts\HttpClient\Client;

final readonly class ShowWeather
{
    public function __construct(private Client $http)
    {
    }

    public function handle(string $city): array
    {
        $weather = $this->http->get('https://api.weather.example/v1/now', query: ['city' => $city]);

        return $weather->ok() ? $weather->json() : ['error' => $weather->getStatusCode()];
    }
}

Route::get('/weather/{city}', ShowWeather::class);
```

```php
$http->get($url, query: ['page' => 2], headers: ['Accept' => 'application/json']);
$http->post($url, json: ['name' => 'Ada']);
$http->put($url, json: $data);
$http->patch($url, json: ['name' => 'Ada L.']);
$http->delete($url);
$http->request('PUT', $url, body: $csv, headers: ['Content-Type' => 'text/csv']);
```

### Sending data

| Argument | Sends |
| --- | --- |
| `query:` | The query string |
| `json:` | A JSON body |
| `form:` | A urlencoded form, or a form with files when `files:` is given too |
| `files:` | Files, as `multipart/form-data` |
| `body:` | A body as it is, with `request()` |
| `headers:` | Headers for this request |

`json: null` sends no JSON body, and `form: []` without files sends no form body. To send the JSON literal null, use `request()` with `body: 'null', headers: ['Content-Type' => 'application/json']`.

### Reading the answer

The answer is a PSR-7 response with three additions:

```php
$response->ok();                    // whether the status is 200 to 299
$response->json();                  // the body, decoded
$response->throw();                 // throws unless the status is 2xx; otherwise returns the response
$response->getStatusCode();
$response->getHeaderLine('Content-Type');
(string) $response->getBody();
```

## Errors

An error status is an answer. A `404` or a `500` is returned like any other, because what to do about it is your decision:

```php
$response = $http->get("https://api.example.com/users/{$id}");
if ($response->getStatusCode() === 404) {
    throw new HttpException(404, 'No such user.');
}
```

When anything but a `2xx` is a failure for you, say so with `throw()`. That includes a `304` and a redirect that was not followed:

```php
$user = $http->get($url)->throw()->json();
```

| Exception | When |
| --- | --- |
| `Crab\HttpClient\ResponseException` | `throw()` met a status that is not `2xx`. `$error->response` is the answer. |
| `Crab\HttpClient\NetworkException` | The server could not be reached, or took too long |
| `Crab\HttpClient\RequestException` | The request itself could not be sent |

No answer always throws. All three are a `Psr\Http\Client\ClientExceptionInterface`, and no message holds the query string or a header, so they are safe to log.

`json()` throws `JsonException` for an empty or malformed JSON body, and `LengthException` when any JSON object has more than 1,000 members, including repeated names. Lists of smaller objects may contain more than 1,000 items. Exceeding the redirect limit throws `Crab\HttpClient\ClientException`.

## Timeouts

```php
$http->timeout(2.0)->get($url);
```

`timeout()` returns a client that allows the whole call that many seconds: finding the address, connecting, waiting for a free connection, redirects, retries and their pauses all share one deadline. Each later try gets the time left. `http_client.connect_timeout` also limits each connection, within that remaining time. The client you called it on is unchanged, which matters because every request a worker serves shares it.

Give every call to another service a timeout you chose. The default of 30 seconds is longer than most clients of your API will wait.

To give up when your own client has gone, pass its cancellation:

```php
$http->request('GET', $url, cancellation: $request->cancellation());
```

## Trying again

```php
$http->retry(3)->get($url);                 // up to three more times
$http->retry(3, delay: 0.5)->get($url);     // random pauses: 0.375–0.5 s, 0.75–1 s, 1.5–2 s
$http->timeout(2)->retry(3)->get($url);     // two seconds for the whole call
```

A request is tried again only when that cannot do it twice:

| Request | Tried again when |
| --- | --- |
| `GET`, `HEAD`, `OPTIONS`, `PUT`, `DELETE` | It got no answer, or `429`, `502`, `503` or `504` |
| `POST`, `PATCH` | None of it was sent, or it was answered `429` or `503` |

Retrying also requires an empty or seekable body. A request outside the first row is never retried after following a redirect, even if the next hop fails or asks for a retry.

A server's `Retry-After` replaces a shorter delay and must fit before the whole-call deadline. A `500` is never tried again: a server that failed may fail the same way.

If the next pause cannot fit before the deadline, the call ends at once with the last try's result: its response is returned, or its failure is thrown. No more requests are sent. A try or connection that itself runs out of time throws `NetworkException`.

For work that must eventually succeed, such as delivering a webhook, do not retry in the request. Push a [job](queues.md), which retries over minutes.

## Sending files

```php
use Crab\Contracts\HttpClient\File;

$http->post($url, form: ['title' => 'Q3'], files: [
    'report' => File::fromPath('/tmp/q3.pdf', type: 'application/pdf'),
    'notes' => File::fromContents($text, 'notes.txt', 'text/plain'),
    'avatar' => $request->file('avatar'),               // an upload you received, passed on
    'photos' => [File::fromPath($one), File::fromPath($two)],
]);
```

Files are copied in chunks into a temporary body stream, which spills to disk when large; small bodies may stay in memory.

## A client for one API

Make a client with the API's address and credentials, once, in a provider, and bind it under a name of your own:

```php
// app/Providers/AppProvider.php
use Crab\HttpClient\Client;

$container->singleton(StripeClient::class, static fn (): StripeClient => new StripeClient(
    new Client('https://api.stripe.com/v1', headers: ['Authorization' => 'Bearer ' . config('stripe.key')]),
));
```

```php
final readonly class StripeClient
{
    public function __construct(private Client $http)
    {
    }

    public function charge(int $amount, string $source): array
    {
        return $this->http->post('/charges', form: ['amount' => $amount, 'source' => $source])->throw()->json();
    }
}
```

Wrapping the client in a class of your own gives the rest of the application methods that mean something, and one place to fake in tests.

A redirect to another origin drops only `Authorization` and `Cookie`; other credential headers, such as `X-Api-Key`, remain. Send those credentials with `redirects: 0` on the client, or follow redirects only from a base URL you trust.

## Cookies

A client keeps no cookies unless it is given a jar, because one jar on a client that every request shares would hand one caller's cookies to the next. Give each caller a separate jar for each site. A jar accepts cookies for parent domains and does not check a public suffix list, so a jar shared across unrelated hosts can be given a parent-domain cookie that reaches another site:

```php
use Crab\HttpClient\CookieJar;

$session = $http->cookies(new CookieJar());
$session->post('https://shop.example/login', form: ['user' => 'ada', 'pass' => $secret]);
$session->get('https://shop.example/orders');            // sent with the cookie the login set
```

A plaintext HTTP response cannot replace, delete or shadow an unexpired Secure cookie of the same name on an overlapping domain and path. This also holds while following redirects. HTTPS responses can update or remove those cookies normally.

## A proxy

`http_client.proxy`, or `proxy:` on a client of your own, sends every request through an HTTP proxy: `http://user:password@proxy.example:8080`. An `https` request goes through a tunnel the proxy cannot read.

## What it does not do

- HTTP/3, HTTP/2 without encryption, and server push.
- Proxies reached over TLS, and SOCKS proxies.
- Brotli. With PHP's `zlib` extension, answers compressed with gzip or deflate are decompressed for you; without it they remain encoded.

HTTP/2 is spoken with every `https` server that offers it: all requests to one server then share one connection and run at once. With the others it is HTTP/1.1 on connections that are kept and reused, up to `http_client.connections` to one server.

## In tests

`FakeClient` is the real client with replies written in advance in place of the network:

```php
use Crab\Contracts\HttpClient\Client;
use Crab\HttpClient\FakeClient;
use Psr\Http\Message\RequestInterface;

$http = new FakeClient([
    'GET https://api.example.com/users/7' => ['id' => 7, 'name' => 'Ada'],                       // JSON, 200
    'POST https://api.example.com/charges' => FakeClient::response(402, ['error' => 'declined']),
    'https://slow.example.com/*' => new RuntimeException('it timed out'),                         // thrown
    'https://echo.example.com/*' => fn (RequestInterface $request) => (string) $request->getBody(),
]);
$app->container->instance(Client::class, $http);

// ... run the code under test ...

self::assertCount(1, $http->sent('POST https://api.example.com/charges'));
self::assertSame('{"amount":500}', (string) $http->requests[0]->getBody());
```

- A reply is found by method and URL, the first that matches. `*` stands for anything, and the method may be left out. Patterns without `?` ignore the query string; include it and the expected query or wildcards to distinguish query values.
- A request nothing matches throws, naming it, so a test never reaches the network.
- Everything else is the real client: base URL, headers, redirects, cookies and `throw()` behave as they do over the network.
