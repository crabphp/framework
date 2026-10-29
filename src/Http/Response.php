<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Http\Problem;
use Crab\Engine\Http\Response as EngineResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A PSR-7 response.
 */
final class Response extends Message implements ResponseInterface
{
    private ?Problem $problem = null;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        private int $status = 200,
        array $headers = [],
        string|StreamInterface $body = '',
        private string $reason = '',
    ) {
        self::assertStatus($status);
        foreach ($headers as $name => $value) {
            $this->put((string) $name, $value, true);
        }
        $this->body = $body;
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function text(string $body, int $status = 200, array $headers = []): self
    {
        return self::typed('text/plain; charset=utf-8', $body, $status, $headers);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return self::typed('text/html; charset=utf-8', $body, $status, $headers);
    }

    /**
     * @param array<string, string|list<string>> $headers
     * @param int $flags Further json_encode() flags; a value that cannot be encoded always throws.
     */
    public static function json(mixed $value, int $status = 200, array $headers = [], int $flags = 0): self
    {
        return self::typed('application/json', self::encode($value, $flags), $status, $headers);
    }

    /**
     * A body sent while it is still being made: each chunk goes out as soon
     * as it is yielded, and the generator waits while the client is slower.
     * A chunk may wait on anything, a database or another API, and the
     * response lasts as long as it keeps moving.
     *
     *     return Response::stream($this->export->lines(), 'text/csv');
     *
     * @param iterable<string> $chunks
     * @param array<string, string|list<string>> $headers
     */
    public static function stream(
        iterable $chunks,
        string $type = 'application/octet-stream',
        int $status = 200,
        array $headers = [],
    ): self {
        return new self($status, ['Content-Type' => $type, ...$headers], new ChunkStream($chunks));
    }

    /**
     * Server-sent events, what a browser's EventSource and most AI APIs
     * read: one event for each value yielded, sent as soon as it is.
     * A value is sent as JSON, a string as it is; a string key names
     * the event.
     *
     *     yield ['token' => $token];          data: {"token":"Hi"}
     *     yield 'done' => ['id' => 7];        event: done, data: {"id":7}
     *
     * An action that returns a generator is answered this way.
     *
     * @param iterable<mixed> $events
     * @param array<string, string|list<string>> $headers
     */
    public static function events(iterable $events, array $headers = []): self
    {
        return self::stream(
            self::eventStream($events),
            'text/event-stream; charset=utf-8',
            200,
            // A proxy that buffers or a cache that keeps would hold the events back.
            ['Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no', ...$headers],
        );
    }

    /**
     * @param iterable<mixed> $events
     * @return \Generator<int, string>
     */
    private static function eventStream(iterable $events): \Generator
    {
        foreach ($events as $name => $value) {
            $event = '';
            if (is_string($name)) {
                if (preg_match('/[\r\n]/', $name) === 1) {
                    throw new \InvalidArgumentException('An event\'s name is one line.');
                }
                $event = "event: {$name}\n";
            }
            $data = is_string($value) ? $value : self::encode($value);
            foreach (preg_split('/\r\n|\r|\n/', $data) ?: [''] as $line) {
                $event .= "data: {$line}\n";
            }
            yield $event . "\n";
        }
    }

    private static function encode(mixed $value, int $flags = 0): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | $flags);
        // PHP writes a date as an object of its own internals; a date should be text.
        if (str_contains($json, '"timezone_type":')) {
            $json = json_encode(self::withDatesAsText($value), JSON_THROW_ON_ERROR | $flags);
        }
        return $json;
    }

    /**
     * 201 for something that was made: the value as JSON, and where it can
     * be found, when given, as the Location header.
     */
    public static function created(mixed $value = null, ?string $location = null): self
    {
        $headers = $location === null ? [] : ['Location' => $location];
        return $value === null ? new self(201, $headers) : self::json($value, 201, $headers);
    }

    /**
     * 204: it was done and there is nothing to send back.
     */
    public static function noContent(): self
    {
        return new self(204);
    }

    /**
     * Sends the client to another address. 302 by default; 303 after a
     * form was handled, 301 or 308 when the move is permanent.
     */
    public static function redirect(string $to, int $status = 302): self
    {
        if ($status < 300 || $status > 399) {
            throw new \InvalidArgumentException("A redirect has a status from 300 to 399, not {$status}.");
        }
        return new self($status, ['Location' => $to]);
    }

    /**
     * Sends a file for the client to save. It is read as it is sent, so
     * its size does not matter.
     *
     * @param string|null $name The name the client saves it under; the file's own name when left out.
     * @param string $type Its media type.
     */
    public static function download(string $path, ?string $name = null, string $type = 'application/octet-stream'): self
    {
        $file = is_file($path) ? @fopen($path, 'rb') : false;
        if ($file === false) {
            throw new \RuntimeException("The file to download cannot be read: {$path}");
        }
        return new self(
            200,
            ['Content-Type' => $type, 'Content-Disposition' => self::disposition($name ?? basename($path))],
            new Stream($file),
        );
    }

    /**
     * Sends text made in the action for the client to save as a file, such
     * as a CSV built from a query: attachment($csv, 'orders.csv', 'text/csv').
     *
     * @param string $name The name the client saves it under.
     * @param string $type Its media type.
     */
    public static function attachment(string $body, string $name, string $type = 'application/octet-stream'): self
    {
        return new self(200, ['Content-Type' => $type, 'Content-Disposition' => self::disposition($name)], $body);
    }

    /**
     * A name in plain ASCII for old clients, and the real one, encoded, for the rest.
     */
    private static function disposition(string $name): string
    {
        $plain = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $name);
        return "attachment; filename=\"{$plain}\"; filename*=UTF-8''" . rawurlencode($name);
    }

    /**
     * Adds a Set-Cookie header; with another PSR-7 response, use
     * withAddedHeader('Set-Cookie', (string) $cookie).
     */
    public function withCookie(Cookie $cookie): static
    {
        return $this->withAddedHeader('Set-Cookie', (string) $cookie);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        self::assertStatus($code);
        $new = clone $this;
        $new->status = $code;
        $new->reason = $reasonPhrase;
        // It no longer answers with what the problem says.
        $new->problem = null;
        return $new;
    }

    public function withBody(StreamInterface $body): static
    {
        $new = parent::withBody($body);
        $new->problem = null;
        return $new;
    }

    /**
     * The problem this error response was made for, which the
     * application's ErrorRenderer writes; null for any other response,
     * and once its status or body is changed.
     */
    public function problem(): ?Problem
    {
        return $this->problem;
    }

    public function withProblem(?Problem $problem): static
    {
        $new = clone $this;
        $new->problem = $problem;
        return $new;
    }

    /**
     * The engine's response for this one while its body is still the
     * string it was made with; null once it is a stream.
     *
     * @internal
     */
    public function toEngine(): ?EngineResponse
    {
        if (!is_string($this->body)) {
            return null;
        }
        if ($this->names !== []) {
            return new EngineResponse($this->status, $this->getHeaders(), $this->body);
        }
        $headers = $this->headers;
        // Alone and spelled this way, the type matches the fields the
        // engine has already encoded for its own text and JSON responses.
        if (isset($headers['content-type']) && count($headers) === 1 && count($headers['content-type']) === 1) {
            $headers = ['Content-Type' => $headers['content-type'][0]];
        }
        return new EngineResponse($this->status, $headers, $this->body);
    }

    public function getReasonPhrase(): string
    {
        // The engine's phrase is the one that goes on the wire.
        return $this->reason !== '' ? $this->reason : EngineResponse::reason($this->status);
    }

    /**
     * The value with every date inside it written as ISO 8601 text.
     */
    private static function withDatesAsText(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        } elseif ($value instanceof \BackedEnum) {
            return $value->value;
        } elseif (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::withDatesAsText($item);
            }
        }
        return $value;
    }

    private static function typed(string $type, string $body, int $status, array $headers): self
    {
        $response = new self($status, [], $body);
        // A known-valid field needs no validation.
        $response->headers['content-type'] = [$type];
        foreach ($headers as $name => $value) {
            $response->put((string) $name, $value, false);
        }
        return $response;
    }

    private static function assertStatus(int $status): void
    {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException('HTTP status must be between 100 and 599.');
        }
    }
}
