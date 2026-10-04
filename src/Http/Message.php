<?php

declare(strict_types=1);

namespace Crab\Http;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The header and body behavior shared by requests and responses.
 */
abstract class Message implements MessageInterface
{
    protected string $protocol = '1.1';
    /**
     * Lowercase names to value lists.
     *
     * @var array<string, list<string>>
     */
    protected array $headers = [];
    /**
     * Lowercase names to the spelling they were given in, where it differs.
     *
     * @var array<string, string>
     */
    protected array $names = [];
    /**
     * A string becomes a stream when one is first asked for.
     */
    protected string|StreamInterface $body = '';

    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    public function withProtocolVersion(string $version): static
    {
        $new = clone $this;
        $new->protocol = $version;
        return $new;
    }

    public function getHeaders(): array
    {
        $this->load();
        if ($this->names === []) {
            return $this->headers;
        }
        $headers = [];
        foreach ($this->headers as $key => $values) {
            $headers[$this->names[$key] ?? $key] = $values;
        }
        return $headers;
    }

    public function hasHeader(string $name): bool
    {
        $this->load();
        return isset($this->headers[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        $this->load();
        return $this->headers[strtolower($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): static
    {
        $this->load();
        $new = clone $this;
        $new->put($name, $value, false);
        return $new;
    }

    public function withAddedHeader(string $name, $value): static
    {
        $this->load();
        $new = clone $this;
        $new->put($name, $value, true);
        return $new;
    }

    public function withoutHeader(string $name): static
    {
        $this->load();
        $key = strtolower($name);
        if (!isset($this->headers[$key])) {
            return $this;
        }
        $new = clone $this;
        unset($new->headers[$key], $new->names[$key]);
        return $new;
    }

    public function getBody(): StreamInterface
    {
        if (is_string($this->body)) {
            $this->body = new Stream($this->body);
        }
        return $this->body;
    }

    public function withBody(StreamInterface $body): static
    {
        $new = clone $this;
        $new->body = $body;
        return $new;
    }

    /**
     * Brings in headers held elsewhere before they are read or changed.
     */
    protected function load(): void
    {
    }

    /**
     * Validates one field and stores it, replacing or extending its values.
     */
    protected function put(string $name, mixed $value, bool $append): void
    {
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Header names must be HTTP tokens.');
        }
        $values = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            if (!is_string($item) && !is_int($item) && !is_float($item)) {
                throw new \InvalidArgumentException('Header values must be strings or numbers.');
            }
            $item = trim((string) $item, " \t");
            if (preg_match('/^[\x20\x09\x21-\x7E\x80-\xFF]*$/D', $item) !== 1) {
                throw new \InvalidArgumentException('Header values must not contain control characters.');
            }
            $values[] = $item;
        }
        if ($values === []) {
            throw new \InvalidArgumentException('A header needs at least one value.');
        }
        $key = strtolower($name);
        if ($append && isset($this->headers[$key])) {
            $this->headers[$key] = [...$this->headers[$key], ...$values];
            return;
        }
        $this->headers[$key] = $values;
        if ($key === $name) {
            unset($this->names[$key]);
        } else {
            $this->names[$key] = $name;
        }
    }
}
