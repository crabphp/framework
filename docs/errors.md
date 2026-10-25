# Error handling

- [Introduction](#introduction)
- [The error response](#the-error-response)
- [Answering with an error](#answering-with-an-error)
- [Unexpected failures](#unexpected-failures)
    - [Debug mode](#debug-mode)
- [Warnings are errors](#warnings-are-errors)
- [Failures outside a request](#failures-outside-a-request)
- [Your own error format](#your-own-error-format)
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

## Your own error format

An API with clients of its own, or a standard to follow, can write every error in its own shape. Bind `Crab\Contracts\Http\ErrorRenderer` in a provider's `register()`:

```php
// app/Providers/AppProvider.php
use App\Http\Errors;
use Crab\Contracts\Http\ErrorRenderer;

$container->singleton(ErrorRenderer::class, Errors::class);
```

It is given a `Problem`, with the status, the message safe for the client and the problems by field, and returns the response:

```php
// app/Http/Errors.php
namespace App\Http;

use Crab\Contracts\Http\ErrorRenderer;
use Crab\Contracts\Http\Problem;
use Crab\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class Errors implements ErrorRenderer
{
    public function render(Problem $problem, ServerRequestInterface $request): ResponseInterface
    {
        return Response::json(['message' => $problem->message, 'errors' => $problem->fields], $problem->status);
    }
}
```

It writes every error the application answers with: the router's `404` and `405`, a validation `422`, the `401` of authentication, the `429` of a rate limit, an `HttpException` and a failure's `500`. An error your middleware returns is written by it too, when the middleware makes it with `ErrorResponse::make()`:

```php
use Crab\Http\ErrorResponse;

return ErrorResponse::make($request, 401, 'Sign in first.', ['WWW-Authenticate' => 'Bearer']);
```

What it may rely on:

- **It runs after all middleware**, even middleware in front of the error handler, such as CORS. The headers they gave the error (`Allow`, `WWW-Authenticate`, `Retry-After`, CORS) are kept unless your response sets the same one.
- **The status is yours.** It is usually `$problem->status`, but you may answer a status of your own.
- **`$problem->cause` is the exception the request was answered for**, when there was one. Map your own exceptions to codes with it; extend `HttpException` for answers below `500`, so they are not logged as failures. Show the cause itself only when `$problem->debug` is on.
- **Crab's own format is at hand** for anything you do not write yourself: `ErrorResponse::render($problem, $request)`.
- **If it throws,** the failure is logged and the client gets the error in Crab's format.

A response changed by middleware after it was made, with a new status or body, is that middleware's answer and is sent as it is. Errors the server gives before a request reaches the application, such as a body larger than `server.max_body`, are in Crab's plain format.

## Replacing the error handler

The error handler is the middleware `Crab\Http\ErrorHandler`: it logs failures and turns exceptions into error responses. To change only the format, bind an `ErrorRenderer` instead. Replace the handler when what happens on a failure must change, for example to report it to a service as well as the log. Keep cancellation passing through, log server failures, leave answers below `500` unlogged, and answer with `ErrorResponse::make()` so the format stays the renderer's:

```php
use Crab\Contracts\Http\Middleware;
use Crab\Engine\Async\CancelledException;
use Crab\Http\ErrorResponse;
use Crab\Http\HttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final readonly class ReportedErrors implements Middleware
{
    public function __construct(private LoggerInterface $logger, private Reporter $reporter)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (CancelledException $cancelled) {
            throw $cancelled;
        } catch (\Throwable $error) {
            $status = $error instanceof HttpException ? $error->status : 500;
            if ($status >= 500) {
                $this->logger->error('Request failed: {message}', ['message' => $error->getMessage(), 'exception' => $error]);
                $this->reporter->report($error);
            }

            return ErrorResponse::make(
                $request,
                $status,
                $error instanceof HttpException ? $error->getMessage() : '',
                $error instanceof HttpException ? $error->headers : [],
                cause: $error,
            );
        }
    }
}
```

```php
// app.php
->withMiddleware(only: [ReportedErrors::class])
```
