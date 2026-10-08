<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Crab\Contracts\HttpClient\Response as ResponseContract;
use Crab\Engine\Http\Response as EngineResponse;
use Crab\Http\Message;
use Psr\Http\Message\StreamInterface;

/**
 * The response another server sent.
 */
final class Response extends Message implements ResponseContract
{
    /**
     * @param array<string, list<string>> $headers As received; a field that is not valid HTTP throws.
     * @param string $request The request this answers, by method, host and path, for throw() to name.
     */
    public function __construct(
        private int $status,
        array $headers = [],
        string|StreamInterface $body = '',
        private string $reason = '',
        string $version = '1.1',
        private readonly string $request = '',
    ) {
        foreach ($headers as $name => $values) {
            $this->put((string) $name, $values, true);
        }
        $this->body = $body;
        $this->protocol = $version;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function throw(): static
    {
        if (!$this->ok()) {
            throw new ResponseException($this, $this->request);
        }
        return $this;
    }

    public function json(): mixed
    {
        return json_decode((string) $this->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        if ($code < 100 || $code > 599) {
            throw new \InvalidArgumentException('HTTP status must be between 100 and 599.');
        }
        $new = clone $this;
        $new->status = $code;
        $new->reason = $reasonPhrase;
        return $new;
    }

    public function getReasonPhrase(): string
    {
        return $this->reason !== '' ? $this->reason : EngineResponse::reason($this->status);
    }
}
