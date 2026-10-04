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
     */
    public static function json(mixed $value, int $status = 200, array $headers = []): self
    {
        return self::typed('application/json', json_encode($value, JSON_THROW_ON_ERROR), $status, $headers);
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
