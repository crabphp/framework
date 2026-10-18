<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\EventLoop;
use Crab\Http\Stream;
use Psr\Http\Message\RequestInterface;

/**
 * One HTTP/2 connection, carrying many requests at once (RFC 9113). Each
 * request is a stream of frames; a reader takes the frames the server
 * sends, as they come, and hands each to the request it belongs to.
 *
 * @internal
 */
final class Http2Session
{
    private const DATA = 0;
    private const HEADERS = 1;
    private const RST_STREAM = 3;
    private const SETTINGS = 4;
    private const PUSH_PROMISE = 5;
    private const PING = 6;
    private const GOAWAY = 7;
    private const WINDOW_UPDATE = 8;
    private const CONTINUATION = 9;

    private const END_STREAM = 0x1;
    private const END_HEADERS = 0x4;
    private const PADDED = 0x8;
    private const PRIORITY = 0x20;
    private const ACK = 0x1;

    /**
     * How many bytes of one answer's body the server may send before it is told it may send more.
     */
    private const STREAM_WINDOW = 1_048_576;
    /**
     * The same for all the answers on the connection together.
     */
    private const CONNECTION_WINDOW = 16_777_216;
    /**
     * The largest frame this side takes, which is what a connection starts with.
     */
    private const MAX_FRAME = 16_384;
    /**
     * Headers that describe an HTTP/1.1 connection and have no place on this one.
     */
    private const DROPPED = [
        'host',
        'connection',
        'keep-alive',
        'proxy-connection',
        'transfer-encoding',
        'upgrade',
        'te',
        'content-length',
    ];
    private const ERRORS = [
        'no error',
        'a protocol error',
        'an internal error',
        'a flow control error',
        'a settings timeout',
        'a closed stream',
        'a frame size error',
        'a refused stream',
        'a cancellation',
        'a compression error',
        'a connect error',
        'too many requests',
        'inadequate security',
        'HTTP/1.1 being required',
    ];

    /**
     * @var array<int, Http2Stream>
     */
    private array $streams = [];
    private int $nextId = 1;
    private readonly Hpack $hpack;
    /**
     * How many bytes of request bodies the server will take on the whole connection for now.
     */
    private int $window = 65_535;
    /**
     * What the server said of itself: the window a new stream starts with, its largest frame and how many requests at once.
     */
    private int $streamWindow = 65_535;
    private int $maxFrame = 16_384;
    private int $maxStreams = 100;
    /**
     * Bytes of bodies received that the server has not yet been told it may send again.
     */
    private int $unacknowledged = 0;
    /**
     * Why the connection can carry nothing more.
     */
    private ?\Throwable $dead = null;
    /**
     * Whether the server has said it will take no new requests.
     */
    private bool $leaving = false;
    private bool $writing = false;
    /**
     * Fibers waiting their turn to write, in order.
     *
     * @var list<\Crab\Engine\Suspension>
     */
    private array $writers = [];
    /**
     * Resume whoever waits for room: a free stream, or a window to send into.
     *
     * @var array<int, \Closure>
     */
    private array $waiting = [];
    private int $nextWaiter = 0;

    /**
     * @param \Closure(): void $onClose Called once, when the connection can carry nothing more.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EventLoop $loop,
        private readonly \Closure $onClose,
    ) {
        $this->hpack = new Hpack();
    }

    /**
     * Opens the conversation and starts listening. Called once, by the fiber that made the connection.
     */
    public function start(float $deadline): void
    {
        $settings = pack('nN', 2, 0) . pack('nN', 4, self::STREAM_WINDOW);
        $this->write(
            "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n"
                // No pushed answers, and room for bodies to arrive without a pause.
                . self::frame(self::SETTINGS, 0, 0, $settings)
                . self::frame(self::WINDOW_UPDATE, 0, 0, pack('N', self::CONNECTION_WINDOW - 65_535)),
            $deadline,
        );
        $this->loop->defer(function (): void {
            $this->listen();
        });
    }

    /**
     * Whether another request may be started on it.
     */
    public function accepts(): bool
    {
        return $this->dead === null && !$this->leaving && $this->nextId < 0x7FFF_FFFF;
    }

    /**
     * @param bool $sent Set once any request header bytes are written, so a timeout before transmission is known to be safe to retry.
     * @throws Unprocessed When the server did not act on the request, which may then be made on another connection.
     * @throws \RuntimeException Saying what went wrong, to be put after the name of the request.
     */
    public function exchange(
        RequestInterface $request,
        float $deadline,
        ?Cancellation $cancellation,
        bool &$sent = false,
    ): Response {
        while ($this->accepts() && count($this->streams) >= $this->maxStreams) {
            $this->park($deadline, $cancellation, 'waiting for the server to take another request');
        }
        if (!$this->accepts()) {
            throw new Unprocessed('the connection was closing');
        }
        $body = $request->getBody();
        $size = $body->getSize();
        $stream = null;
        try {
            // The streams of a connection are opened in the order they are numbered, so one is numbered when its turn to write comes.
            $this->lock();
            try {
                $this->alive();
                $stream = new Http2Stream($this->nextId, $this->streamWindow, $request->getMethod() === 'HEAD');
                $this->nextId += 2;
                $this->streams[$stream->id] = $stream;
                $this->connection->write($this->head($stream->id, $request, $size), $deadline, $cancellation, $sent);
            } catch (\Throwable $error) {
                $this->writeFailed($error);
                throw $error;
            } finally {
                $this->unlock();
            }
            if ($size !== 0) {
                $this->sendBody($stream, $body, $deadline, $cancellation);
            }
            while (!$stream->done && $stream->error === null) {
                $this->await($stream, $deadline, $cancellation, 'waiting for the answer');
            }
        } catch (\Throwable $error) {
            if ($stream !== null) {
                $this->abandon($stream);
                if (!$stream->answered && $stream->error instanceof Unprocessed) {
                    throw $stream->error;
                }
            }
            throw $error;
        }
        if ($stream->error !== null) {
            $this->abandon($stream);
            throw $stream->error;
        }
        $headers = $stream->headers;
        $sink = $stream->body ?? new BodySink();
        if ($sink->decoding) {
            unset($headers['content-encoding'], $headers['content-length']);
        }
        try {
            return new Response(
                (int) $stream->status,
                $headers,
                new Stream($sink->stream()),
                '',
                '2',
                Exchange::describe($request),
            );
        } catch (\InvalidArgumentException $invalid) {
            throw new \RuntimeException('the answer is not valid HTTP: ' . $invalid->getMessage(), previous: $invalid);
        }
    }

    /**
     * Ends the connection: every request still on it fails, and whoever keeps the connection is told.
     */
    public function close(?\Throwable $why = null): void
    {
        if ($this->dead !== null) {
            return;
        }
        $this->dead = $why ?? new \RuntimeException('the connection was closed');
        $streams = $this->streams;
        $this->streams = [];
        foreach ($streams as $stream) {
            // A request nothing came back for was, as far as anyone can tell, not acted on only if it was never sent; that is not known here.
            $stream->error ??= new \RuntimeException($this->dead->getMessage(), previous: $this->dead);
            $stream->wake?->__invoke();
        }
        $this->wakeAll();
        ($this->onClose)();
    }

    /**
     * The frames that open a stream: the request's method, address and headers.
     */
    private function head(int $id, RequestInterface $request, ?int $size): string
    {
        $uri = $request->getUri();
        $target = $request->getRequestTarget();
        $authority = $request->getHeaderLine('Host');
        if ($authority === '') {
            $authority = $uri->getHost() . ($uri->getPort() === null ? '' : ':' . $uri->getPort());
        }
        $method = $request->getMethod();
        $fields = [
            [':method', $method],
            [':scheme', strtolower($uri->getScheme())],
            [':authority', $authority],
            [':path', $target === '' ? '/' : $target],
        ];
        $given = [];
        foreach ($request->getHeaders() as $name => $values) {
            $name = strtolower((string) $name);
            if (in_array($name, self::DROPPED, true)) {
                continue;
            }
            $given[$name] = true;
            // Several cookies are one field, as they are on an HTTP/1.1 connection.
            foreach ($name === 'cookie' ? [implode('; ', $values)] : $values as $value) {
                $fields[] = [$name, (string) $value];
            }
        }
        if (!isset($given['user-agent'])) {
            $fields[] = ['user-agent', 'Crab'];
        }
        if (!isset($given['accept'])) {
            $fields[] = ['accept', '*/*'];
        }
        if (!isset($given['accept-encoding']) && function_exists('inflate_init')) {
            $fields[] = ['accept-encoding', 'gzip, deflate'];
        }
        if ($size !== null && ($size > 0 || in_array($method, ['POST', 'PUT', 'PATCH'], true))) {
            $fields[] = ['content-length', (string) $size];
        }
        $block = Hpack::encode($fields);
        $ends = $size === 0 ? self::END_STREAM : 0;
        if (strlen($block) <= $this->maxFrame) {
            return self::frame(self::HEADERS, $ends | self::END_HEADERS, $id, $block);
        }
        // Too long for one frame: the rest follows at once, and nothing may come between.
        $parts = str_split($block, $this->maxFrame);
        $frames = self::frame(self::HEADERS, $ends, $id, array_shift($parts));
        foreach ($parts as $index => $part) {
            $frames .= self::frame(
                self::CONTINUATION,
                $index === array_key_last($parts) ? self::END_HEADERS : 0,
                $id,
                $part,
            );
        }
        return $frames;
    }

    /**
     * Sends a request's body, no faster than the server says it will take it.
     */
    private function sendBody(
        Http2Stream $stream,
        \Psr\Http\Message\StreamInterface $body,
        float $deadline,
        ?Cancellation $cancellation,
    ): void {
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $pending = '';
        while (true) {
            if ($pending === '' && !$body->eof()) {
                $pending = $body->read(65_536);
            }
            if ($pending === '') {
                if ($body->eof()) {
                    break;
                }
                continue;
            }
            while (min($this->window, $stream->window) <= 0) {
                if ($stream->done || $stream->error !== null) {
                    // The server has answered, or given up, without wanting the rest.
                    return;
                }
                $this->alive();
                $this->park($deadline, $cancellation, 'sending', $stream);
            }
            if ($stream->done || $stream->error !== null) {
                return;
            }
            $length = min(strlen($pending), $this->window, $stream->window, $this->maxFrame);
            $this->window -= $length;
            $stream->window -= $length;
            $this->write(
                self::frame(self::DATA, 0, $stream->id, substr($pending, 0, $length)),
                $deadline,
                $cancellation,
            );
            $pending = (string) substr($pending, $length);
        }
        if (!$stream->done && $stream->error === null) {
            $this->write(self::frame(self::DATA, self::END_STREAM, $stream->id, ''), $deadline, $cancellation);
        }
    }

    /**
     * Gives up a stream whose request will not be waited for any longer, and tells the server so.
     */
    private function abandon(Http2Stream $stream): void
    {
        if (!isset($this->streams[$stream->id])) {
            return;
        }
        unset($this->streams[$stream->id]);
        $this->wakeAll();
        if ($this->dead !== null) {
            return;
        }
        $reset = self::frame(self::RST_STREAM, 0, $stream->id, pack('N', 8));
        // Sent on the side: whoever gave the request up is not made to wait for it.
        $this->loop->defer(function () use ($reset): void {
            try {
                $this->write($reset, (hrtime(true) / 1_000_000_000) + 5.0);
            } catch (\Throwable) {
                // A connection that cannot be written to is found out by its reader.
            }
        });
    }

    /**
     * Reads frames for as long as the connection lasts.
     */
    private function listen(): void
    {
        $buffer = '';
        // A header block that is not finished: its stream, whether the stream ends with it, and what has come of it.
        $block = null;
        try {
            while ($this->dead === null) {
                while (strlen($buffer) < 9 || strlen($buffer) < (9 + unpack('N', "\0" . substr($buffer, 0, 3))[1])) {
                    $bytes = $this->connection->read(INF, null, background: true);
                    if ($bytes === null) {
                        throw new \RuntimeException('the connection was lost in the middle of the answer');
                    }
                    $buffer .= $bytes;
                }
                $length = unpack('N', "\0" . substr($buffer, 0, 3))[1];
                $type = ord($buffer[3]);
                $flags = ord($buffer[4]);
                $id = unpack('N', $buffer, 5)[1] & 0x7FFF_FFFF;
                if ($length > self::MAX_FRAME) {
                    throw new \RuntimeException('the server sent a frame larger than allowed');
                }
                $payload = substr($buffer, 9, $length);
                $buffer = (string) substr($buffer, 9 + $length);
                if ($block !== null && ($type !== self::CONTINUATION || $id !== $block[0])) {
                    throw new \RuntimeException('the server interrupted a header block');
                }
                switch ($type) {
                    case self::DATA:
                        $this->data($id, $flags, $payload);
                        break;
                    case self::HEADERS:
                        if (($flags & self::PADDED) !== 0) {
                            $payload = self::unpadded($payload);
                        }
                        if (($flags & self::PRIORITY) !== 0) {
                            $payload = (string) substr($payload, 5);
                        }
                        $block = [$id, ($flags & self::END_STREAM) !== 0, $payload];
                        // No break: a block that is whole is read at once.
                    case self::CONTINUATION:
                        if ($block === null) {
                            throw new \RuntimeException('the server continued a header block it had not begun');
                        }
                        if ($type === self::CONTINUATION) {
                            $block[2] .= $payload;
                        }
                        if (strlen($block[2]) > 262_144) {
                            throw new \RuntimeException('the answer\'s headers are too long');
                        }
                        if (($flags & self::END_HEADERS) !== 0) {
                            $this->headers($block[0], $block[1], $block[2]);
                            $block = null;
                        }
                        break;
                    case self::RST_STREAM:
                        $this->reset($id, strlen($payload) === 4 ? unpack('N', $payload)[1] : 2);
                        break;
                    case self::SETTINGS:
                        if (($flags & self::ACK) === 0) {
                            $this->settings($payload);
                        }
                        break;
                    case self::PING:
                        if (($flags & self::ACK) === 0) {
                            $this->write(
                                self::frame(self::PING, self::ACK, 0, $payload),
                                (hrtime(true) / 1_000_000_000) + 10.0,
                            );
                        }
                        break;
                    case self::GOAWAY:
                        $this->leave(
                            strlen($payload) >= 8 ? unpack('N', $payload)[1] & 0x7FFF_FFFF : 0,
                            strlen($payload) >= 8 ? unpack('N', $payload, 4)[1] : 0,
                        );
                        break;
                    case self::WINDOW_UPDATE:
                        $more = strlen($payload) === 4 ? unpack('N', $payload)[1] & 0x7FFF_FFFF : 0;
                        if ($id === 0) {
                            $this->window += $more;
                        } elseif (isset($this->streams[$id])) {
                            $this->streams[$id]->window += $more;
                        }
                        $this->wakeAll();
                        break;
                    case self::PUSH_PROMISE:
                        throw new \RuntimeException('the server pushed an answer nobody asked for');
                    default:
                        // A frame of a kind not known here, or one that only ranks streams, is passed over.
                        break;
                }
            }
        } catch (\Throwable $error) {
            $this->close($error);
        }
    }

    private function data(int $id, int $flags, string $payload): void
    {
        $counted = strlen($payload);
        if (($flags & self::PADDED) !== 0) {
            $payload = self::unpadded($payload);
        }
        // Every byte counts against what the server may send, the padding and a body nobody waits for included.
        $this->unacknowledged += $counted;
        $update = '';
        if ($this->unacknowledged >= (self::CONNECTION_WINDOW / 4)) {
            $update .= self::frame(self::WINDOW_UPDATE, 0, 0, pack('N', $this->unacknowledged));
            $this->unacknowledged = 0;
        }
        $stream = $this->streams[$id] ?? null;
        if ($stream !== null) {
            $stream->answered = true;
            if ($stream->status === null) {
                $stream->error = new \RuntimeException('the server sent a body before any headers');
            } elseif (!$stream->bodiless) {
                try {
                    $stream->body?->write($payload);
                } catch (\RuntimeException $error) {
                    $stream->error = $error;
                }
            }
            $stream->unacknowledged += $counted;
            if (($flags & self::END_STREAM) !== 0) {
                $this->finish($stream);
            } elseif ($stream->unacknowledged >= (self::STREAM_WINDOW / 2)) {
                $update .= self::frame(self::WINDOW_UPDATE, 0, $id, pack('N', $stream->unacknowledged));
                $stream->unacknowledged = 0;
            }
            $stream->wake?->__invoke();
        }
        if ($update !== '') {
            $this->write($update, (hrtime(true) / 1_000_000_000) + 10.0);
        }
    }

    private function headers(int $id, bool $ends, string $block): void
    {
        // Read even for a stream nobody waits for: the next block may refer to what this one said.
        $fields = $this->hpack->decode($block);
        $stream = $this->streams[$id] ?? null;
        if ($stream === null) {
            return;
        }
        $stream->answered = true;
        if ($stream->status === null) {
            $status = null;
            $headers = [];
            foreach ($fields as [$name, $value]) {
                if ($name === ':status') {
                    $status = (int) $value;
                } elseif ($name !== '' && $name[0] !== ':') {
                    $headers[$name][] = $value;
                }
            }
            if ($status === null || $status < 100 || $status > 599) {
                $stream->error = new \RuntimeException('the answer has no status');
            } elseif ($status >= 200) {
                // An interim answer, such as 100 Continue, is followed by the real one.
                $stream->status = $status;
                $stream->headers = $headers;
                $bodiless = $stream->bodiless || $status === 204 || $status === 304;
                $stream->body = new BodySink($bodiless ? '' : $headers['content-encoding'][0] ?? '');
            }
        }
        // Headers after the body are trailers, which nothing here reads.
        if ($ends || $stream->error !== null) {
            $this->finish($stream);
        }
        $stream->wake?->__invoke();
    }

    private function reset(int $id, int $code): void
    {
        $stream = $this->streams[$id] ?? null;
        if ($stream === null) {
            return;
        }
        if ($code === 0 && $stream->status !== null) {
            // "No error": the server has answered and wants no more of the request's body.
            $this->finish($stream);
        } else {
            $why = self::ERRORS[$code] ?? "error {$code}";
            $stream->error = $code === 7
                ? new Unprocessed('the server refused the request before acting on it')
                : new \RuntimeException("the server gave up on the request, with {$why}");
            unset($this->streams[$id]);
            $this->wakeAll();
        }
        $stream->wake?->__invoke();
    }

    private function settings(string $payload): void
    {
        foreach (str_split($payload, 6) as $setting) {
            if (strlen($setting) !== 6) {
                break;
            }
            ['id' => $id, 'value' => $value] = unpack('nid/Nvalue', $setting);
            if ($id === 3) {
                $this->maxStreams = max(1, $value);
            } elseif ($id === 4) {
                // The window of every open stream moves with that of new ones.
                foreach ($this->streams as $stream) {
                    $stream->window += $value - $this->streamWindow;
                }
                $this->streamWindow = $value;
            } elseif ($id === 5) {
                $this->maxFrame = max(self::MAX_FRAME, min($value, 1_048_576));
            }
        }
        $this->wakeAll();
        $this->write(self::frame(self::SETTINGS, self::ACK, 0, ''), (hrtime(true) / 1_000_000_000) + 10.0);
    }

    /**
     * The server takes no new requests: those it says it did not reach may be made elsewhere, the others are waited for.
     */
    private function leave(int $lastReached, int $code): void
    {
        $this->leaving = true;
        foreach ($this->streams as $id => $stream) {
            if ($id <= $lastReached) {
                continue;
            }

            $stream->error = new Unprocessed('the server closed the connection before reaching the request');
            unset($this->streams[$id]);
            $stream->wake?->__invoke();
        }
        $this->wakeAll();
        if ($this->streams === []) {
            $why = self::ERRORS[$code] ?? "error {$code}";
            $this->close(
                new \RuntimeException(
                    $code === 0 ? 'the server closed the connection' : "the server closed the connection, with {$why}",
                ),
            );
        }
    }

    private function finish(Http2Stream $stream): void
    {
        $stream->done = $stream->error === null;
        unset($this->streams[$stream->id]);
        $this->wakeAll();
        if ($this->leaving && $this->streams === []) {
            $this->loop->defer(function (): void {
                $this->close();
            });
        }
    }

    /**
     * Writes whole frames, one writer at a time so that the frames of two never mix.
     */
    private function write(string $frames, float $deadline, ?Cancellation $cancellation = null): void
    {
        $this->lock();
        try {
            $this->alive();
            $this->connection->write($frames, $deadline, $cancellation);
        } catch (\Throwable $error) {
            $this->writeFailed($error);
            throw $error;
        } finally {
            $this->unlock();
        }
    }

    /**
     * A write that stopped part way leaves half a frame on the connection, which nothing can follow.
     */
    private function writeFailed(\Throwable $error): void
    {
        if ($this->dead === null && !$error instanceof Unprocessed) {
            $this->loop->defer(function () use ($error): void {
                $this->close(new \RuntimeException('the connection was lost while sending', previous: $error));
            });
        }
    }

    private function alive(): void
    {
        if ($this->dead !== null) {
            throw new \RuntimeException($this->dead->getMessage(), previous: $this->dead);
        }
    }

    private function lock(): void
    {
        if (!$this->writing) {
            $this->writing = true;
            return;
        }
        $turn = $this->loop->getSuspension();
        $this->writers[] = $turn;
        // Resumed holding the lock: whoever had it hands it on.
        $turn->suspend();
    }

    private function unlock(): void
    {
        $next = array_shift($this->writers);
        if ($next === null) {
            $this->writing = false;
        } else {
            $next->resume();
        }
    }

    /**
     * Suspends until a stream changes, the deadline passes or the wait is cancelled.
     */
    private function await(Http2Stream $stream, float $deadline, ?Cancellation $cancellation, string $doing): void
    {
        $this->suspend(
            static function (?\Closure $wake) use ($stream): void {
                $stream->wake = $wake;
            },
            $deadline,
            $cancellation,
            $doing,
        );
    }

    /**
     * Suspends until there may be room: a stream has ended, or the server takes more. A stream given is woken by its own changes too.
     */
    private function park(
        float $deadline,
        ?Cancellation $cancellation,
        string $doing,
        ?Http2Stream $stream = null,
    ): void {
        $id = $this->nextWaiter++;
        $this->suspend(
            function (?\Closure $wake) use ($id, $stream): void {
                if ($wake === null) {
                    unset($this->waiting[$id]);
                } else {
                    $this->waiting[$id] = $wake;
                }
                if ($stream !== null) {
                    $stream->wake = $wake;
                }
            },
            $deadline,
            $cancellation,
            $doing,
        );
    }

    /**
     * @param \Closure(?\Closure): void $register Given what resumes the fiber, to keep, and null afterwards, to let go of.
     */
    private function suspend(\Closure $register, float $deadline, ?Cancellation $cancellation, string $doing): void
    {
        $cancellation?->throwIfCancelled();
        $left = $deadline - (hrtime(true) / 1_000_000_000);
        if ($left <= 0) {
            throw new TimedOut($doing);
        }
        $suspension = $this->loop->getSuspension();
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
        $register(static fn() => $finish());
        $timer = $this->loop->delay($left, static fn() => $finish(new TimedOut($doing)));
        $unsubscribe = $cancellation?->subscribe(static fn(CancelledException $error) => $finish($error));
        try {
            $suspension->suspend();
        } finally {
            $settled = true;
            $register(null);
            $this->loop->cancel($timer);
            $unsubscribe?->__invoke();
        }
    }

    private function wakeAll(): void
    {
        foreach ($this->waiting as $wake) {
            $wake();
        }
    }

    private static function frame(int $type, int $flags, int $stream, string $payload): string
    {
        return substr(pack('N', strlen($payload)), 1) . chr($type) . chr($flags) . pack('N', $stream) . $payload;
    }

    /**
     * A frame's content without the padding a server may add to hide its length.
     */
    private static function unpadded(string $payload): string
    {
        $padding = $payload === '' ? 0 : ord($payload[0]);
        if ($padding >= strlen($payload)) {
            throw new \RuntimeException('the server sent a frame that is all padding');
        }
        return substr($payload, 1, strlen($payload) - 1 - $padding);
    }
}
