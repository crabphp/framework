<?php

declare(strict_types=1);

namespace Crab\Engine\Network;

use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\EventLoop;
use RuntimeException;
use Throwable;

/**
 * Opens a nonblocking outbound byte connection for low-level protocols.
 *
 * The returned Connection owns the stream. Call this from a worker task: the
 * DNS lookup, TCP connect and optional TLS handshake suspend the task while
 * the worker continues serving other connections.
 */
final readonly class Connector
{
    public function __construct(
        private DnsResolver $dns = new DnsResolver(),
    ) {}

    /**
     * @param array<string, mixed> $tls PHP SSL context options
     * @throws Throwable When no address connects or the operation is cancelled.
     */
    public function connect(
        EventLoop $loop,
        string $host,
        int $port,
        float $deadline,
        ?Cancellation $cancellation = null,
        ?string $tlsHost = null,
        array $tls = [],
        int $maxWriteBufferSize = 1_048_576,
        float $closeTimeout = 5.0,
    ): Connection {
        if ($port < 1 || $port > 65_535 || !is_finite($deadline)) {
            throw new RuntimeException('An outbound connection needs a valid port and deadline.');
        }
        if ($tlsHost === '') {
            $tlsHost = null;
        }
        $addresses = $this->dns->resolve($loop, $host, $cancellation);
        $failure = null;
        foreach ($addresses as $address) {
            try {
                return $this->open(
                    $loop,
                    $address,
                    $port,
                    $deadline,
                    $cancellation,
                    $tlsHost,
                    $tls,
                    $maxWriteBufferSize,
                    $closeTimeout,
                );
            } catch (Throwable $error) {
                $failure = $error;
                if ($cancellation?->isCancelled() ?? false) {
                    throw $error;
                }
            }
        }
        throw $failure ?? new RuntimeException("Could not connect to {$host}:{$port}.");
    }

    /**
     * @param array<string, mixed> $tls
     */
    private function open(
        EventLoop $loop,
        string $address,
        int $port,
        float $deadline,
        ?Cancellation $cancellation,
        ?string $tlsHost,
        array $tls,
        int $maxWriteBufferSize,
        float $closeTimeout,
    ): Connection {
        $target = str_contains($address, ':') ? "tcp://[{$address}]:{$port}" : "tcp://{$address}:{$port}";
        $context = ['socket' => ['tcp_nodelay' => true]];
        if ($tlsHost !== null) {
            $context['ssl'] = [
                'peer_name' => $tlsHost,
                'SNI_enabled' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'alpn_protocols' => 'http/1.1',
                ...$tls,
            ];
        }
        $stream = @stream_socket_client(
            $target,
            $code,
            $reason,
            0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            stream_context_create($context),
        );
        if ($stream === false) {
            throw new RuntimeException("Could not connect to {$target}: {$reason}", $code);
        }
        stream_set_blocking($stream, false);
        try {
            $this->waitWritable($loop, $stream, $deadline, $cancellation, 'connecting');
            if (@stream_socket_get_name($stream, true) === false) {
                throw new RuntimeException("The connection to {$target} was refused.");
            }
            if ($tlsHost !== null) {
                $this->encrypt($loop, $stream, $deadline, $cancellation);
            }
            $remote = (string) (@stream_socket_get_name($stream, true) ?: $target);
            return new Connection($loop, $stream, $remote, $maxWriteBufferSize, $closeTimeout);
        } catch (Throwable $error) {
            @fclose($stream);
            throw $error;
        }
    }

    /**
     * @param resource $stream
     */
    private function waitWritable(
        EventLoop $loop,
        mixed $stream,
        float $deadline,
        ?Cancellation $cancellation,
        string $doing,
    ): void {
        $left = $deadline - (hrtime(true) / 1_000_000_000);
        if ($left <= 0) {
            throw new RuntimeException("Timed out {$doing}.");
        }
        Async::await(
            $loop,
            static function (\Closure $resume, \Closure $fail) use ($loop, $stream, $left, $doing): \Closure {
                $watcher = $loop->onWritable($stream, static function () use ($stream, $resume, $fail): void {
                    if (@stream_socket_get_name($stream, true) === false) {
                        $fail(new RuntimeException('The outbound connection was refused.'));
                        return;
                    }
                    $resume();
                });
                $timer = $loop->delay($left, static fn() => $fail(new RuntimeException("Timed out {$doing}.")));
                return static function () use ($loop, $watcher, $timer): void {
                    $loop->cancel($watcher);
                    $loop->cancel($timer);
                };
            },
            $cancellation,
        );
    }

    /**
     * @param resource $stream
     */
    private function encrypt(EventLoop $loop, mixed $stream, float $deadline, ?Cancellation $cancellation): void
    {
        while (true) {
            error_clear_last();
            $done = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($done === true) {
                return;
            }
            if ($done === false) {
                $message = error_get_last()['message'] ?? 'The TLS handshake failed.';
                throw new RuntimeException(trim((string) preg_replace(
                    '/^stream_socket_enable_crypto\(\):\s*/',
                    '',
                    $message,
                )));
            }
            $left = $deadline - (hrtime(true) / 1_000_000_000);
            if ($left <= 0) {
                throw new RuntimeException('Timed out agreeing on TLS.');
            }
            Async::await(
                $loop,
                static function (\Closure $resume, \Closure $fail) use ($loop, $stream, $left): \Closure {
                    $read = $loop->onReadable($stream, $resume);
                    $write = $loop->onWritable($stream, $resume);
                    $timer = $loop->delay($left, static fn() => $fail(
                        new RuntimeException('Timed out agreeing on TLS.'),
                    ));
                    return static function () use ($loop, $read, $write, $timer): void {
                        $loop->cancel($read);
                        $loop->cancel($write);
                        $loop->cancel($timer);
                    };
                },
                $cancellation,
            );
        }
    }
}
