<?php

declare(strict_types=1);

/**
 * Diagnostic HTTP fixture, not a general-purpose HTTP server.
 * All variants share bounded framing and backpressure. The codec variant adds
 * Crab's parser/encoder after the common boundary scan, without HttpSession.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Crab\Engine\Http\HttpException;
use Crab\Engine\Http\Parser;
use Crab\Engine\Http\Request;
use Crab\Engine\Http\Response;
use Crab\Engine\Http\ResponseEncoder;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\Listener;
use Crab\Engine\Network\WriteResult;
use Revolt\EventLoop\Driver\StreamSelectDriver;

final class BenchmarkHttp
{
    private string $input = '';
    private string $pending = '';
    private int $scan = 0;
    private bool $ending = false;
    private bool $closed = false;
    private bool $eof = false;
    private bool $pumping = false;
    private ?Parser $parser;
    private static ?Response $response = null;

    public function __construct(
        private readonly Closure $send,
        private readonly Closure $pause,
        private readonly Closure $finish,
        private readonly Closure $handler,
        bool $codec = false,
    ) {
        $this->parser = $codec ? new Parser() : null;
        if ($codec) {
            self::$response ??= Response::text("Hello from Crab\n");
        }
    }

    public function receive(string $bytes): void
    {
        if ($this->closed || $this->ending) {
            return;
        }
        if (strlen($this->input) + strlen($bytes) > 131072) {
            $this->reject(431);
        } else {
            $this->input .= $bytes;
        }
        $this->pump();
    }

    public function eof(): void
    {
        $this->eof = true;
        $this->pump();
    }

    public function drain(): void
    {
        $this->ending = true;
        $this->input = '';
        $this->pump();
    }

    public function dispose(): void
    {
        $this->closed = true;
        $this->input = $this->pending = '';
    }

    public function pump(): void
    {
        if ($this->pumping || $this->closed) {
            return;
        }
        $this->pumping = true;
        try {
            while (!$this->closed) {
                if ($this->pending !== '') {
                    if (!(($this->send)($this->pending))) {
                        ($this->pause)(true);
                        return;
                    }
                    $this->pending = '';
                }
                if ($this->ending) {
                    $this->closed = true;
                    $this->input = '';
                    ($this->finish)();
                    return;
                }
                $end = strpos($this->input, "\r\n\r\n", $this->scan);
                if ($end === false) {
                    $this->scan = max(0, strlen($this->input) - 3);
                    if (strlen($this->input) > 16384) {
                        $this->reject(431);
                        continue;
                    }
                    if ($this->eof) {
                        if ($this->input !== '') {
                            $this->reject(400);
                        } else {
                            $this->ending = true;
                        }
                        continue;
                    }
                    ($this->pause)(false);
                    return;
                }
                if ($end + 4 > 16384) {
                    $this->reject(431);
                    continue;
                }
                $head = substr($this->input, 0, $end);
                $this->input = substr($this->input, $end + 4);
                $this->scan = 0;
                [$status, $close, $request] = $this->decode($head);
                if ($status !== 200) {
                    $this->reject($status);
                    continue;
                }
                $body = ($this->handler)();
                if ($request !== null) {
                    $encoder = new ResponseEncoder(self::$response, $request);
                    $close = $encoder->close;
                    foreach ($encoder->bytes() as $chunk) {
                        $this->pending .= $chunk;
                    }
                    $request->cancellation->cancel();
                } else {
                    $this->pending = self::encode(200, $body, $close);
                }
                if ($close) {
                    $this->ending = true;
                    $this->input = '';
                }
            }
        } finally {
            $this->pumping = false;
        }
    }

    /**
     * @return array{int, bool, ?Request}
     */
    private function decode(string $head): array
    {
        if ($this->parser !== null) {
            try {
                $this->parser->append($head . "\r\n\r\n");
                $request = $this->parser->next();
            } catch (HttpException $error) {
                return [$error->status, true, null];
            }
            if ($request === null || $request->header('transfer-encoding') !== null) {
                return [501, true, null];
            }
            $status = $request->method !== 'GET' ? 405 : ($request->target !== '/' ? 404 : 200);
            return [$status, false, $request];
        }
        $lines = explode("\r\n", $head);
        $line = array_shift($lines);
        if (!preg_match('/^([A-Z]+) ([^\x00-\x20\x7f]+) HTTP\/1\.1$/D', $line, $match)) {
            return [400, true, null];
        }
        $hosts = 0;
        $length = null;
        $transfer = false;
        $close = false;
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                return [400, true, null];
            }
            $name = strtolower(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1), " \t");
            if (!preg_match('/^[!#$%&\x27*+.^_`|~0-9a-z-]+$/D', $name)
                || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value)) {
                return [400, true, null];
            }
            if ($name === 'host') {
                ++$hosts;
                if ($value === '') {
                    return [400, true, null];
                }
            } elseif ($name === 'content-length') {
                if ($length !== null || !preg_match('/^[0-9]+$/D', $value)) {
                    return [400, true, null];
                }
                $length = ltrim($value, '0');
            } elseif ($name === 'transfer-encoding') {
                $transfer = true;
            } elseif ($name === 'connection') {
                $close = $close || in_array('close', array_map(trim(...), explode(',', strtolower($value))), true);
            }
        }
        if ($hosts !== 1) {
            return [400, true, null];
        }
        $status = match (true) {
            $match[1] !== 'GET' => 405,
            $match[2] !== '/' => 404,
            $transfer || ($length !== null && $length !== '') => 501,
            default => 200,
        };
        return [$status, $close, null];
    }

    private function reject(int $status): void
    {
        $this->pending = self::encode($status, Response::reason($status) . "\n", true);
        $this->input = '';
        $this->ending = true;
    }

    private static function encode(int $status, string $body, bool $close): string
    {
        static $second = -1;
        static $date = '';
        $now = time();
        if ($now !== $second) {
            $second = $now;
            $date = gmdate('D, d M Y H:i:s', $now) . ' GMT';
        }
        return 'HTTP/1.1 ' . $status . ' ' . Response::reason($status) . "\r\n"
            . "content-type: text/plain; charset=utf-8\r\ncontent-length: " . strlen($body)
            . "\r\nconnection: " . ($close ? 'close' : 'keep-alive') . "\r\ndate: " . $date . "\r\n\r\n" . $body;
    }
}

final class BenchmarkSocket
{
    public readonly BenchmarkHttp $http;
    public bool $closed = false;
    private bool $paused = false;
    private bool $eof = false;
    private bool $ending = false;
    private string $output = '';
    private int $offset = 0;
    private ?string $reader = null;
    private ?string $writer = null;

    /**
     * @param resource $stream
     */
    public function __construct(
        public mixed $stream,
        private readonly Closure $onClose,
        Closure $handler,
        private readonly ?StreamSelectDriver $loop = null,
    ) {
        stream_set_blocking($stream, false);
        stream_set_read_buffer($stream, 0);
        stream_set_write_buffer($stream, 0);
        stream_set_chunk_size($stream, 65536);
        $this->http = new BenchmarkHttp($this->send(...), $this->pause(...), $this->end(...), $handler);
        $this->sync();
    }

    public function wantsRead(): bool { return !$this->closed && !$this->paused && !$this->eof && !$this->ending; }
    public function wantsWrite(): bool { return !$this->closed && $this->output !== ''; }

    public function read(): void
    {
        if (!$this->wantsRead()) {
            return;
        }
        $bytes = @fread($this->stream, 65536);
        if ($bytes === false) {
            $this->close();
        } elseif ($bytes !== '') {
            $this->http->receive($bytes);
        } elseif (feof($this->stream)) {
            $this->eof = true;
            $this->http->eof();
            $this->sync();
        }
    }

    public function send(string $bytes): bool
    {
        if ($this->closed || strlen($this->output) - $this->offset + strlen($bytes) > 1048576) {
            return false;
        }
        if ($this->output === '') {
            $written = @fwrite($this->stream, $bytes);
            if ($written === false) {
                $this->close();
                return false;
            }
            if ($written === strlen($bytes)) {
                return true;
            }
            $bytes = substr($bytes, $written);
        }
        if ($this->offset !== 0) {
            $this->output = substr($this->output, $this->offset);
            $this->offset = 0;
        }
        $this->output .= $bytes;
        $this->sync();
        return true;
    }

    public function flush(): void
    {
        if (!$this->wantsWrite()) {
            return;
        }
        $written = @fwrite($this->stream, substr($this->output, $this->offset), 262144);
        if ($written === false) {
            $this->close();
            return;
        }
        $this->offset += $written;
        if ($this->offset === strlen($this->output)) {
            $this->output = '';
            $this->offset = 0;
            if ($this->ending) {
                $this->close();
                return;
            }
        }
        $this->sync();
        if ($written > 0) {
            $this->http->pump();
        }
    }

    public function pause(bool $paused): void
    {
        $this->paused = $paused;
        $this->sync();
    }

    public function end(): void
    {
        $this->ending = true;
        if ($this->output === '') {
            $this->close();
        } else {
            $this->sync();
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->http->dispose();
        foreach ([$this->reader, $this->writer] as $id) {
            if ($id !== null) {
                $this->loop->cancel($id);
            }
        }
        fclose($this->stream);
        $this->stream = null;
        $this->output = '';
        ($this->onClose)();
    }

    private function sync(): void
    {
        if ($this->loop === null || $this->closed) {
            return;
        }
        if ($this->wantsRead()) {
            if ($this->reader === null) {
                $this->reader = $this->loop->onReadable($this->stream, $this->read(...));
            } else {
                $this->loop->enable($this->reader);
            }
        } elseif ($this->reader !== null) {
            $this->loop->disable($this->reader);
        }
        if ($this->wantsWrite() && $this->writer === null) {
            $this->writer = $this->loop->onWritable($this->stream, $this->flush(...));
        } elseif (!$this->wantsWrite() && $this->writer !== null) {
            $this->loop->cancel($this->writer);
            $this->writer = null;
        }
    }
}

$options = getopt('', ['backend:', 'protocol:', 'host:', 'port:']);
$backend = $options['backend'] ?? 'select';
$host = $options['host'] ?? '127.0.0.1';
$port = filter_var($options['port'] ?? 8080, FILTER_VALIDATE_INT);
$loopback = $host === '::1' || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && str_starts_with($host, '127.'));
if (!in_array($backend, ['select', 'revolt', 'transport', 'codec'], true)
    || ($options['protocol'] ?? 'http') !== 'http' || !$loopback || $port < 1 || $port > 65535) {
    fwrite(STDERR, "Usage: php php.php --backend=select|revolt|transport|codec --protocol=http --host=127.0.0.1 --port=8080\n");
    exit(2);
}
$address = 'tcp://' . ($host === '::1' ? '[::1]' : $host) . ':' . $port;
$server = @stream_socket_server($address, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    stream_context_create(['socket' => ['backlog' => 1024, 'tcp_nodelay' => true]]));
if ($server === false) {
    fwrite(STDERR, "Bind failed: {$error}\n");
    exit(1);
}
stream_set_blocking($server, false);
$handler = static fn(): string => "Hello from Crab\n";
$peers = [];
$stopping = false;
$loop = $backend === 'select' ? null : new StreamSelectDriver();
$listener = null;

$acceptRaw = static function () use ($server, $loop, $handler, &$peers, &$stopping): void {
    for ($i = 0; $i < 64 && !$stopping; ++$i) {
        $stream = @stream_socket_accept($server, 0);
        if ($stream === false) {
            return;
        }
        if (count($peers) >= 512) {
            fclose($stream);
            continue;
        }
        $id = (int) $stream;
        $peers[$id] = new BenchmarkSocket($stream, static function () use ($id, $loop, &$peers, &$stopping): void {
            unset($peers[$id]);
            if ($stopping && $peers === []) {
                $loop?->stop();
            }
        }, $handler, $loop);
    }
};

try {
    if ($loop === null) {
        $stopRequested = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $signal = static function () use (&$stopRequested): void { $stopRequested = true; };
            pcntl_signal(SIGTERM, $signal);
            pcntl_signal(SIGINT, $signal);
        }
        $stopAt = INF;
        while (!$stopping || $peers !== []) {
            if ($stopRequested && !$stopping) {
                $stopping = true;
                $stopAt = hrtime(true) / 1e9 + 2;
                fclose($server);
                foreach ($peers as $peer) { $peer->http->drain(); }
            }
            if ($stopping && hrtime(true) / 1e9 >= $stopAt) {
                foreach ($peers as $peer) { $peer->close(); }
                break;
            }
            $read = $stopping ? [] : [$server];
            $write = [];
            foreach ($peers as $peer) {
                if ($peer->wantsRead()) { $read[] = $peer->stream; }
                if ($peer->wantsWrite()) { $write[] = $peer->stream; }
            }
            if ($read === [] && $write === []) {
                break;
            }
            $except = [];
            if (@stream_select($read, $write, $except, 0, 100000) === false) {
                continue;
            }
            foreach ($read as $stream) {
                if ($stream === $server && !$stopping) {
                    $acceptRaw();
                } else {
                    ($peers[(int) $stream] ?? null)?->read();
                }
            }
            foreach ($write as $stream) { ($peers[(int) $stream] ?? null)?->flush(); }
        }
    } else {
        if ($backend === 'revolt') {
            $acceptWatcher = $loop->onReadable($server, $acceptRaw);
        } else {
            $listener = new Listener($loop, $server, static function (Connection $connection) use ($backend, $loop, $handler, &$peers, &$stopping): void {
                if ($stopping || count($peers) >= 512) {
                    $connection->close();
                    return;
                }
                $id = spl_object_id($connection);
                $peers[$id] = $connection;
                $http = new BenchmarkHttp(
                    static fn(string $bytes): bool => $connection->tryWrite($bytes) === WriteResult::Accepted,
                    static function (bool $paused) use ($connection): void {
                        $paused ? $connection->pauseRead() : $connection->resumeRead();
                    },
                    $connection->end(...), $handler, $backend === 'codec',
                );
                $connection->onData(static function (Connection $connection, string $bytes) use ($http): void { $http->receive($bytes); });
                $connection->onDrain($http->pump(...));
                $connection->onEof($http->eof(...));
                $connection->onDrainRequest($http->drain(...));
                $connection->onClose(static function () use ($http, $id, $loop, &$peers, &$stopping): void {
                    $http->dispose();
                    unset($peers[$id]);
                    if ($stopping && $peers === []) { $loop->stop(); }
                });
            });
            $listener->attach();
        }
        $shutdown = static function () use ($loop, $listener, $server, &$peers, &$stopping, &$acceptWatcher): void {
            if ($stopping) { return; }
            $stopping = true;
            if ($listener !== null) {
                $listener->close();
            } else {
                $loop->cancel($acceptWatcher);
                fclose($server);
            }
            foreach ($peers as $peer) {
                $peer instanceof Connection ? $peer->drain() : $peer->http->drain();
            }
            if ($peers === []) {
                $loop->stop();
            } else {
                $loop->delay(2, static function () use (&$peers, $loop): void {
                    foreach ($peers as $peer) { $peer->close(); }
                    $loop->stop();
                });
            }
        };
        if (function_exists('pcntl_signal')) {
            $loop->onSignal(SIGTERM, $shutdown);
            $loop->onSignal(SIGINT, $shutdown);
        }
        $loop->run();
    }
} finally {
    foreach ($peers as $peer) { $peer->close(); }
    $listener?->close();
    if (is_resource($server)) { fclose($server); }
    if ($loop !== null) {
        foreach ($loop->getIdentifiers() as $id) { $loop->cancel($id); }
    }
}
