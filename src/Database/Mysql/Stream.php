<?php

declare(strict_types=1);

namespace Crab\Database\Mysql;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\EventLoop;
use Crab\Engine\Network\DnsResolver;

/**
 * Owns a nonblocking stream and suspends while it connects, reads or writes.
 * Only connection setup has a deadline; commands install no timers.
 *
 * @internal
 */
final class Stream
{
    private const READ_SIZE = 65_536;
    private const YIELD_BYTES = 65_536;

    private mixed $stream = null;
    private string $buffer = '';
    private int $offset = 0;
    private int $readSinceYield = 0;
    private ?\Closure $waiting = null;
    private ?string $watcher = null;

    public function __construct(
        private readonly EventLoop $loop,
    ) {}

    public function open(Settings $settings, DnsResolver $resolver, float $deadline, ?Cancellation $cancellation): void
    {
        $token = new Cancellation();
        $unsubscribe = $cancellation?->subscribe(static fn(CancelledException $error) => $token->cancel($error));
        $timer = $this->loop->delay(max(0.000_001, $deadline - (hrtime(true) / 1e9)), static fn() => $token->cancel(
            new StreamFailure(
                'MySQL connection setup timed out; check "timeout" on the database connection and the server address.',
            ),
        ));
        try {
            $targets = $settings->socket !== null
                ? ['unix://' . $settings->socket]
                : array_map(
                    static fn(string $ip): string => (
                        'tcp://' . (str_contains($ip, ':') ? "[{$ip}]" : $ip) . ':' . $settings->port
                    ),
                    $resolver->resolve($this->loop, $settings->host, $token),
                );
            $failure = null;
            foreach ($targets as $target) {
                $options = ['socket' => ['tcp_nodelay' => true]];
                if ($settings->encrypted) {
                    if (!extension_loaded('openssl')) {
                        throw new StreamFailure(
                            'MySQL TLS requires PHP openssl; enable the extension before connecting.',
                        );
                    }
                    $options['ssl'] = [
                        'peer_name' => $settings->host,
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'SNI_enabled' => true,
                        ...$settings->ssl,
                    ];
                }
                $stream = @stream_socket_client(
                    $target,
                    $number,
                    $reason,
                    0,
                    STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
                    stream_context_create($options),
                );
                if ($stream === false) {
                    $failure = new StreamFailure('Cannot connect to MySQL: ' . $reason);
                    continue;
                }
                stream_set_blocking($stream, false);
                stream_set_read_buffer($stream, 0);
                stream_set_write_buffer($stream, 0);
                $this->stream = $stream;
                try {
                    $this->wait(true, $deadline, $token);
                    if (@stream_socket_get_name($stream, true) === false) {
                        throw new StreamFailure('MySQL refused the connection; check the server address and port.');
                    }
                    return;
                } catch (CancelledException $error) {
                    throw $error;
                } catch (StreamFailure $error) {
                    $failure = $error;
                    $this->close();
                }
            }
            throw $failure ?? new StreamFailure('No MySQL address could be connected to.');
        } catch (CancelledException $error) {
            $cancellation?->throwIfCancelled();
            throw new StreamFailure(
                'MySQL connection setup timed out; check "timeout" on the database connection and the server address.',
                previous: $error,
            );
        } finally {
            $this->loop->cancel($timer);
            $unsubscribe?->__invoke();
        }
    }

    public function encrypt(float $deadline, ?Cancellation $cancellation): void
    {
        while (true) {
            $this->checkDeadline($deadline);
            $cancellation?->throwIfCancelled();
            $done = @stream_socket_enable_crypto($this->resource(), true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $this->checkDeadline($deadline);
            if ($done === true) {
                return;
            }
            if ($done === false) {
                throw new StreamFailure(
                    'MySQL TLS negotiation failed; check the server certificate and options["ssl"] on the database connection.',
                );
            }
            $this->wait(false, $deadline, $cancellation);
        }
    }

    public function read(int $length, float $deadline = INF, ?Cancellation $cancellation = null): string
    {
        $this->checkDeadline($deadline);
        $cancellation?->throwIfCancelled();
        while ((strlen($this->buffer) - $this->offset) < $length) {
            $this->checkDeadline($deadline);
            $stream = $this->resource();
            $bytes = @fread($stream, self::READ_SIZE);
            if ($bytes === false || $bytes === '' && feof($stream)) {
                throw new StreamFailure('MySQL closed the connection while reading its answer.');
            }
            if ($bytes === '') {
                $this->wait(false, $deadline, $cancellation);
                continue;
            }
            if ($this->offset > 0) {
                $this->buffer = substr($this->buffer, $this->offset);
                $this->offset = 0;
            }
            $this->buffer .= $bytes;
            // Decrypted bytes may already be buffered by OpenSSL without socket
            // readiness. A deferred turn keeps those large reads fair as well.
            $this->readSinceYield += strlen($bytes);
            if ($this->readSinceYield >= self::YIELD_BYTES) {
                $this->readSinceYield = 0;
                $this->yieldRead();
            }
        }
        $this->checkDeadline($deadline);
        $cancellation?->throwIfCancelled();
        $bytes = substr($this->buffer, $this->offset, $length);
        $this->offset += $length;
        if ($this->offset === strlen($this->buffer)) {
            $this->buffer = '';
            $this->offset = 0;
        }
        return $bytes;
    }

    /**
     * Socket waits already yield. Buffered TLS/large results additionally yield
     * once per byte budget, without scheduling another turn for every small read.
     */
    private function yieldRead(): void
    {
        $suspension = $this->loop->getSuspension();
        $deferred = $this->loop->defer(static fn() => $suspension->resume());
        try {
            $suspension->suspend();
        } finally {
            $this->loop->cancel($deferred);
        }
    }

    public function write(string $bytes, bool &$sent, float $deadline = INF, ?Cancellation $cancellation = null): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $this->checkDeadline($deadline);
            $cancellation?->throwIfCancelled();
            $stream = $this->resource();
            $written = @fwrite($stream, substr($bytes, $offset, 65_536));
            if ($written === false || $written === 0 && feof($stream)) {
                throw new StreamFailure('MySQL lost the connection while sending the command.');
            }
            if ($written === 0) {
                $this->wait(true, $deadline, $cancellation);
            } else {
                $sent = true;
                $offset += $written;
            }
        }
        $this->checkDeadline($deadline);
    }

    /**
     * A kept socket is checked once before a command, never polled.
     * This consumes one byte if unexpected idle data exists; false requires
     * discarding the session, so that consumed byte can never be reused.
     */
    public function isAlive(): bool
    {
        if ($this->stream === null || strlen($this->buffer) !== $this->offset) {
            return false;
        }
        $bytes = @fread($this->stream, 1);
        return $bytes === '' && !feof($this->stream);
    }

    public function close(): void
    {
        if ($this->stream === null) {
            return;
        }
        if ($this->watcher !== null) {
            $this->loop->cancel($this->watcher);
        }
        $this->waiting?->__invoke(new StreamFailure('MySQL connection was closed.'));
        @fclose($this->stream);
        $this->stream = null;
        $this->buffer = '';
        $this->offset = 0;
        $this->readSinceYield = 0;
    }

    private function resource(): mixed
    {
        return $this->stream ?? throw new StreamFailure('MySQL connection is closed.');
    }

    /**
     * Progress and already buffered packets do not extend connection setup.
     * Query calls pass INF, without a clock read or a timer.
     */
    private function checkDeadline(float $deadline): void
    {
        if ($deadline !== INF && (hrtime(true) / 1e9) >= $deadline) {
            throw new StreamFailure(
                'MySQL connection setup timed out; check "timeout" on the database connection and the server address.',
            );
        }
    }

    private function wait(bool $writable, float $deadline, ?Cancellation $cancellation): void
    {
        $cancellation?->throwIfCancelled();
        $left = is_infinite($deadline) ? null : $deadline - (hrtime(true) / 1e9);
        if ($left !== null && $left <= 0) {
            throw new StreamFailure(
                'MySQL connection setup timed out; increase "timeout" on the database connection or check the server.',
            );
        }
        $suspension = $this->loop->getSuspension();
        $settled = false;
        $finish = static function (?\Throwable $error = null) use ($suspension, &$settled): void {
            if ($settled) {
                return;
            }
            $settled = true;
            if ($error === null) {
                $suspension->resume();
            } else {
                $suspension->throw($error);
            }
        };
        if ($writable) {
            $watcher = $this->loop->onWritable($this->resource(), static fn() => $finish());
        } else {
            $watcher = $this->loop->onReadable($this->resource(), static fn() => $finish());
        }
        $this->waiting = $finish;
        $this->watcher = $watcher;
        $timer = null;
        if ($left !== null) {
            $timer = $this->loop->delay($left, static fn() => $finish(
                new StreamFailure(
                    'MySQL connection setup timed out; increase "timeout" on the database connection or check the server.',
                ),
            ));
        }
        $unsubscribe = $cancellation?->subscribe(static fn(CancelledException $error) => $finish($error));
        try {
            $suspension->suspend();
        } finally {
            $settled = true;
            $this->waiting = null;
            $this->watcher = null;
            $this->loop->cancel($watcher);
            if ($timer !== null) {
                $this->loop->cancel($timer);
            }
            $unsubscribe?->__invoke();
        }
    }
}
