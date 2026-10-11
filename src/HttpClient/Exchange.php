<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Http\Stream;
use Psr\Http\Message\RequestInterface;

/**
 * One request and its answer on a connection, as HTTP/1.1 puts them on
 * the wire. The answer's body is kept in memory while it is small and on
 * disk beyond that.
 *
 * @internal
 */
final class Exchange
{
    /**
     * How long the answer's status line and headers may be.
     */
    private const MAX_HEAD = 65536;
    /**
     * Bytes that have arrived and are not yet read.
     */
    private string $buffer = '';
    /**
     * Whether the server has sent anything in answer to this request.
     */
    public bool $answered = false;
    /**
     * Whether any byte of this request was written, even if a write failed.
     */
    public bool $sent = false;
    /**
     * Whether the connection can carry another request afterwards.
     */
    public bool $reusable = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly float $deadline,
        private readonly ?Cancellation $cancellation = null,
    ) {
    }

    /**
     * @throws \RuntimeException Saying what went wrong, to be put after the name of the request.
     */
    public function run(RequestInterface $request): Response
    {
        $unsent = null;
        try {
            $this->send($request);
        } catch (TimedOut | CancelledException $error) {
            throw $error;
        } catch (\RuntimeException $error) {
            // A server may answer before it has read everything, a body too large for it, and close. Its answer is what matters then.
            $unsent = $error;
        }
        try {
            do {
                [$version, $status, $reason, $headers] = $this->head();
                // An interim answer, such as 100 Continue, is followed by the real one.
            } while ($status < 200 && $status !== 101);
        } catch (\RuntimeException $error) {
            throw $unsent ?? $error;
        }

        $method = $request->getMethod();
        $encoding = strtolower(implode(',', $headers['transfer-encoding'] ?? []));
        $length = $headers['content-length'][0] ?? null;
        $bodiless = $method === 'HEAD' || $status === 204 || $status === 304 || $status < 200;
        // These have no body, whatever their headers say.
        $sink = new BodySink($bodiless ? '' : ($headers['content-encoding'][0] ?? ''));
        $store = $sink->write(...);
        $delimited = true;
        if ($bodiless) {
            // Nothing to read.
        } elseif (str_contains($encoding, 'chunked')) {
            $this->chunks($store);
        } elseif ($length !== null) {
            if (preg_match('/^\d{1,18}$/D', $length) !== 1) {
                throw new \RuntimeException('the answer\'s length is not a number');
            }
            $this->exactly((int) $length, $store);
        } else {
            // Neither a length nor chunks: the body ends when the server closes the connection.
            $delimited = false;
            $this->untilClosed($store);
        }
        if ($sink->decoding) {
            // The body is handed on as what it says, so what described its compressed form no longer holds.
            unset($headers['content-encoding'], $headers['content-length']);
        }
        $closing = static fn (array $values): bool => str_contains(strtolower(implode(',', $values)), 'close');
        $this->reusable = $unsent === null && $delimited && $version === '1.1' && $status !== 101 && $this->buffer === ''
            && !$closing($headers['connection'] ?? []) && !$closing($request->getHeader('Connection'));
        try {
            return new Response($status, $headers, new Stream($sink->stream()), $reason, $version, self::describe($request));
        } catch (\InvalidArgumentException $error) {
            throw new \RuntimeException('the answer is not valid HTTP: ' . $error->getMessage(), previous: $error);
        }
    }

    private function send(RequestInterface $request): void
    {
        $method = $request->getMethod();
        $target = $request->getRequestTarget();
        $body = $request->getBody();
        $size = $body->getSize();
        $headers = $request->getHeaders();
        $given = array_change_key_case($headers, CASE_LOWER);
        if ($target === '') {
            $target = '/';
        }
        if ($this->connection->forwarded) {
            // A proxy that forwards is told the whole URL, and who is asking when it wants to know.
            $uri = $request->getUri();
            $target = $uri->getScheme() . '://' . $uri->getHost() . ($uri->getPort() === null ? '' : ':' . $uri->getPort()) . $target;
        }
        $head = "{$method} {$target} HTTP/1.1\r\n";
        if ($this->connection->forwarded && $this->connection->proxyAuthorization !== null) {
            $head .= "Proxy-Authorization: {$this->connection->proxyAuthorization}\r\n";
        }
        if (!isset($given['host'])) {
            $uri = $request->getUri();
            $head .= 'Host: ' . $uri->getHost() . ($uri->getPort() === null ? '' : ':' . $uri->getPort()) . "\r\n";
        }
        foreach ($headers as $name => $values) {
            // How the body is framed is decided here, by what the body is.
            if (in_array(strtolower((string) $name), ['content-length', 'transfer-encoding'], true)) {
                continue;
            }
            foreach ($values as $value) {
                $head .= "{$name}: {$value}\r\n";
            }
        }
        if (!isset($given['user-agent'])) {
            $head .= 'User-Agent: Crab' . "\r\n";
        }
        if (!isset($given['accept'])) {
            $head .= "Accept: */*\r\n";
        }
        if (!isset($given['accept-encoding']) && function_exists('inflate_init')) {
            $head .= "Accept-Encoding: gzip, deflate\r\n";
        }
        if ($size === null) {
            $head .= "Transfer-Encoding: chunked\r\n";
        } elseif ($size > 0 || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $head .= "Content-Length: {$size}\r\n";
        }
        $head .= "\r\n";
        if ($size === 0) {
            $this->connection->write($head, $this->deadline, $this->cancellation, $this->sent);
            return;
        }
        if ($body->isSeekable()) {
            $body->rewind();
        }
        // A small body goes out with the head, in one write.
        $pending = $head;
        while (!$body->eof()) {
            $bytes = $body->read(65536);
            if ($bytes === '') {
                continue;
            }
            $pending .= $size === null ? dechex(strlen($bytes)) . "\r\n{$bytes}\r\n" : $bytes;
            if (strlen($pending) >= 65536) {
                $this->connection->write($pending, $this->deadline, $this->cancellation, $this->sent);
                $pending = '';
            }
        }
        if ($size === null) {
            $pending .= "0\r\n\r\n";
        }
        if ($pending !== '') {
            $this->connection->write($pending, $this->deadline, $this->cancellation, $this->sent);
        }
    }

    /**
     * A request by its method and where it goes, without the query string
     * or anything else that may hold a secret.
     */
    public static function describe(RequestInterface $request): string
    {
        $uri = $request->getUri();
        $where = $uri->getHost() === '' ? $uri->getPath() : $uri->getScheme() . '://' . $uri->getHost() . ($uri->getPort() === null ? '' : ':' . $uri->getPort()) . $uri->getPath();
        return $request->getMethod() . ' ' . $where;
    }

    /**
     * @return array{string, int, string, array<string, list<string>>} The version, the status, its phrase and the headers by lowercase name.
     */
    private function head(): array
    {
        while (($end = strpos($this->buffer, "\r\n\r\n")) === false) {
            if (strlen($this->buffer) > self::MAX_HEAD) {
                throw new \RuntimeException('the answer\'s headers are too long');
            }
            if (!$this->more()) {
                throw new \RuntimeException($this->answered ? 'the connection was lost in the middle of the answer' : 'the server closed the connection without answering');
            }
        }
        $lines = explode("\r\n", substr($this->buffer, 0, $end));
        $this->buffer = substr($this->buffer, $end + 4);
        if (preg_match('~^HTTP/(1\.[01]) (\d{3})(?: (.*))?$~D', array_shift($lines), $status) !== 1) {
            throw new \RuntimeException('the answer is not HTTP/1.1');
        }
        $headers = [];
        $last = null;
        foreach ($lines as $line) {
            if ($last !== null && ($line[0] ?? '') !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                // An old way of continuing a value on the next line.
                $headers[$last][array_key_last($headers[$last])] .= ' ' . trim($line);
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                throw new \RuntimeException('the answer is not valid HTTP');
            }
            $last = strtolower(substr($line, 0, $colon));
            $headers[$last][] = trim(substr($line, $colon + 1), " \t");
        }
        return [$status[1], (int) $status[2], $status[3] ?? '', $headers];
    }

    /**
     * @param \Closure(string): void $store
     */
    private function exactly(int $length, \Closure $store): void
    {
        while ($length > 0) {
            if ($this->buffer === '' && !$this->more()) {
                throw new \RuntimeException('the connection was lost in the middle of the answer');
            }
            $bytes = strlen($this->buffer) <= $length ? $this->buffer : substr($this->buffer, 0, $length);
            $this->buffer = (string) substr($this->buffer, strlen($bytes));
            $length -= strlen($bytes);
            $store($bytes);
        }
    }

    /**
     * @param \Closure(string): void $store
     */
    private function chunks(\Closure $store): void
    {
        while (true) {
            $line = $this->line();
            if (preg_match('/^([0-9a-f]{1,15})(?:;.*)?$/Di', $line, $size) !== 1) {
                throw new \RuntimeException('the answer is not valid HTTP');
            }
            $length = (int) hexdec($size[1]);
            if ($length === 0) {
                break;
            }
            $this->exactly($length, $store);
            if ($this->line() !== '') {
                throw new \RuntimeException('the answer is not valid HTTP');
            }
        }
        // Trailers, which nothing here reads, up to the empty line that ends the body.
        while ($this->line() !== '') {
        }
    }

    /**
     * @param \Closure(string): void $store
     */
    private function untilClosed(\Closure $store): void
    {
        do {
            if ($this->buffer !== '') {
                $store($this->buffer);
                $this->buffer = '';
            }
        } while ($this->more());
    }

    /**
     * The next line, without its line break.
     */
    private function line(): string
    {
        while (($end = strpos($this->buffer, "\r\n")) === false) {
            if (strlen($this->buffer) > self::MAX_HEAD || !$this->more()) {
                throw new \RuntimeException('the connection was lost in the middle of the answer');
            }
        }
        $line = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 2);
        return $line;
    }

    /**
     * Reads more into the buffer; false when the server has closed the connection.
     */
    private function more(): bool
    {
        $bytes = $this->connection->read($this->deadline, $this->cancellation);
        if ($bytes === null) {
            return false;
        }
        $this->answered = true;
        $this->buffer .= $bytes;
        return true;
    }
}
