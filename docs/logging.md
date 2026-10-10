# Logging

- [Introduction](#introduction)
- [Configuration](#configuration)
- [Writing to the log](#writing-to-the-log)
    - [Context](#context)
    - [Exceptions](#exceptions)
- [What the framework logs](#what-the-framework-logs)
- [Using another logger](#using-another-logger)

## Introduction

Crab ships a small PSR-3 logger that writes one line per record to a file or a stream. Use it to record what your application did and why it failed. Because it is PSR-3, any library that takes a `Psr\Log\LoggerInterface` is given the same logger, and you can swap in Monolog without changing the code that logs.

## Configuration

| Setting | Default | |
| --- | --- | --- |
| `log.path` | `php://stderr` | A file, or a stream such as `php://stderr`. A relative path is inside the application. |
| `log.level` | `debug` | The least severe level written |

```php
// config/log.php
return [
    'path' => env('LOG_PATH', 'php://stderr'),
    'level' => env('LOG_LEVEL', 'debug'),
];
```

Write to standard error in a container, where the platform collects it. Write to a file such as `storage/logs/app.log` on a machine you run yourself. The file is opened for appending, so it can be rotated by copying and truncating.

The levels, from least to most severe, are `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert` and `emergency`. Set `log.level` to `info` or `warning` in production to leave out the chatter.

## Writing to the log

Ask for the logger in a constructor:

```php
use Crab\Contracts\Log\Logger;

final readonly class ChargeCard
{
    public function __construct(private Logger $logger)
    {
    }

    public function handle(int $orderId, int $amount): array
    {
        $this->logger->info('Charging order {order}', ['order' => $orderId, 'amount' => $amount]);

        // ...
    }
}
```

There is a method per level: `debug()`, `info()`, `notice()`, `warning()`, `error()`, `critical()`, `alert()` and `emergency()`.

### Context

The second argument is context. A `{name}` in the message is replaced by the context value of that name, and the rest follows the message as JSON:

```text
2026-10-10T08:15:02.114+00:00 INFO Charging order 1042 {"amount":4900}
```

Put identifiers in the context, not in the message text, so that records can be searched by them.

> [!NOTE]
> The logger is shared by every request a worker serves, so it cannot know which request a record belongs to. Pass what identifies it yourself, such as the [request id](middleware.md#request-ids) or the user's id.

### Exceptions

Give an exception under the `exception` key and it is written after the line, with its trace:

```php
try {
    $this->gateway->charge($amount);
} catch (GatewayException $error) {
    $this->logger->error('Charge failed for order {order}: {message}', [
        'order' => $orderId,
        'message' => $error->getMessage(),
        'exception' => $error,
    ]);

    throw new HttpException(502, 'The payment provider did not answer.');
}
```

Never log a password, a token or a card number. An exception's message may carry one; the framework's own messages do not.

## What the framework logs

| Record | Level |
| --- | --- |
| A request that failed with an exception | `error` |
| A queue job that failed and will be tried again | `warning` |
| A queue job given up on | `error` |
| A scheduled task that failed | `error` |
| A scheduled task skipped because its last run is still going | `warning` |
| A task or timer that failed in a worker | `error` |
| A deprecated PHP feature, once per place | `warning` |

An `HttpException` below `500` is an answer, not a failure, and is not logged.

## Using another logger

Bind your logger under the contract in a provider. Everything, the framework included, then logs through it:

```php
// app/Providers/AppProvider.php
use Crab\Contracts\Log\Logger;
use Psr\Log\LoggerInterface;

$container->singleton(Logger::class, static fn (): Logger => new MyLogger(config('log.path')));
$container->singleton(LoggerInterface::class, Logger::class);
```

`Crab\Contracts\Log\Logger` is PSR-3's interface under Crab's name, so wrapping a PSR-3 logger takes a class of a few lines that implements it.
