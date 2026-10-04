<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Engine\Http\Request as EngineRequest;
use Psr\Http\Message\UriInterface;

/**
 * A PSR-7 URI. Scheme and host are kept lowercase; path, query and fragment
 * are percent-encoded without encoding an existing escape twice.
 */
final class Uri implements UriInterface
{
    private const PORTS = ['http' => 80, 'https' => 443];
    private const PATH = '/(?:[^a-zA-Z0-9_\-.~!$&\'()*+,;=%:@\/]++|%(?![A-Fa-f0-9]{2}))/';
    private const QUERY = '/(?:[^a-zA-Z0-9_\-.~!$&\'()*+,;=%:@\/?]++|%(?![A-Fa-f0-9]{2}))/';
    /**
     * Characters that never need encoding in a path, query or fragment.
     */
    private const SAFE = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-.~!$&\'()*+,;=:@/';

    /**
     * A received request whose Host has not been read into this URI yet.
     */
    private ?EngineRequest $source = null;

    private string $scheme = '';
    private string $userInfo = '';
    private string $host = '';
    private ?int $port = null;
    private string $path = '';
    private string $query = '';
    private string $fragment = '';

    public function __construct(string $uri = '')
    {
        if ($uri === '') {
            return;
        }
        $parts = parse_url($uri);
        if ($parts === false) {
            throw new \InvalidArgumentException('The URI cannot be parsed.');
        }
        $this->scheme = strtolower($parts['scheme'] ?? '');
        $this->userInfo = $parts['user'] ?? '';
        if (isset($parts['pass'])) {
            $this->userInfo .= ':' . $parts['pass'];
        }
        $this->host = strtolower($parts['host'] ?? '');
        $this->port = $parts['port'] ?? null;
        $this->path = self::encode($parts['path'] ?? '', self::PATH);
        $this->query = self::encode($parts['query'] ?? '', self::QUERY);
        $this->fragment = self::encode($parts['fragment'] ?? '', self::QUERY);
    }

    /**
     * The URI of a request received in origin form. Its path and query are
     * ready at once; scheme, host and port come from Host when first needed.
     *
     * @internal
     */
    public static function received(string $target, EngineRequest $source): self
    {
        $uri = new self();
        $uri->source = $source;
        $query = strpos($target, '?');
        if ($query === false) {
            $uri->path = self::encode($target, self::PATH);
        } else {
            $uri->path = self::encode(substr($target, 0, $query), self::PATH);
            $uri->query = self::encode(substr($target, $query + 1), self::QUERY);
        }
        return $uri;
    }

    public function getScheme(): string
    {
        $this->source === null || $this->resolve();
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        $this->source === null || $this->resolve();
        if ($this->host === '') {
            return '';
        }
        $authority = $this->userInfo === '' ? $this->host : $this->userInfo . '@' . $this->host;
        $port = $this->getPort();
        return $port === null ? $authority : $authority . ':' . $port;
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        $this->source === null || $this->resolve();
        return $this->host;
    }

    public function getPort(): ?int
    {
        $this->source === null || $this->resolve();
        return $this->port === (self::PORTS[$this->scheme] ?? null) ? null : $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function withScheme(string $scheme): static
    {
        return $this->with('scheme', strtolower($scheme));
    }

    public function withUserInfo(string $user, ?string $password = null): static
    {
        return $this->with('userInfo', $password === null || $password === '' ? $user : $user . ':' . $password);
    }

    public function withHost(string $host): static
    {
        return $this->with('host', strtolower($host));
    }

    public function withPort(?int $port): static
    {
        if ($port !== null && ($port < 0 || $port > 65535)) {
            throw new \InvalidArgumentException('A port must be between 0 and 65535.');
        }
        return $this->with('port', $port);
    }

    public function withPath(string $path): static
    {
        return $this->with('path', self::encode($path, self::PATH));
    }

    public function withQuery(string $query): static
    {
        return $this->with('query', self::encode($query, self::QUERY));
    }

    public function withFragment(string $fragment): static
    {
        return $this->with('fragment', self::encode($fragment, self::QUERY));
    }

    public function __toString(): string
    {
        $authority = $this->getAuthority();
        $uri = $this->scheme === '' ? '' : $this->scheme . ':';
        $path = $this->path;
        if ($authority !== '') {
            $uri .= '//' . $authority;
            if ($path !== '' && $path[0] !== '/') {
                $path = '/' . $path;
            }
        } elseif (str_starts_with($path, '//')) {
            // Without an authority, a doubled slash would be read as one.
            $path = '/' . ltrim($path, '/');
        }
        $uri .= $path;
        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }
        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }
        return $uri;
    }

    private function with(string $property, string|int|null $value): static
    {
        $this->source === null || $this->resolve();
        if ($this->$property === $value) {
            return $this;
        }
        $new = clone $this;
        $new->$property = $value;
        return $new;
    }

    private static function encode(string $value, string $unsafe): string
    {
        if (strspn($value, self::SAFE) === strlen($value)) {
            return $value;
        }
        return preg_replace_callback($unsafe, static fn(array $match): string => rawurlencode($match[0]), $value);
    }

    /**
     * Reads scheme, host and port from the received request's Host field;
     * a request without one has no authority.
     */
    private function resolve(): void
    {
        $host = $this->source->header('host');
        $this->source = null;
        $parts = $host === null ? false : parse_url('http://' . $host);
        if ($parts !== false && isset($parts['host'])) {
            $this->scheme = 'http';
            $this->host = strtolower($parts['host']);
            $this->port = $parts['port'] ?? null;
        }
    }
}
