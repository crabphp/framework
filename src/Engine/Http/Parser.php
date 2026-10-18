<?php

declare(strict_types=1);

namespace Crab\Engine\Http;

/**
 * Strict HTTP/1 message framing. next() consumes at most one complete request.
 */
final class Parser
{
    private const TOKEN = "[!#$%&'*+.^_`|~0-9A-Za-z-]+";
    private const REQUEST_LINE = '/^(' . self::TOKEN . ') ([^\x00-\x20\x7F]+) HTTP\/([0-9]+\.[0-9]+)\r\n/';
    private const FIELDS = '/\G(?:(' . self::TOKEN . '):[ \t]*+([\x09\x20-\x7E\x80-\xFF]*)\r\n|\r\n\z)/';
    private const VALID_FIELDS = '/\G(?:' . self::TOKEN . ':[\x09\x20-\x7E\x80-\xFF]*+\r\n)*+\r\n\z/';
    private const FRAMING_FIELDS = '/\r\n(host|connection|expect|content-length|transfer-encoding):[ \t]*+([^\r]*)/i';
    private const HOST = '/^(?:\[[0-9a-fA-F:.]+\]|[A-Za-z0-9._~%!$&\x27()*+;=-]+)(?::[0-9]{1,5})?$/D';

    /**
     * Validated field lines, bounded and reset when full. A line always
     * validates to the same name and value, so entries never go stale.
     *
     * @var array<string, array{string, string}>
     */
    private static array $fields = [];
    /**
     * Whole-head patterns by field-count limit.
     *
     * @var array<int, string>
     */
    private static array $headPatterns = [];
    private string $headPattern;
    private string $buffer = '';
    private ?string $validHost = null;
    private string $state = 'headers';
    private int $headerScan = 0;
    private string $method = '';
    private string $target = '';
    private string $version = '';
    private array $headers = [];
    private string $head = '';
    private ?array $fieldMap = null;
    private array $trailers = [];
    private ?Body $body = null;
    private int $remaining = 0;
    private int $trailerBytes = 0;
    private bool $continue = false;

    public function __construct(
        private readonly int $maxHeaderBytes = 16_384,
        private readonly int $maxBodyBytes = 1_048_576,
        private readonly int $maxHeaderCount = 100,
    ) {
        if ($maxHeaderBytes < 1 || $maxBodyBytes < 0 || $maxHeaderCount < 1) {
            throw new \InvalidArgumentException('Invalid HTTP parser limits.');
        }
        $this->headPattern = self::$headPatterns[$maxHeaderCount] ??= self::headPattern($maxHeaderCount);
    }

    /**
     * One anchored pattern for the usual request: a buffer holding exactly one
     * bodyless head with an origin-form target. It validates the request line
     * and every field and captures Host and Connection. Anything else,
     * including a repeated captured field, an expectation or any body framing,
     * fails the match and takes the general path, which also decides the status
     * of a malformed head.
     */
    private static function headPattern(int $maxHeaderCount): string
    {
        $value = '[ \t]*+([\x09\x20-\x7E\x80-\xFF]*+)\r\n';
        return (
            '/^('
            . self::TOKEN
            . ') (\/[^\x00-\x20\x7F#]*+) HTTP\/1\.([01])\r\n(?:'
            . '(?i:host):(?(4)(*COMMIT)(*FAIL))'
            . $value
            . '|(?i:connection):(?(5)(*COMMIT)(*FAIL))'
            . $value
            . '|(?i:expect|content-length|transfer-encoding):(*COMMIT)(*FAIL)'
            . '|'
            . self::TOKEN
            . ':[\x09\x20-\x7E\x80-\xFF]*+\r\n'
            . '){0,'
            . $maxHeaderCount
            . '}+\r\K\n\z/'
        );
    }

    public function append(string $bytes): void
    {
        if ((strlen($this->buffer) + strlen($bytes)) > ($this->maxBodyBytes + $this->maxHeaderBytes + 65_536)) {
            throw new HttpException(413, 'Too much pending request data.');
        }
        $this->buffer .= $bytes;
    }

    public function phase(): string
    {
        if ($this->state !== 'headers') {
            return 'body';
        }
        return $this->buffer === '' ? 'idle' : 'headers';
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
        return $this->receive('');
    }

    /**
     * append() followed by next(), without copying a lone bodyless head into
     * the buffer first.
     */
    public function receive(string $bytes): ?Request
    {
        // Tried once per head, so a trickled head is not rescanned.
        if ($this->headerScan === 0 && $this->state === 'headers' && ($bytes === '' || $this->buffer === '')) {
            $head = $bytes === '' ? $this->buffer : $bytes;
            if (
                $head !== ''
                && strlen($head) <= $this->maxHeaderBytes
                && preg_match($this->headPattern, $head, $match, PREG_UNMATCHED_AS_NULL) === 1
            ) {
                $method = $match[1];
                $host = $match[4];
                // Values rarely end in whitespace; the scan consumed any leading.
                if ($host !== null && $host !== '' && ($host[-1] === ' ' || $host[-1] === "\t")) {
                    $host = rtrim($host, " \t");
                }
                // A missing or unusable Host and CONNECT are left to the
                // general path, which reports them.
                if (
                    ($host === null ? $match[3] === '0' : $host === $this->validHost || $this->acceptHost($host))
                    && $method !== 'CONNECT'
                ) {
                    $connection = $match[5];
                    if (
                        $connection !== null
                        && $connection !== ''
                        && ($connection[-1] === ' ' || $connection[-1] === "\t")
                    ) {
                        $connection = rtrim($connection, " \t");
                    }
                    if ($bytes === '') {
                        $this->buffer = '';
                    }
                    return new Request(
                        $method,
                        $match[2],
                        $match[3] === '1' ? '1.1' : '1.0',
                        [],
                        null,
                        [],
                        null,
                        $head,
                        $connection,
                    );
                }
            }
        }
        if ($bytes !== '') {
            $this->append($bytes);
        }
        return $this->general();
    }

    /**
     * Remembers a valid Host so that a persistent client's repeats cost one
     * comparison.
     */
    private function acceptHost(string $host): bool
    {
        if (!preg_match(self::HOST, $host)) {
            return false;
        }
        $this->validHost = $host;
        return true;
    }

    /**
     * The complete incremental parser for whatever the buffer holds.
     */
    private function general(): ?Request
    {
        while (true) {
            if ($this->state === 'headers') {
                $buffer = $this->buffer;
                $end = strpos($buffer, "\r\n\r\n", $this->headerScan);
                if ($end === false) {
                    if (strlen($buffer) > $this->maxHeaderBytes) {
                        throw new HttpException(431, 'Request headers are too large.');
                    }
                    $this->headerScan = max(0, strlen($buffer) - 3);
                    return null;
                }
                $end += 4;
                if ($end > $this->maxHeaderBytes) {
                    throw new HttpException(431, 'Request headers are too large.');
                }
                $this->headerScan = 0;
                // A lone request needs no copy; the head keeps its blank line.
                if ($end === strlen($buffer)) {
                    $this->buffer = '';
                    $request = $this->parseHead($buffer);
                } else {
                    $this->buffer = substr($buffer, $end);
                    $request = $this->parseHead(substr($buffer, 0, $end));
                }
                if ($request !== null) {
                    return $request;
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
                if ($this->remaining > ($this->maxBodyBytes - $this->body->size())) {
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
                $forbidden = [
                    'content-length',
                    'transfer-encoding',
                    'host',
                    'connection',
                    'trailer',
                    'te',
                    'upgrade',
                    'authorization',
                    'proxy-authorization',
                    'content-encoding',
                    'content-type',
                    'content-range',
                    'expect',
                ];
                $connectionFields = array_map(
                    trim(...),
                    explode(',', strtolower(implode(',', $this->headers['connection'] ?? []))),
                );
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

    /**
     * Parses one head, including its terminating blank line. Bodyless requests
     * return directly; framed bodies continue through the parser state.
     */
    private function parseHead(string $head): ?Request
    {
        if (!preg_match(self::REQUEST_LINE, $head, $line)) {
            throw new HttpException(400, 'Malformed request line.');
        }
        $method = $line[1];
        $target = $line[2];
        $version = $line[3];
        if ($version !== '1.1' && $version !== '1.0') {
            throw new HttpException(505, 'Unsupported HTTP version.');
        }
        $fieldsAt = strlen($line[0]);
        return $this->finishHead($method, $target, $version, $head, $this->scanFields($head, $fieldsAt));
    }

    /**
     * Applies the rules that follow field validation: Host, target form,
     * expectations and body framing.
     *
     * @param array<string, list<string>> $headers The extracted framing fields.
     */
    private function finishHead(string $method, string $target, string $version, string $head, array $headers): ?Request
    {
        if (isset($headers['host'])) {
            $host = $headers['host'];
            if (isset($host[1])) {
                throw new HttpException(400, 'HTTP/1.1 requires exactly one Host field.');
            }
            if ($host[0] !== $this->validHost && !$this->acceptHost($host[0])) {
                throw new HttpException(400, 'Invalid Host field.');
            }
        } elseif ($version === '1.1') {
            throw new HttpException(400, 'HTTP/1.1 requires exactly one Host field.');
        }
        if ($method === 'CONNECT') {
            throw new HttpException(501, 'CONNECT tunneling is not supported.');
        }
        if ($target[0] !== '/' || str_contains($target, '#')) {
            $headers = self::validateTarget($method, $target, $headers);
        }
        // Only an absolute-form target replaces the Host the client sent; its
        // rare requests carry a complete map instead of a head.
        $fields = $target[0] !== '/' && $target !== '*' ? self::split($head, $headers['host'][0]) : null;

        if (!isset($headers['content-length']) && !isset($headers['transfer-encoding'])) {
            if (
                isset($headers['expect'])
                && ($version !== '1.1' || strtolower(implode(',', $headers['expect'])) !== '100-continue')
            ) {
                throw new HttpException(417, 'Unsupported expectation.');
            }
            return (
                $fields !== null
                    ? new Request($method, $target, $version, $fields)
                    : new Request(
                        $method,
                        $target,
                        $version,
                        [],
                        null,
                        [],
                        null,
                        $head,
                        isset($headers['connection']) ? implode(', ', $headers['connection']) : null,
                    )
            );
        }

        $this->method = $method;
        $this->target = $target;
        $this->version = $version;
        $this->headers = $headers;
        $this->head = $head;
        $this->fieldMap = $fields;
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
            if (strlen($digits) > strlen($limit) || strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0) {
                throw new HttpException(413, 'Request body is too large.');
            }
            $this->remaining = (int) $digits;
            $this->state = $this->remaining > 0 ? 'fixed' : 'complete';
        }
        if ($encodings !== []) {
            $codings = array_map(trim(...), explode(',', strtolower(implode(',', $encodings))));
            if (
                $this->version !== '1.1'
                || end($codings) !== 'chunked'
                || count(array_keys($codings, 'chunked', true)) !== 1
            ) {
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
        return $this->state === 'complete' ? $this->complete() : null;
    }

    /**
     * Validates the field block that starts at $offset and ends with the
     * blank line, and extracts only the fields that decide framing and
     * connection handling.
     *
     * @return array<string, list<string>>
     */
    private function scanFields(string $head, int $offset): array
    {
        // Every CRLF in a head ends the request line, a field or the block.
        if ((substr_count($head, "\r\n") - 2) > $this->maxHeaderCount) {
            throw new HttpException(431, 'Too many header fields.');
        }
        // One anchored scan without captures validates every field up to the
        // blank line, so no malformed field can be skipped.
        if (preg_match(self::VALID_FIELDS, $head, offset: $offset) !== 1) {
            throw new HttpException(400, 'Malformed header field.');
        }
        $framing = [];
        // The request line's CRLF anchors the first field name.
        $count = (int) preg_match_all(self::FRAMING_FIELDS, $head, $fields, PREG_PATTERN_ORDER, $offset - 2);
        for ($index = 0; $index < $count; ++$index) {
            $framing[strtolower($fields[1][$index])][] = rtrim($fields[2][$index], " \t");
        }
        return $framing;
    }

    /**
     * Splits a validated head into its complete field map. $host is the
     * authority of an absolute-form target, which replaces the Host field.
     *
     * @internal
     *
     * @return array<string, list<string>>
     */
    public static function split(string $head, ?string $host = null): array
    {
        // The fields follow the request line's CRLF.
        $count = (int) preg_match_all(self::FIELDS, $head, $fields, PREG_PATTERN_ORDER, strpos($head, "\r\n") + 2) - 1;
        $headers = [];
        $names = $fields[1];
        $values = $fields[2];
        for ($index = 0; $index < $count; ++$index) {
            // The scan already consumed leading whitespace.
            $value = $values[$index];
            if ($value !== '' && ($value[-1] === ' ' || $value[-1] === "\t")) {
                $value = rtrim($value, " \t");
            }
            $headers[strtolower($names[$index])][] = $value;
        }
        if ($host !== null) {
            $headers['host'] = [$host];
        }
        return $headers;
    }

    /**
     * Values of one lowercase field name in a validated head, or null.
     *
     * @internal
     *
     * @return list<string>|null
     */
    public static function find(string $head, string $name): ?array
    {
        // The request line's CRLF anchors the first field name.
        $offset = strpos($head, "\r\n") + 2;
        // A colon can never be part of a field name; without this guard the
        // pattern could read a name and the start of its value as one name.
        if (
            str_contains($name, ':')
            || !preg_match_all(
                '/\r\n' . preg_quote($name, '/') . ':[ \t]*+([^\r]*)/i',
                $head,
                $fields,
                PREG_PATTERN_ORDER,
                $offset - 2,
            )
        ) {
            return null;
        }
        $values = [];
        foreach ($fields[1] as $value) {
            $values[] = rtrim($value, " \t");
        }
        return $values;
    }

    /**
     * Validates asterisk and absolute-form targets; origin-form targets with
     * no fragment never reach this path.
     *
     * @param array<string, list<string>> $headers
     * @return array<string, list<string>>
     */
    private static function validateTarget(string $method, string $target, array $headers): array
    {
        if ($target === '*') {
            if ($method !== 'OPTIONS') {
                throw new HttpException(400, 'Asterisk target requires OPTIONS.');
            }
            return $headers;
        }
        if (str_contains($target, '#')) {
            throw new HttpException(400, 'Fragments are not allowed in request targets.');
        }
        $parts = parse_url($target);
        if (
            $parts === false
            || !isset($parts['host'], $parts['scheme'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new HttpException(400, 'Invalid request target.');
        }
        $headers['host'] = [$parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')];
        return $headers;
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

    /**
     * field() for lines an application repeats, such as response headers.
     *
     * @return array{string, string}
     */
    public static function knownField(string $line): array
    {
        $field = self::$fields[$line] ?? null;
        if ($field === null) {
            $field = self::field($line);
            if (strlen($line) <= 256) {
                if (count(self::$fields) >= 256) {
                    self::$fields = [];
                }
                self::$fields[$line] = $field;
            }
        }
        return $field;
    }

    private function chunkLength(string $line): int
    {
        $token = self::TOKEN;
        $quoted = '"(?:[\x09\x20\x21\x23-\x5B\x5D-\x7E\x80-\xFF]|\\\\[\x09\x20-\x7E\x80-\xFF])*"';
        if (!preg_match(
            '/^([0-9A-Fa-f]+)(?:[ \t]*;[ \t]*'
            . $token
            . '(?:[ \t]*=[ \t]*(?:'
            . $token
            . '|'
            . $quoted
            . '))?)*[ \t]*$/D',
            $line,
            $match,
        )) {
            throw new HttpException(400, 'Invalid chunk size or extension.');
        }
        $digits = strtolower(ltrim($match[1], '0') ?: '0');
        $limit = dechex($this->maxBodyBytes);
        if (strlen($digits) > strlen($limit) || strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0) {
            throw new HttpException(413, 'Chunk is too large.');
        }
        return (int) hexdec($digits);
    }

    private function takeLine(int $limit): ?string
    {
        $end = strpos($this->buffer, "\r\n");
        if ($end === false && strlen($this->buffer) > $limit || $end !== false && ($end + 2) > $limit) {
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
        $request = $this->fieldMap !== null
            ? new Request($this->method, $this->target, $this->version, $this->fieldMap, $this->body, $this->trailers)
            : new Request(
                $this->method,
                $this->target,
                $this->version,
                [],
                $this->body,
                $this->trailers,
                null,
                $this->head,
                isset($this->headers['connection']) ? implode(', ', $this->headers['connection']) : null,
            );
        $this->fieldMap = null;
        $this->body = null;
        $this->head = '';
        $this->headers = $this->trailers = [];
        $this->state = 'headers';
        $this->headerScan = 0;
        $this->continue = false;
        return $request;
    }
}
