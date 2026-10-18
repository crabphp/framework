<?php

declare(strict_types=1);

namespace Crab\Tests\HttpClient;

use Crab\Engine\EventLoop;

/**
 * A proxy that makes tunnels, on the test's own event loop: asked to
 * CONNECT, it connects there and passes bytes both ways unread.
 */
final class TestTunnel
{
    public readonly int $port;
    /**
     * What each client asked: the CONNECT line and its headers by lowercase name.
     *
     * @var list<array{line: string, headers: array<string, string>}>
     */
    public array $asked = [];
    /**
     * @var resource
     */
    private mixed $socket;
    /**
     * @var list<string>
     */
    private array $watchers = [];
    /**
     * @var list<resource>
     */
    private array $open = [];

    /**
     * @param string|null $refuseWith A status line to answer every CONNECT with in place of making the tunnel.
     */
    public function __construct(
        private readonly ?string $refuseWith = null,
    ) {
        $this->socket = stream_socket_server('tcp://127.0.0.1:0');
        stream_set_blocking($this->socket, false);
        $this->port = (int) substr(strrchr(stream_socket_get_name($this->socket, false), ':'), 1);
        $loop = EventLoop::current();
        $this->watchers[] = $loop->onReadable($this->socket, function () use ($loop): void {
            $client = @stream_socket_accept($this->socket, 0);
            if ($client === false) {
                return;
            }
            stream_set_blocking($client, false);
            $this->open[] = $client;
            $buffer = '';
            $target = null;
            $this->watchers[] = $loop->onReadable($client, function (string $watcher) use (
                $loop,
                $client,
                &$buffer,
                &$target,
            ): void {
                $bytes = @fread($client, 65_536);
                if ($bytes === '' || $bytes === false) {
                    if ($bytes === false || feof($client)) {
                        $loop->cancel($watcher);
                    }
                    return;
                }
                if ($target !== null) {
                    @fwrite($target, $bytes);
                    return;
                }
                $buffer .= $bytes;
                $end = strpos($buffer, "\r\n\r\n");
                if ($end === false) {
                    return;
                }
                $lines = explode("\r\n", substr($buffer, 0, $end));
                $line = array_shift($lines);
                $headers = [];
                foreach ($lines as $header) {
                    [$name, $value] = explode(':', $header, 2);
                    $headers[strtolower($name)] = trim($value);
                }
                $this->asked[] = ['line' => $line, 'headers' => $headers];
                if ($this->refuseWith !== null) {
                    @fwrite($client, "{$this->refuseWith}\r\nContent-Length: 0\r\n\r\n");
                    return;
                }
                $target = stream_socket_client('tcp://' . explode(' ', $line)[1]);
                stream_set_blocking($target, false);
                $this->open[] = $target;
                $this->watchers[] = $loop->onReadable($target, static function (string $back) use (
                    $loop,
                    $client,
                    $target,
                ): void {
                    $bytes = @fread($target, 65_536);
                    if ($bytes === '' || $bytes === false) {
                        if ($bytes === false || feof($target)) {
                            $loop->cancel($back);
                        }
                        return;
                    }
                    @fwrite($client, $bytes);
                });
                @fwrite($client, "HTTP/1.1 200 Connection established\r\n\r\n");
            });
        });
    }

    public function url(string $credentials = ''): string
    {
        return 'http://' . ($credentials === '' ? '' : $credentials . '@') . "127.0.0.1:{$this->port}";
    }

    public function close(): void
    {
        foreach ($this->watchers as $watcher) {
            EventLoop::current()->cancel($watcher);
        }
        foreach ($this->open as $stream) {
            @fclose($stream);
        }
        @fclose($this->socket);
    }
}
