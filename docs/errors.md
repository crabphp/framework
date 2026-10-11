# Error handling

- [Introduction](#introduction)
- [The error response](#the-error-response)
- [Answering with an error](#answering-with-an-error)
- [Unexpected failures](#unexpected-failures)
    - [Debug mode](#debug-mode)
- [Warnings are errors](#warnings-are-errors)
- [Failures outside a request](#failures-outside-a-request)
- [Replacing the error handler](#replacing-the-error-handler)

## Introduction

Every error a client sees, from a missing route to a crashed action, is built in one place and has one shape. You throw an exception to answer with an error, and anything you did not expect is logged with its trace and answered `500`. Nothing fails silently: a failure in a queue job, a scheduled task or a timer reaches the same log.

## The error response

A client that accepts JSON gets JSON:

```json
{"error": {"status": 404, "message": "No such user."}}
```

A validation failure adds the problems by field:

```json
{"error": {"status": 422, "message": "The request is invalid.", "fields": {"email": ["must be an email address"]}}}
```

Any other client gets the same as plain text. Send `Accept: application/json` from your API client to always receive JSON.

## Answering with an error

Throw `HttpException` with the status and a message for the client:

```php
use Crab\Http\HttpException;

$user = $this->db->table('users')->find($id) ?? throw new HttpException(404, 'No such user.');

if ($user['id'] !== $me->id) {
    throw new HttpException(403);                                    // the status's own words: "Forbidden"
}

throw new HttpException(429, 'Slow down.', ['Retry-After' => '30']);  // with headers
```

A status below `500` is an answer, not a failure, and is not logged. Use `4xx` for what the client can fix and let real failures be `500`.

Middleware inside the error handler may throw `HttpException`; middleware prepended outside it must return an error response or catch its own failures:

```php
use Crab\Http\ErrorResponse;

return ErrorResponse::make($request, 401, 'Sign in first.', ['WWW-Authenticate' => 'Bearer']);
```

## Unexpected failures

Anything else thrown while handling a request is caught by the error handler, written to the [log](logging.md) with its trace, and answered:

```json
{"error": {"status": 500, "message": "Internal Server Error"}}
```

The client learns nothing about the failure. The worker keeps serving.

### Debug mode

With `app.debug` on, the response carries the exception's class, file, line and trace. It is for your own machine:

```text
APP_DEBUG=true
```

> [!WARNING]
> Never enable `app.debug` in production. A trace shows file paths, queries and sometimes secrets to anyone who can cause an error.

## Warnings are errors

Inside a worker, a PHP warning or notice is thrown as an `ErrorException`. Reading an undefined array key or a file that is not there fails the request, visibly, where plain PHP would print a line and carry on with a wrong value.

- Code silenced with `@` is left alone; check its return value.
- A deprecation is logged once per place and execution continues.
- Console commands other than the server run without this.

## Failures outside a request

| Where | What happens to a failure |
| --- | --- |
| A [queue job](queues.md) | Logged, tried again, and kept as failed when its tries run out |
| A [scheduled task](scheduling.md) | Logged; the task runs again when next due |
| A timer or a task a provider started in a worker | Logged; the worker keeps running |
| A console command | Printed with its class, message and place; the exit status is 1 |

## Replacing the error handler

The error handler is the middleware `Crab\Http\ErrorHandler`. To answer in a format of your own, write a middleware that catches, and put it in its place:

```php
use Crab\Contracts\Http\Middleware;
use Crab\Http\HttpException;
use Crab\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final readonly class ProblemJson implements Middleware
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpException $error) {
            return Response::json(['title' => $error->getMessage()], $error->status, $error->headers);
        } catch (\Throwable $error) {
            $this->logger->error('Request failed: {message}', ['message' => $error->getMessage(), 'exception' => $error]);

            return Response::json(['title' => 'Something went wrong.'], 500);
        }
    }
}
```

```php
// app.php
->withMiddleware(only: [ProblemJson::class])
```

The router's own `404`, `405` and `422` are returned, not thrown, so they do not pass through your `catch`. Rewrite them after `$handler->handle()` returns if they must match.
