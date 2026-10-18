<?php

declare(strict_types=1);

namespace Crab\Engine\Network;

use Closure;
use Crab\Engine\EventLoop;
use Crab\Engine\FailureReporter;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class Connector
{
    /**
     * Numeric TCP endpoints only: DNS resolution must happen before this call.
     *
     * @param callable(?Connection, ?Throwable): void $completion
     * @return Closure(): void Cancellation completes once with an error.
     */
    public static function connect(
        EventLoop $loop,
        string $address,
        callable $completion,
        float $timeout = 5.0,
    ): Closure {
        $parts = parse_url($address);
        if (
            $parts === false
            || ($parts['scheme'] ?? '') !== 'tcp'
            || !isset($parts['host'], $parts['port'])
            || filter_var(trim($parts['host'], '[]'), FILTER_VALIDATE_IP) === false
            || $parts['port'] < 1
            || $parts['port'] > 65_535
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['path'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException(
                'Connect requires a numeric tcp://IPv4:port or tcp://[IPv6]:port endpoint.',
            );
        }
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new InvalidArgumentException('Connect timeout must be positive.');
        }
        $stream = @stream_socket_client(
            $address,
            $errno,
            $error,
            0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            stream_context_create(['socket' => ['tcp_nodelay' => true]]),
        );
        $watcher = $timer = $deferred = null;
        $done = false;
        $finish = static function (?Throwable $failure = null) use (
            $loop,
            $address,
            $completion,
            &$stream,
            &$watcher,
            &$timer,
            &$deferred,
            &$done,
        ): void {
            if ($done) {
                return;
            }
            $done = true;
            foreach ([$watcher, $timer, $deferred] as $id) {
                if ($id === null) {
                    continue;
                }

                $loop->cancel($id);
            }
            $connection = null;
            if ($failure !== null) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            } else {
                $connection = new Connection($loop, $stream, substr($address, 6));
            }
            $stream = null;
            try {
                $completion($connection, $failure);
            } catch (Throwable $error) {
                $connection?->close();
                FailureReporter::report('Connect callback failed', $error);
            }
        };
        if ($stream === false) {
            $deferred = $loop->defer(static fn() => $finish(
                new RuntimeException("Connect to {$address} failed: {$error}", $errno),
            ));
        } else {
            stream_set_blocking($stream, false);
            $watcher = $loop->onWritable($stream, static function () use (&$stream, $address, $finish): void {
                // Writability also signals connection refusal. getpeername only succeeds after establishment.
                if (@stream_socket_get_name($stream, true) === false) {
                    $finish(new RuntimeException("Connect to {$address} failed."));
                } else {
                    $finish();
                }
            });
            $timer = $loop->delay($timeout, static fn() => $finish(
                new RuntimeException("Connect to {$address} timed out."),
            ));
        }
        return static fn() => $finish(new RuntimeException("Connect to {$address} cancelled."));
    }
}
