<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Engine\Http\Response as EngineResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A PSR-7 response.
 */
final class Response extends Message implements ResponseInterface
{
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
        $json = json_encode($value, JSON_THROW_ON_ERROR | $flags);
        // PHP writes a date as an object of its own internals; a date should be text.
        if (str_contains($json, '"timezone_type":')) {
            $json = json_encode(self::withDatesAsText($value), JSON_THROW_ON_ERROR | $flags);
        }
        return self::typed('application/json', $json, $status, $headers);
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
        return new self(200, ['Content-Type' => $type, 'Content-Disposition' => self::disposition($name ?? basename($path))], new Stream($file));
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
        return $new;
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
