<?php

declare(strict_types=1);

namespace Crab\Engine\Http;

/**
 * Strict HTTP/1 message framing. next() consumes at most one complete request.
 */
final class Parser
{
    private const TOKEN = "[!#$%&'*+.^_`|~0-9A-Za-z-]+";
    private string $buffer = '';
    private string $state = 'headers';
    private int $headerScan = 0;
    private string $method = '';
    private string $target = '';
    private string $version = '';
    private array $headers = [];
    private array $trailers = [];
    private ?Body $body = null;
    private int $remaining = 0;
    private int $trailerBytes = 0;
    private bool $continue = false;

    public function __construct(
        private readonly int $maxHeaderBytes = 16384,
        private readonly int $maxBodyBytes = 1048576,
        private readonly int $maxHeaderCount = 100,
    ) {
        if ($maxHeaderBytes < 1 || $maxBodyBytes < 0 || $maxHeaderCount < 1) {
            throw new \InvalidArgumentException('Invalid HTTP parser limits.');
        }
    }

    public function append(string $bytes): void
    {
        if (strlen($this->buffer) + strlen($bytes) > $this->maxBodyBytes + $this->maxHeaderBytes + 65536) {
            throw new HttpException(413, 'Too much pending request data.');
        }
        $this->buffer .= $bytes;
    }

    public function phase(): string
    {
        return $this->state === 'headers' ? ($this->buffer === '' ? 'idle' : 'headers') : 'body';
    }

    public function takeContinue(): bool
    {
        $continue = $this->continue;
        $this->continue = false;
        return $continue;
    }

    /**
     * Transfer unread bytes to an upgraded protocol exactly once.
     */
    public function takeBufferedBytes(): string
    {
        $bytes = $this->buffer;
        $this->buffer = '';
        return $bytes;
    }

    public function next(): ?Request
    {
        while (true) {
            if ($this->state === 'headers') {
                $end = strpos($this->buffer, "\r\n\r\n", $this->headerScan);
                if ($end === false) {
                    if (strlen($this->buffer) > $this->maxHeaderBytes) {
                        throw new HttpException(431, 'Request headers are too large.');
                    }
                    $this->headerScan = max(0, strlen($this->buffer) - 3);
                    return null;
                }
                if ($end + 4 > $this->maxHeaderBytes) {
                    throw new HttpException(431, 'Request headers are too large.');
                }
                $head = substr($this->buffer, 0, $end);
                $this->buffer = substr($this->buffer, $end + 4);
                $this->parseHeaders($head);
                if ($this->state === 'complete') {
                    return $this->complete();
                }
            }

            if ($this->state === 'fixed' || $this->state === 'chunk-data') {
                $length = min($this->remaining, strlen($this->buffer));
                if ($length > 0) {
                    $this->body->append(substr($this->buffer, 0, $length));
                    $this->buffer = substr($this->buffer, $length);
                    $this->remaining -= $length;
                }
                if ($this->remaining !== 0) {
                    return null;
                }
                if ($this->state === 'fixed') {
                    return $this->complete();
                }
                $this->state = 'chunk-crlf';
            }

            if ($this->state === 'chunk-crlf') {
                if (strlen($this->buffer) < 2) {
                    return null;
                }
                if (!str_starts_with($this->buffer, "\r\n")) {
                    throw new HttpException(400, 'Invalid chunk terminator.');
                }
                $this->buffer = substr($this->buffer, 2);
                $this->state = 'chunk-size';
            }

            if ($this->state === 'chunk-size') {
                $line = $this->takeLine(8192);
                if ($line === null) {
                    return null;
                }
                $this->remaining = $this->chunkLength($line);
                if ($this->remaining > $this->maxBodyBytes - $this->body->size()) {
                    throw new HttpException(413, 'Request body is too large.');
                }
                $this->state = $this->remaining === 0 ? 'trailers' : 'chunk-data';
                continue;
            }

            if ($this->state === 'trailers') {
                $line = $this->takeLine($this->maxHeaderBytes - $this->trailerBytes);
                if ($line === null) {
                    return null;
                }
                $this->trailerBytes += strlen($line) + 2;
                if ($line === '') {
                    return $this->complete();
                }
                [$name, $value] = self::field($line);
                $forbidden = ['content-length', 'transfer-encoding', 'host', 'connection', 'trailer', 'te',
                    'upgrade', 'authorization', 'proxy-authorization', 'content-encoding', 'content-type',
                    'content-range', 'expect'];
                $connectionFields = array_map(trim(...), explode(',', strtolower(implode(',', $this->headers['connection'] ?? []))));
                if (in_array($name, $forbidden, true) || in_array($name, $connectionFields, true)) {
                    throw new HttpException(400, 'Forbidden trailer field.');
                }
                $this->trailers[$name][] = $value;
                if (array_sum(array_map(count(...), $this->trailers)) > $this->maxHeaderCount) {
                    throw new HttpException(431, 'Too many trailer fields.');
                }
            }
        }
    }

    private function parseHeaders(string $head): void
    {
        $lines = explode("\r\n", $head);
        $line = array_shift($lines);
        if (!preg_match('/^(' . self::TOKEN . ') ([^\x00-\x20\x7F]+) HTTP\/([0-9]+\.[0-9]+)$/D', $line, $match)) {
            throw new HttpException(400, 'Malformed request line.');
        }
        [, $this->method, $this->target, $this->version] = $match;
        if (!in_array($this->version, ['1.0', '1.1'], true)) {
            throw new HttpException(505, 'Unsupported HTTP version.');
        }
        if (count($lines) > $this->maxHeaderCount) {
            throw new HttpException(431, 'Too many header fields.');
        }
        $this->headers = [];
        foreach ($lines as $line) {
            [$name, $value] = self::field($line);
            $this->headers[$name][] = $value;
        }
        $host = $this->headers['host'] ?? [];
        if (count($host) > 1 || ($this->version === '1.1' && count($host) !== 1)) {
            throw new HttpException(400, 'HTTP/1.1 requires exactly one Host field.');
        }
        if ($host !== [] && !preg_match('/^(?:\[[0-9a-fA-F:.]+\]|[A-Za-z0-9._~%!$&\x27()*+;=-]+)(?::[0-9]{1,5})?$/D', $host[0])) {
            throw new HttpException(400, 'Invalid Host field.');
        }
        $this->validateTarget();

        $this->body = new Body();
        $this->trailers = [];
        $this->trailerBytes = 0;
        $this->remaining = 0;
        $this->state = 'complete';
        $lengths = $this->headers['content-length'] ?? [];
        $encodings = $this->headers['transfer-encoding'] ?? [];
        if ($lengths !== [] && $encodings !== []) {
            throw new HttpException(400, 'Content-Length and Transfer-Encoding cannot coexist.');
        }
        if ($lengths !== []) {
            if (count($lengths) !== 1 || !preg_match('/^[0-9]+$/D', $lengths[0])) {
                throw new HttpException(400, 'Ambiguous or invalid Content-Length.');
            }
            $digits = ltrim($lengths[0], '0') ?: '0';
            $limit = (string) $this->maxBodyBytes;
            if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
                throw new HttpException(413, 'Request body is too large.');
            }
            $this->remaining = (int) $digits;
            $this->state = $this->remaining > 0 ? 'fixed' : 'complete';
        }
        if ($encodings !== []) {
            $codings = array_map(trim(...), explode(',', strtolower(implode(',', $encodings))));
            if ($this->version !== '1.1' || end($codings) !== 'chunked' || count(array_keys($codings, 'chunked', true)) !== 1) {
                throw new HttpException(400, 'Invalid request transfer encoding.');
            }
            if ($codings !== ['chunked']) {
                throw new HttpException(501, 'Only chunked transfer encoding is supported.');
            }
            $this->state = 'chunk-size';
        }
        if (isset($this->headers['expect'])) {
            if ($this->version !== '1.1' || strtolower(implode(',', $this->headers['expect'])) !== '100-continue') {
                throw new HttpException(417, 'Unsupported expectation.');
            }
            $this->continue = $this->state !== 'complete';
        }
    }

    private function validateTarget(): void
    {
        if ($this->method === 'CONNECT') {
            throw new HttpException(501, 'CONNECT tunneling is not supported.');
        }
        if ($this->target === '*') {
            if ($this->method !== 'OPTIONS') {
                throw new HttpException(400, 'Asterisk target requires OPTIONS.');
            }
            return;
        }
        if (str_contains($this->target, '#')) {
            throw new HttpException(400, 'Fragments are not allowed in request targets.');
        }
        if (str_starts_with($this->target, '/')) {
            return;
        }
        $parts = parse_url($this->target);
        if ($parts === false || !isset($parts['host'], $parts['scheme'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new HttpException(400, 'Invalid request target.');
        }
        $this->headers['host'] = [$parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')];
    }

    /**
     * @return array{string, string}
     */
    public static function field(string $line): array
    {
        if (!preg_match('/^(' . self::TOKEN . '):([\x09\x20-\x7E\x80-\xFF]*)$/D', $line, $match)) {
            throw new HttpException(400, 'Malformed header field.');
        }
        return [strtolower($match[1]), trim($match[2], " \t")];
    }

    private function chunkLength(string $line): int
    {
        $token = self::TOKEN;
        $quoted = '"(?:[\x09\x20\x21\x23-\x5B\x5D-\x7E\x80-\xFF]|\\\\[\x09\x20-\x7E\x80-\xFF])*"';
        if (!preg_match('/^([0-9A-Fa-f]+)(?:[ \t]*;[ \t]*' . $token . '(?:[ \t]*=[ \t]*(?:' . $token . '|' . $quoted . '))?)*[ \t]*$/D', $line, $match)) {
            throw new HttpException(400, 'Invalid chunk size or extension.');
        }
        $digits = strtolower(ltrim($match[1], '0') ?: '0');
        $limit = dechex($this->maxBodyBytes);
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new HttpException(413, 'Chunk is too large.');
        }
        return (int) hexdec($digits);
    }

    private function takeLine(int $limit): ?string
    {
        $end = strpos($this->buffer, "\r\n");
        if (($end === false && strlen($this->buffer) > $limit) || ($end !== false && $end + 2 > $limit)) {
            throw new HttpException(431, 'HTTP framing line is too large.');
        }
        if ($end === false) {
            return null;
        }
        $line = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 2);
        return $line;
    }

    private function complete(): Request
    {
        $this->body->rewind();
        $request = new Request($this->method, $this->target, $this->version, $this->headers, $this->body, $this->trailers);
        $this->body = null;
        $this->headers = $this->trailers = [];
        $this->state = 'headers';
        $this->headerScan = 0;
        $this->continue = false;
        return $request;
    }
}
