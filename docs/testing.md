# Testing

- [Introduction](#introduction)
- [Testing a route](#testing-a-route)
    - [Sending JSON](#sending-json)
    - [Reading the answer](#reading-the-answer)
    - [As a logged-in user](#as-a-logged-in-user)
    - [Socket routes](#socket-routes)
- [Settings for tests](#settings-for-tests)
- [The database](#the-database)
- [Testing a class on its own](#testing-a-class-on-its-own)
- [Replacing a service](#replacing-a-service)
- [Fakes that ship](#fakes-that-ship)
    - [Time](#time)
    - [The HTTP client](#the-http-client)
    - [The queue](#the-queue)
    - [The cache](#the-cache)
- [Code that waits](#code-that-waits)

## Introduction

A Crab application is tested without a server. A test loads `app.php`, hands the application a request, and reads the response; no port is opened and nothing is forked, while the time it takes depends on your code and services.

Most of your code needs even less. Actions, jobs, listeners and commands are plain classes: a test makes one with `new` and calls it.

Tests use PHPUnit and live in `tests/`. Run them with `composer test`.

## Testing a route

```php
// tests/HelloTest.php
namespace Tests;

use Crab\App;
use Crab\Http\Request;
use PHPUnit\Framework\TestCase;

final class HelloTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $this->app = require dirname(__DIR__) . '/app.php';
    }

    public function testHelloSomeone(): void
    {
        $response = $this->app->handle(new Request('GET', '/hello/Ada'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"hello":"Ada"}', (string) $response->getBody());
    }

    public function testUnknownPathIsNotFound(): void
    {
        self::assertSame(404, $this->app->handle(new Request('GET', '/missing'))->getStatusCode());
    }
}
```

`$app->handle()` runs the request through everything a real one meets: the middleware, the router, validation and your action. Each test loads a fresh container; isolate persistent stores and environment state too, since loading another `.env` does not clear omitted keys.

### Sending JSON

`Crab\Testing\Api` sends requests as a client would. Data goes as JSON and JSON is asked for, so errors come back as JSON too:

```php
use Crab\Testing\Api;

$api = new Api($this->app);

$api->get('/users', ['page' => 2]);
$api->post('/users', ['email' => 'ada@example.com', 'name' => 'Ada']);
$api->patch('/users/1', ['name' => 'Ada L.']);
$api->delete('/users/1');
```

`put()` takes data as `post()` does, `request($method, $path, $json, $headers)` sends anything else, and `withHeaders([...])` gives an `Api` that adds those headers to every request.

### Reading the answer

```php
$response = $api->post('/users', ['email' => 'not-an-email']);

self::assertSame(422, $response->status);
self::assertSame(['must be an email address'], $response->json('error.fields.email'));
self::assertSame('/users/1', $api->post('/users', $valid)->header('Location'));
```

| | |
| --- | --- |
| `$response->status` | The status |
| `$response->json()` | The body, decoded |
| `$response->json('data.0.name')` | One value of it, by a path with dots |
| `$response->header('Location')` | A header |
| `$response->assertStatus(201)` | Checks the status and returns the response for chaining |
| `$response->assertJson('data.0.name', 'mug')` | Checks that a dot path exists and its value and type match exactly |
| `$response->assertHeader('Location', '/users/1')` | Checks that a header exists and has this value |
| `$response->body()` | The body as text |
| `$response->response` | The PSR-7 response |

`assertStatus()` fails the test when the status is another, and says what was answered instead, which with a `422` or a `500` is the reason:

```php
$order = $api->post('/orders', $lines)->assertStatus(201)->json();
// POST /orders answered 422, not 201: {"error":{"status":422,"fields":{"lines.0.quantity":["must be at most 20"]}}}
```

`assertJson()` and `assertHeader()` also return the response for chaining, and include the request, status and body when an assertion fails:

```php
$api->get('/products/1')->assertStatus(200)->assertJson('name', 'Stoneware Mug');
$api->post('/users', $valid)->assertStatus(201)->assertHeader('Location', '/users/1');
```

`json()` throws `LogicException` for a non-JSON body or a missing path; use `assertJson()` to report it as an assertion failure.

### As a logged-in user

`actingAs()` puts the user on each request and bypasses the built-in `TokenAuth`, with no token to issue and send. Custom authentication middleware must honor that attribute or be tested with its real credentials:

```php
$ada = new User(1, 'ada@example.com');

$api->actingAs($ada)->get('/me')->assertStatus(200);
$api->get('/me')->assertStatus(401);            // without it, still refused
```

Use `$api->withToken($token)->get('/me')` when testing token authentication itself; use `actingAs()` when the test only needs a logged-in user. `withToken($token, 'Token')` changes the scheme, and `withToken($token, '')` sends the token alone.

### Socket routes

`$api->websocket('/live')` opens a real socket route over an in-process socket pair. Arrays travel as JSON, and `WebSocket\Binary` preserves binary messages. Keep Api as your fixture: it closes all sockets when it leaves scope, including after failed assertions. When your test case stores Api in a property, call `$this->api->closeSockets()` in `tearDown()`.

`TestSocket::close()` waits for connection-owned action cleanup with a one-second bound and finishes the main-context loop. See [WebSockets](websockets.md#testing) for messages, close assertions and HTTP refusals.

## Settings for tests

Tests should not use your development database or send real email. Configuration files read the environment, and PHPUnit can set it, so say what differs in `phpunit.xml`:

```xml
<phpunit bootstrap="vendor/autoload.php">
    <php>
        <env name="DB_DSN" value="sqlite::memory:" force="true"/>
        <env name="QUEUE_STORE" value="memory" force="true"/>
        <env name="CACHE_STORE" value="array" force="true"/>
        <env name="LOG_PATH" value="php://memory" force="true"/>
    </php>
</phpunit>
```

```php
// config/queue.php
return ['store' => env('QUEUE_STORE', 'database')];
```

The real environment wins over `.env`, so these apply whatever `.env` says.

## The database

With `sqlite::memory:` every test starts with an empty database that vanishes afterwards. Run your migrations in `setUp()`:

```php
use Crab\Contracts\Database\Database;
use Crab\Contracts\Database\Migrator;

private Database $db;

protected function setUp(): void
{
    $this->app = require dirname(__DIR__) . '/app.php';
    $this->app->container->get(Migrator::class)->run();
    $this->db = $this->app->container->get(Database::class);
}

public function testAUserIsStored(): void
{
    $api = new Api($this->app);
    $api->post('/users', ['email' => 'ada@example.com', 'name' => 'Ada']);

    self::assertSame('Ada', $this->db->table('users')->where('email', 'ada@example.com')->value('name'));
}
```

SQLite's pool is one connection, which is what keeps an in-memory database alive for the whole test. If production runs MySQL, run the suite against a MySQL test database as well before a release: the two differ in what they accept.

## Testing a class on its own

An action is a function of its arguments. Make it and call it:

```php
public function testShowUserReturnsTheRow(): void
{
    $db = $this->app->container->get(Database::class);
    $id = $db->table('users')->insert(['email' => 'ada@example.com', 'name' => 'Ada']);

    $user = (new ShowUser($db))->handle($id);

    self::assertSame('Ada', $user['name']);
}

public function testShowUserRefusesAnUnknownId(): void
{
    $this->expectException(HttpException::class);

    (new ShowUser($this->db))->handle(999);
}
```

The same goes for a job (`(new SendWelcomeEmail($db, $mailer))->handle(userId: 1)`), a listener, and a command (`$command->handle(new \Crab\Console\Input(['--dry-run']), $output)`).

Test through `$app->handle()` what depends on the route: validation, middleware, the status code. Test by calling the class what the class decides.

## Replacing a service

Bind something else under the service's name before the first request of the test:

```php
use Crab\Contracts\HttpClient\Client;

$mailer = new RecordingMailer();
$this->app->container->instance(Mailer::class, $mailer);

$api->post('/users', ['email' => 'ada@example.com', 'name' => 'Ada']);

self::assertSame(['ada@example.com'], $mailer->recipients);
```

It is replaced under every name it has: put a `FrozenClock` under Crab's `Clock` and whoever asks for PSR's `ClockInterface` gets it too. A service that was already made keeps what it was given, so replace before the first request.

This is why services are asked for by interface: a test can then stand anything behind it. A fake is usually a few lines of your own that record what they were asked to do.

## Fakes that ship

### Time

Code that needs the time asks for the clock, never `time()` or `new DateTime()`:

```php
use Crab\Contracts\Clock\Clock;

final readonly class IssueCoupon
{
    public function __construct(private Clock $clock)
    {
    }

    public function expiry(): \DateTimeImmutable
    {
        return $this->clock->now()->modify('+30 days');
    }
}
```

A test then holds the clock still, and moves it:

```php
use Crab\Testing\FrozenClock;

$clock = new FrozenClock('2026-01-31 12:00:00');
$this->app->container->instance(Clock::class, $clock);

// ...
$clock->advance('+31 days');
// ... the coupon has now expired.
```

### The HTTP client

`FakeClient` answers from replies you write in advance, and records what was sent:

```php
use Crab\HttpClient\FakeClient;

$http = new FakeClient([
    'GET https://api.weather.example/*' => ['temperature' => 21],
]);
$this->app->container->instance(Client::class, $http);

$response = (new Api($this->app))->get('/weather', ['city' => 'Paris']);

self::assertCount(1, $http->sent('GET https://api.weather.example/*'));
```

A request no reply matches throws, so a test never reaches the network. See [HTTP client](http-client.md#in-tests).

### The queue

With `queue.store` set to `memory`, jobs are kept in the test's own process. Assert on what was pushed:

```php
use Crab\Contracts\Queue\Store;

$api->post('/users', ['email' => 'ada@example.com', 'name' => 'Ada']);

$waiting = $this->app->container->get(Store::class)->jobs('default', 'pending', time());
self::assertSame(SendWelcomeEmail::class, $waiting[0]->job);
self::assertSame(['userId' => 1], $waiting[0]->data);
```

To run what was pushed as well, ask the consumer for the next job:

```php
use Crab\Queue\Consumer;

self::assertTrue($this->app->container->get(Consumer::class)->runNext());
```

### The cache

With `cache.store` set to `array`, entries live in the test's memory and are gone with it.

## Code that waits

Most tests never need the event loop. For a provider that starts a timer in each worker, or for code that sleeps or runs several things at once, `WorkerHarness` runs a body inside a real worker in the test's process:

```php
use Crab\Engine\Async\Async;
use Crab\Engine\Process\Worker;
use Crab\Contracts\Container\Container;
use Crab\Testing\WorkerHarness;

$flushed = WorkerHarness::run([new MetricsProvider()], static function (Worker $worker, Container $container): int {
    Async::sleep($worker->loop, 0.05);                  // long enough for the provider's timer

    return $container->get(Metrics::class)->flushes;
});

self::assertGreaterThan(0, $flushed);
```

It registers the providers, starts them, runs the body, stops them, and throws again anything the body, a provider or a timer threw.
