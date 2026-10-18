<?php

declare(strict_types=1);

namespace Crab\Tests\HttpClient;

use Crab\Engine\EventLoop;

/**
 * A scripted HTTP server on the test's own event loop: it reads whole
 * requests and writes back, byte for byte, what the test tells it to.
 */
final class TestServer
{
    public readonly int $port;
    /**
     * Every request received: its method, target, headers by lowercase name, body, and which connection it came on.
     *
     * @var list<array{method: string, target: string, headers: array<string, string>, body: string, connection: int}>
     */
    public array $requests = [];
    /**
     * Bytes received per connection, including an unfinished request.
     *
     * @var array<int, int>
     */
    public array $bytes = [];
    public int $connections = 0;
    /**
     * @var resource
     */
    private mixed $socket;
    /**
     * @var array<int, resource>
     */
    private array $open = [];
    /**
     * The watchers of each open connection, cancelled when it is closed.
     *
     * @var array<int, list<string>>
     */
    private array $watching = [];
    private string $accepting;

    /**
     * @param \Closure(array, self): (string|array{string, float}|null) $reply The bytes to write back, with a number of seconds to wait first when given as a pair; null to close without a word. A reply holding "Connection: close" closes after it.
     * @param string|null $certificate A PEM file holding a certificate and its key, to serve over TLS.
     * @param float $handshakeDelay Seconds to wait before starting TLS, to test deadlines while a connection is being opened.
     */
    public function __construct(
        private readonly \Closure $reply,
        ?string $certificate = null,
        float $handshakeDelay = 0.0,
    ) {
        $context = stream_context_create($certificate === null ? [] : ['ssl' => ['local_cert' => $certificate]]);
        $this->socket = stream_socket_server(
            'tcp://127.0.0.1:0',
            $code,
            $reason,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );
        stream_set_blocking($this->socket, false);
        $this->port = (int) substr(strrchr(stream_socket_get_name($this->socket, false), ':'), 1);
        $loop = EventLoop::current();
        $this->accepting = $loop->onReadable($this->socket, function () use (
            $loop,
            $certificate,
            $handshakeDelay,
        ): void {
            $connection = @stream_socket_accept($this->socket, 0);
            if ($connection === false) {
                return;
            }
            stream_set_blocking($connection, false);
            $number = ++$this->connections;
            $this->open[$number] = $connection;
            $buffer = '';
            $encrypted = $certificate === null;
            $reader = $loop->onReadable($connection, function (string $watcher) use (
                $loop,
                $connection,
                $number,
                &$buffer,
                &$encrypted,
            ): void {
                if (!$encrypted) {
                    $done = @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
                    if ($done === false) {
                        $this->drop($number);
                        return;
                    }
                    $encrypted = $done === true;
                    if (!$encrypted) {
                        return;
                    }
                }
                $bytes = @fread($connection, 65_536);
                if ($bytes === '' || $bytes === false) {
                    if ($bytes === false || feof($connection)) {
                        $this->drop($number);
                    }
                    return;
                }
                $this->bytes[$number] = ($this->bytes[$number] ?? 0) + strlen($bytes);
                $buffer .= $bytes;
                while (($request = self::take($buffer)) !== null) {
                    $request['connection'] = $number;
                    $this->requests[] = $request;
                    $reply = ($this->reply)($request, $this);
                    if ($reply === null) {
                        $this->drop($number);
                        return;
                    }
                    [$bytes, $after] = is_array($reply) ? $reply : [$reply, 0.0];
                    $write = function () use ($loop, $connection, $number, $bytes): void {
                        if (!isset($this->open[$number])) {
                            return;
                        }
                        $close = stripos($bytes, 'Connection: close') !== false;
                        // Written as the client takes it: both ends are on one event loop.
                        $this->watching[$number][] = $loop->onWritable($connection, function (string $writer) use (
                            $loop,
                            $connection,
                            $number,
                            $close,
                            &$bytes,
                        ): void {
                            $written = (int) @fwrite($connection, $bytes);
                            $bytes = substr($bytes, $written);
                            if ($bytes !== '') {
                                return;
                            }
                            $loop->cancel($writer);
                            if ($close) {
                                $this->drop($number);
                            }
                        });
                    };
                    $after > 0 ? $loop->delay($after, $write) : $write();
                }
            });
            $this->watching[$number][] = $reader;
            if ($certificate !== null && $handshakeDelay > 0) {
                $loop->disable($reader);
                $this->watching[$number][] = $loop->delay($handshakeDelay, function () use (
                    $loop,
                    $number,
                    $reader,
                ): void {
                    if (isset($this->open[$number])) {
                        $loop->enable($reader);
                    }
                });
            }
        });
    }

    public function url(string $path = '/', string $scheme = 'http', string $host = '127.0.0.1'): string
    {
        return "{$scheme}://{$host}:{$this->port}{$path}";
    }

    /**
     * Closes every connection that is open, as a server does to ones left idle.
     */
    public function dropConnections(): void
    {
        foreach (array_keys($this->open) as $number) {
            $this->drop($number);
        }
    }

    public function close(): void
    {
        EventLoop::current()->cancel($this->accepting);
        $this->dropConnections();
        @fclose($this->socket);
    }

    /**
     * A reply with a body and the headers that frame it.
     *
     * @param array<string, string> $headers
     */
    public static function reply(int $status, string $body = '', array $headers = []): string
    {
        $head = "HTTP/1.1 {$status} Reply\r\n";
        foreach (['Content-Length' => (string) strlen($body), ...$headers] as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }
        return $head . "\r\n" . $body;
    }

    /**
     * A certificate signed by itself, with its key, in one file. Whoever asks for it removes the file.
     */
    public static function certificate(string $name): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $request = openssl_csr_new(['commonName' => $name], $key, ['digest_alg' => 'sha256']);
        $signed = openssl_csr_sign($request, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($signed, $certificate);
        openssl_pkey_export($key, $private);
        $file = sys_get_temp_dir() . '/crab-cert-' . bin2hex(random_bytes(6)) . '.pem';
        file_put_contents($file, $certificate . $private);
        return $file;
    }

    private function drop(int $number): void
    {
        if (isset($this->open[$number])) {
            foreach ($this->watching[$number] ?? [] as $watcher) {
                EventLoop::current()->cancel($watcher);
            }
            @fclose($this->open[$number]);
            unset($this->open[$number], $this->watching[$number]);
        }
    }

    /**
     * Takes one whole request off the front of what has arrived, when one is there.
     *
     * @return array{method: string, target: string, headers: array<string, string>, body: string}|null
     */
    private static function take(string &$buffer): ?array
    {
        $end = strpos($buffer, "\r\n\r\n");
        if ($end === false) {
            return null;
        }
        $lines = explode("\r\n", substr($buffer, 0, $end));
        [$method, $target] = explode(' ', array_shift($lines));
        $headers = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower($name)] = trim($value);
        }
        $start = $end + 4;
        if (($headers['transfer-encoding'] ?? '') === 'chunked') {
            $body = '';
            $position = $start;
            while (true) {
                $line = strpos($buffer, "\r\n", $position);
                if ($line === false) {
                    return null;
                }
                $size = (int) hexdec(substr($buffer, $position, $line - $position));
                if (strlen($buffer) < ($line + 2 + $size + 2)) {
                    return null;
                }
                $body .= substr($buffer, $line + 2, $size);
                $position = $line + 2 + $size + 2;
                if ($size === 0) {
                    break;
                }
            }
            $buffer = substr($buffer, $position);
        } else {
            $length = (int) ($headers['content-length'] ?? 0);
            if (strlen($buffer) < ($start + $length)) {
                return null;
            }
            $body = substr($buffer, $start, $length);
            $buffer = substr($buffer, $start + $length);
        }
        return ['method' => $method, 'target' => $target, 'headers' => $headers, 'body' => $body];
    }
}
