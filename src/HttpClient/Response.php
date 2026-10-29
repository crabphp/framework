<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Crab\Contracts\HttpClient\Event;
use Crab\Contracts\HttpClient\Response as ResponseContract;
use Crab\Engine\Http\Response as EngineResponse;
use Crab\Http\Message;
use Crab\Http\Request;
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
        return Request::decodeJson((string) $this->getBody());
    }

    /**
     * @return \Generator<int, string>
     */
    public function lines(): \Generator
    {
        $body = $this->getBody();
        $pending = '';
        while (!$body->eof()) {
            $pending .= $body->read(65_536);
            while (($end = strpos($pending, "\n")) !== false) {
                yield rtrim(substr($pending, 0, $end), "\r");
                $pending = substr($pending, $end + 1);
            }
        }
        if ($pending !== '') {
            yield rtrim($pending, "\r");
        }
    }

    /**
     * @return \Generator<int, Event>
     */
    public function events(): \Generator
    {
        $name = '';
        $data = null;
        $id = null;
        foreach ($this->lines() as $line) {
            if ($line === '') {
                // A blank line ends an event, and one without data is none.
                if ($data !== null) {
                    yield new Event($name === '' ? 'message' : $name, $data, $id);
                }
                $name = '';
                $data = null;
                continue;
            }
            if ($line[0] === ':') {
                continue;
            }
            [$field, $value] = str_contains($line, ':') ? explode(':', $line, 2) : [$line, ''];
            if (str_starts_with($value, ' ')) {
                $value = substr($value, 1);
            }
            match ($field) {
                'event' => $name = $value,
                'data' => $data = $data === null ? $value : "{$data}\n{$value}",
                'id' => $id = $value,
                default => null,
            };
        }
        if ($data !== null) {
            yield new Event($name === '' ? 'message' : $name, $data, $id);
        }
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
