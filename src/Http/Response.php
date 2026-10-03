<?php

declare(strict_types=1);

namespace Crab\Engine\Http;

final readonly class Response
{
    private bool $headersNormalized;
    private array $normalizedHeaders;
    private string $headerBlock;

    /**
     * @param array<string, string|list<string>> $headers
     * @param string|iterable<string> $body
     */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string|iterable $body = '',
    ) {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException('HTTP status must be between 100 and 599.');
        }
    }

    /**
     * @internal Validates when encoding, retaining referenced header behavior.
     *
     * @return array<string, list<string>>
     */
    public function normalizedHeaders(): array
    {
        if (isset($this->normalizedHeaders)) {
            return $this->normalizedHeaders;
        }
        $headers = [];
        foreach ($this->headers as $name => $values) {
            if (!is_string($name)) {
                throw new \InvalidArgumentException('Response headers require named fields.');
            }
            foreach (is_array($values) ? $values : [$values] as $value) {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('Response header values must be strings.');
                }
                try {
                    $field = Parser::knownField($name . ':' . $value);
                } catch (HttpException $error) {
                    throw new \InvalidArgumentException('Invalid response header.', previous: $error);
                }
                $headers[$field[0]][] = $field[1];
            }
        }
        // Fresh responses pay no reference scan or retained-cache allocation.
        if (!isset($this->headersNormalized)) {
            $this->headersNormalized = true;
            return $headers;
        }
        foreach ($this->headers as $name => $values) {
            if (\ReflectionReference::fromArrayElement($this->headers, $name) !== null) {
                return $headers;
            }
            if (is_array($values)) {
                foreach ($values as $index => $_) {
                    if (\ReflectionReference::fromArrayElement($values, $index) !== null) {
                        return $headers;
                    }
                }
            }
        }
        return $this->normalizedHeaders = $headers;
    }

    /**
     * @internal Encoded explicit fields when none of them affects framing,
     * connection handling or the generated Date; null selects the general
     * encoder. Retained only once normalizedHeaders() has established reuse.
     */
    public function headerBlock(): ?string
    {
        if (isset($this->headerBlock)) {
            return $this->headerBlock;
        }
        $headers = $this->normalizedHeaders();
        if (isset($headers['content-length']) || isset($headers['transfer-encoding'])
            || isset($headers['connection']) || isset($headers['date']) || isset($headers['upgrade'])) {
            return null;
        }
        $block = '';
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $block .= $name . ': ' . $value . "\r\n";
            }
        }
        if (isset($this->normalizedHeaders)) {
            $this->headerBlock = $block;
        }
        return $block;
    }

    public static function text(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, ['Content-Type' => 'text/plain; charset=utf-8', ...$headers], $body);
    }

    public static function json(mixed $value, int $status = 200, array $headers = []): self
    {
        return new self($status, ['Content-Type' => 'application/json', ...$headers], json_encode($value, JSON_THROW_ON_ERROR));
    }

    public static function reason(int $status): string
    {
        return match ($status) {
            100 => 'Continue', 101 => 'Switching Protocols', 200 => 'OK', 201 => 'Created',
            202 => 'Accepted', 204 => 'No Content', 205 => 'Reset Content', 206 => 'Partial Content',
            301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified',
            307 => 'Temporary Redirect', 308 => 'Permanent Redirect', 400 => 'Bad Request',
            401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed',
            408 => 'Request Timeout', 411 => 'Length Required', 413 => 'Content Too Large',
            414 => 'URI Too Long', 417 => 'Expectation Failed', 422 => 'Unprocessable Content',
            426 => 'Upgrade Required', 429 => 'Too Many Requests', 431 => 'Request Header Fields Too Large',
            500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway',
            503 => 'Service Unavailable', 504 => 'Gateway Timeout', 505 => 'HTTP Version Not Supported',
            default => 'Response',
        };
    }
}
