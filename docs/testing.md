# Testing

- [Introduction](#introduction)
- [Testing a route](#testing-a-route)
    - [Sending JSON](#sending-json)
    - [Reading the answer](#reading-the-answer)
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

A Crab application is tested without a server. A test loads `app.php`, hands the application a request, and reads the response; no port is opened and nothing is forked, so a suite of hundreds of tests runs in a second or two.

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

`$app->handle()` runs the request through everything a real one meets: the middleware, the router, validation and your action. Each test loads a fresh application, so nothing carries over from one to the next.

### Sending JSON

A request takes a method, a path, headers and a body:

```php
$response = $this->app->handle(new Request(
    'POST',
    '/users',
    ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
    json_encode(['email' => 'ada@example.com', 'name' => 'Ada']),
));

self::assertSame(201, $response->getStatusCode());
self::assertSame('/users/1', $response->getHeaderLine('Location'));
```

Send `Accept: application/json` to get errors as JSON, as your real clients do. A helper keeps tests short:

```php
private function json(string $method, string $path, array $data = [], array $headers = []): ResponseInterface
{
    return $this->app->handle(new Request(
        $method,
        $path,
        ['Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers,
        $data === [] ? '' : json_encode($data),
    ));
}
```

### Reading the answer

```php
$response = $this->json('POST', '/users', ['email' => 'not-an-email']);

self::assertSame(422, $response->getStatusCode());

$body = json_decode((string) $response->getBody(), true);
self::assertSame(['must be an email address'], $body['error']['fields']['email']);
self::assertSame(['is required'], $body['error']['fields']['name']);
```

## Settings for tests

Tests should not use your development database or send real email. Configuration files read the environment, and PHPUnit can set it, so say what differs in `phpunit.xml`:

```xml
<phpunit ...>
    <php>
        <env name="DB_DSN" value="sqlite::memory:"/>
        <env name="QUEUE_STORE" value="memory"/>
        <env name="CACHE_STORE" value="array"/>
        <env name="LOG_PATH" value="php://memory"/>
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

protected function setUp(): void
{
    $this->app = require dirname(__DIR__) . '/app.php';
    $this->app->container->get(Migrator::class)->run();
    $this->db = $this->app->container->get(Database::class);
}

public function testAUserIsStored(): void
{
    $this->json('POST', '/users', ['email' => 'ada@example.com', 'name' => 'Ada']);

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

The same goes for a job (`(new SendWelcomeEmail($db, $mailer))->handle(userId: 1)`), a listener, and a command (`$command->handle(new Input(['--dry-run']), $output)`).

Test through `$app->handle()` what depends on the route: validation, middleware, the status code. Test by calling the class what the class decides.

## Replacing a service

Bind something else under the service's name before the first request of the test:

```php
use Crab\Contracts\HttpClient\Client;

$mailer = new RecordingMailer();
$this->app->container->instance(Mailer::class, $mailer);

$this->json('POST', '/users', ['email' => 'ada@example.com', 'name' => 'Ada']);

self::assertSame(['ada@example.com'], $mailer->recipients);
```

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

$response = $this->json('GET', '/weather?city=Paris');

self::assertCount(1, $http->sent('GET https://api.weather.example/*'));
```

A request no reply matches throws, so a test never reaches the network. See [HTTP client](http-client.md#in-tests).

### The queue

With `queue.store` set to `memory`, jobs are kept in the test's own process. Assert on what was pushed:

```php
use Crab\Contracts\Queue\Store;

$this->json('POST', '/users', ['email' => 'ada@example.com', 'name' => 'Ada']);

$waiting = $this->app->container->get(Store::class)->waiting();
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
