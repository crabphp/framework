<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\EventLoop;

/**
 * One connection to another server, plain or encrypted. Whoever reads or
 * writes is suspended while the socket is not ready, and the worker goes
 * on with other work. It is used by one request at a time.
 *
 * @internal
 */
final class Connection
{
    /**
     * Whether a request has been answered on it before.
     */
    public bool $used = false;
    /**
     * When it was last given back, on the clock hrtime() reads, in seconds.
     */
    public float $idleSince = 0.0;
    /**
     * What the two ends agreed to speak when they agreed on encryption: "h2" for HTTP/2.
     */
    public string $protocol = 'http/1.1';
    /**
     * Whether it leads to a proxy that forwards requests, which must then name the whole URL.
     */
    public bool $forwarded = false;
    /**
     * What proves to that proxy who is asking, when it wants to know.
     */
    public ?string $proxyAuthorization = null;
    /**
     * @var resource|null
     */
    private mixed $stream;
    /**
     * The waits on the socket that are under way: the watcher of each and what ends it.
     *
     * @var array<int, array{string, \Closure}>
     */
    private array $waits = [];
    private int $nextWait = 0;

    /**
     * @param resource $stream
     */
    private function __construct(
        private readonly EventLoop $loop,
        mixed $stream,
    ) {
        $this->stream = $stream;
    }

    /**
     * Connects, and with a host to prove, agrees on encryption with it.
     *
     * @param string $address A numeric address: "203.0.113.5" or "2001:db8::5".
     * @param string|null $tlsHost The name the server must hold a certificate for; null for a connection that is not encrypted.
     * @param array<string, mixed> $tls PHP's "ssl" context options, added to the defaults.
     * @param float $deadline When to give up, on the clock hrtime() reads, in seconds.
     * @param bool $encrypt Whether to agree on encryption at once. Not when a proxy is first asked for a tunnel: encrypt() is then called once it has made one.
     * @throws \RuntimeException Saying why it could not be opened.
     */
    public static function open(
        EventLoop $loop,
        string $address,
        int $port,
        ?string $tlsHost,
        array $tls,
        float $deadline,
        ?Cancellation $cancellation = null,
        bool $encrypt = true,
    ): self {
        $target = str_contains($address, ':') ? "tcp://[{$address}]:{$port}" : "tcp://{$address}:{$port}";
        $options = ['socket' => ['tcp_nodelay' => true]];
        if ($tlsHost !== null) {
            $options['ssl'] = [
                'peer_name' => $tlsHost,
                'SNI_enabled' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
                // What is offered to the server to speak; HTTP/1.1 alone unless the caller says more.
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
            stream_context_create($options),
        );
        if ($stream === false) {
            throw new \RuntimeException("cannot connect: {$reason}");
        }
        stream_set_blocking($stream, false);
        $connection = new self($loop, $stream);
        try {
            $connection->wait(true, $deadline, $cancellation, 'connecting');
            // Being writable also follows a refusal; only a connected socket knows its peer.
            if (@stream_socket_get_name($stream, true) === false) {
                throw new \RuntimeException('the connection was refused');
            }
            if ($tlsHost !== null && $encrypt) {
                $connection->encrypt($deadline, $cancellation);
            }
        } catch (\Throwable $error) {
            $connection->close();
            throw $error;
        }
        return $connection;
    }

    /**
     * Some bytes, as many as have arrived, or null when the server has
     * closed the connection.
     */
    public function read(float $deadline, ?Cancellation $cancellation = null, bool $background = false): ?string
    {
        while (true) {
            $bytes = @fread($this->stream(), 65_536);
            if ($bytes !== false && $bytes !== '') {
                return $bytes;
            }
            if ($bytes === false || $this->stream === null || feof($this->stream)) {
                return null;
            }
            $this->wait(false, $deadline, $cancellation, 'waiting for the answer', $background);
        }
    }

    /**
     * Writes while time remains, recording the first byte so an exchange
     * can distinguish a request never sent from one partly sent.
     */
    public function write(string $bytes, float $deadline, ?Cancellation $cancellation = null, bool &$sent = false): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            if ((hrtime(true) / 1_000_000_000) >= $deadline) {
                throw new TimedOut('sending');
            }
            $written = @fwrite($this->stream(), $offset === 0 ? $bytes : substr($bytes, $offset));
            if ($written === false) {
                throw new \RuntimeException('the connection was lost while sending');
            }
            if ($written === 0) {
                if (feof($this->stream)) {
                    throw new \RuntimeException('the connection was lost while sending');
                }
                $this->wait(true, $deadline, $cancellation, 'sending');
            }
            if ($written > 0) {
                $sent = true;
            }
            $offset += $written;
        }
    }

    /**
     * Whether a connection that was set aside can carry another request:
     * the server has neither closed it nor sent anything unasked.
     */
    public function isReusable(): bool
    {
        if ($this->stream === null) {
            return false;
        }
        $bytes = @fread($this->stream, 1);
        return $bytes === '' && !feof($this->stream);
    }

    /**
     * Closes the socket. Whoever is waiting on it is resumed with a failure.
     */
    public function close(): void
    {
        if ($this->stream === null) {
            return;
        }
        $waits = $this->waits;
        $this->waits = [];
        foreach ($waits as [$watcher, $end]) {
            // Before the socket goes: a loop must not be left watching one that is closed.
            $this->loop->cancel($watcher);
            $end(new \RuntimeException('the connection was closed'));
        }
        @fclose($this->stream);
        $this->stream = null;
    }

    /**
     * @return resource
     */
    private function stream(): mixed
    {
        return $this->stream ?? throw new \RuntimeException('the connection is closed');
    }

    /**
     * Agrees on encryption with the server the connection was opened for.
     */
    public function encrypt(float $deadline, ?Cancellation $cancellation = null): void
    {
        while (true) {
            error_clear_last();
            $done = @stream_socket_enable_crypto($this->stream(), true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($done === true) {
                $this->protocol = (string) (
                    stream_get_meta_data($this->stream())['crypto']['alpn_protocol'] ?? 'http/1.1'
                );
                return;
            }
            if ($done === false) {
                $why = error_get_last()['message'] ?? 'the handshake failed';
                // PHP's message starts with the name of its own function.
                $why = trim((string) preg_replace('/^stream_socket_enable_crypto\(\):\s*/', '', $why));
                throw new \RuntimeException("the encrypted connection could not be made: {$why}");
            }
            // Neither done nor failed: the handshake waits for the server's next message.
            $this->wait(false, $deadline, $cancellation, 'agreeing on encryption');
        }
    }

    /**
     * Suspends until the socket can be read or written, the deadline
     * passes or the wait is cancelled.
     *
     * @param float $deadline INF to wait for as long as it takes.
     * @param bool $background Whether the wait is one nobody is waiting on, which must not keep an idle process alive: a connection listening for what the server may say unasked.
     */
    private function wait(
        bool $writable,
        float $deadline,
        ?Cancellation $cancellation,
        string $doing,
        bool $background = false,
    ): void {
        $cancellation?->throwIfCancelled();
        $left = is_infinite($deadline) ? null : $deadline - (hrtime(true) / 1_000_000_000);
        if ($left !== null && $left <= 0) {
            throw new TimedOut($doing);
        }
        $loop = $this->loop;
        $suspension = $loop->getSuspension();
        $settled = false;
        $finish = static function (?\Throwable $error = null) use ($suspension, &$settled): void {
            if ($settled) {
                return;
            }
            $settled = true;
            if ($error !== null) {
                $suspension->throw($error);
            } else {
                $suspension->resume();
            }
        };
        $stream = $this->stream();
        $watcher = $writable
            ? $loop->onWritable($stream, static fn() => $finish())
            : $loop->onReadable($stream, static fn() => $finish());
        if ($background) {
            $loop->unreference($watcher);
        }
        $wait = $this->nextWait++;
        $this->waits[$wait] = [$watcher, $finish];
        $timer = $left === null ? null : $loop->delay($left, static fn() => $finish(new TimedOut($doing)));
        $unsubscribe = $cancellation?->subscribe(static fn(CancelledException $error) => $finish($error));
        try {
            $suspension->suspend();
        } finally {
            $settled = true;
            unset($this->waits[$wait]);
            $loop->cancel($watcher);
            if ($timer !== null) {
                $loop->cancel($timer);
            }
            $unsubscribe?->__invoke();
        }
    }
}
