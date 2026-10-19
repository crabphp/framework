<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Http\Request as RequestContract;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Http\Request as EngineRequest;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

/**
 * A PSR-7 server request. One received by the engine reads from the engine's
 * request and builds its header map, URI, body stream, query, cookies,
 * parsed body and uploaded files only when they are first asked for.
 *
 * The parsed body is read from a form (urlencoded or multipart) or a JSON
 * object or array; a body that claims one of those and is malformed is
 * answered with 400.
 */
final class Request extends Message implements RequestContract
{
    private static ?self $blank = null;

    private ?EngineRequest $source = null;
    /**
     * False while the headers are still only in the engine's request.
     */
    private bool $loaded = true;
    /**
     * Null until an engine request's URI is first asked for.
     */
    private ?UriInterface $uri;
    /**
     * A target set explicitly; otherwise it follows the URI.
     */
    private ?string $target = null;
    private ?array $query = null;
    private ?array $cookies = null;
    private array $files = [];
    private bool $filesKnown = false;
    private mixed $parsed = null;
    private bool $parsedKnown = false;
    private array $attributes = [];
    private ?Cancellation $token = null;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        private string $method,
        UriInterface|string $uri = '/',
        array $headers = [],
        string|StreamInterface $body = '',
        string $version = '1.1',
        private array $serverParams = [],
    ) {
        self::assertMethod($method);
        $this->uri = is_string($uri) ? new Uri($uri) : $uri;
        foreach ($headers as $name => $value) {
            $this->put((string) $name, $value, true);
        }
        if (!isset($this->headers['host'])) {
            $this->hostFromUri();
        }
        $this->body = $body;
        $this->protocol = $version;
    }

    /**
     * Wraps a request the engine has received and validated.
     */
    public static function fromEngine(EngineRequest $source): self
    {
        // A clone skips the constructor's URI parsing and validation.
        $request = clone self::$blank ??= new self('GET');
        $request->method = $source->method;
        $request->protocol = $source->version;
        $request->source = $source;
        $request->loaded = false;
        $request->uri = null;
        return $request;
    }

    public function hasHeader(string $name): bool
    {
        // One field does not need the complete map.
        return $this->loaded ? parent::hasHeader($name) : $this->source->header($name) !== null;
    }

    public function getHeaderLine(string $name): string
    {
        return $this->loaded ? parent::getHeaderLine($name) : $this->source->header($name) ?? '';
    }

    public function getBody(): StreamInterface
    {
        if ($this->body === '' && $this->source !== null) {
            $this->body = new Stream($this->source->body->contents());
        }
        return parent::getBody();
    }

    public function getRequestTarget(): string
    {
        if ($this->target !== null) {
            return $this->target;
        }
        if ($this->uri === null) {
            return $this->source->target;
        }
        $target = $this->uri->getPath();
        if ($target === '') {
            $target = '/';
        }
        $query = $this->uri->getQuery();
        return $query === '' ? $target : $target . '?' . $query;
    }

    public function withRequestTarget(string $requestTarget): static
    {
        if (preg_match('/\s/', $requestTarget) === 1) {
            throw new \InvalidArgumentException('A request target must not contain whitespace.');
        }
        $new = clone $this;
        $new->target = $requestTarget;
        return $new;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): static
    {
        self::assertMethod($method);
        $new = clone $this;
        $new->method = $method;
        return $new;
    }

    public function getUri(): UriInterface
    {
        if ($this->uri === null) {
            $target = $this->source->target;
            $this->uri = $target[0] === '/' ? Uri::received($target, $this->source) : new Uri($target);
        }
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        $this->load();
        $new = clone $this;
        $new->uri = $uri;
        if (!$preserveHost || !isset($new->headers['host'])) {
            $new->hostFromUri();
        }
        return $new;
    }

    public function getServerParams(): array
    {
        $peer = $this->peer();
        return $peer === '' ? $this->serverParams : ['REMOTE_ADDR' => $peer] + $this->serverParams;
    }

    public function ip(): string
    {
        $trusted = $this->getAttribute(TrustedProxies::ATTRIBUTE);
        return is_string($trusted) && $trusted !== '' ? $trusted : $this->peer();
    }

    /**
     * The address at the other end of the connection, without its port.
     */
    private function peer(): string
    {
        $peer = $this->source->peer ?? '';
        if ($peer === '') {
            return (string) ($this->serverParams['REMOTE_ADDR'] ?? '');
        }
        $colon = strrpos($peer, ':');
        return trim($colon === false ? $peer : substr($peer, 0, $colon), '[]');
    }

    public function getCookieParams(): array
    {
        if ($this->cookies === null) {
            $this->cookies = [];
            foreach (explode(';', $this->getHeaderLine('cookie')) as $pair) {
                $parts = explode('=', $pair, 2);
                $name = trim($parts[0]);
                // As in $_COOKIE, the first of several same-named cookies wins.
                if (isset($parts[1]) && $name !== '') {
                    $this->cookies[$name] ??= urldecode(trim($parts[1]));
                }
            }
        }
        return $this->cookies;
    }

    public function withCookieParams(array $cookies): static
    {
        $new = clone $this;
        $new->cookies = $cookies;
        return $new;
    }

    public function getQueryParams(): array
    {
        if ($this->query === null) {
            if ($this->uri === null) {
                // The query of a received target needs no URI object.
                $start = strpos($this->source->target, '?');
                $string = $start === false ? '' : substr($this->source->target, $start + 1);
            } else {
                $string = $this->uri->getQuery();
            }
            // Beyond max_input_vars PHP keeps what it has read and warns; keep that, without the warning.
            @parse_str($string, $this->query);
        }
        return $this->query;
    }

    public function withQueryParams(array $query): static
    {
        $new = clone $this;
        $new->query = $query;
        return $new;
    }

    public function getUploadedFiles(): array
    {
        if (!$this->filesKnown) {
            if (str_starts_with(strtolower($this->getHeaderLine('content-type')), 'multipart/form-data')) {
                $this->readMultipart();
            }
            $this->filesKnown = true;
        }
        return $this->files;
    }

    public function withUploadedFiles(array $uploadedFiles): static
    {
        array_walk_recursive($uploadedFiles, static function (mixed $file): void {
            if (!$file instanceof UploadedFileInterface) {
                throw new \InvalidArgumentException('Uploaded files must be a tree of UploadedFileInterface.');
            }
        });
        $new = clone $this;
        $new->files = $uploadedFiles;
        $new->filesKnown = true;
        return $new;
    }

    public function getParsedBody(): array|object|null
    {
        if (!$this->parsedKnown) {
            $type = strtolower($this->getHeaderLine('content-type'));
            if (str_starts_with($type, 'application/x-www-form-urlencoded')) {
                @parse_str((string) $this->getBody(), $this->parsed);
            } elseif (str_starts_with($type, 'multipart/form-data')) {
                $this->readMultipart();
            } elseif (preg_match('~^application/(?:[a-z0-9.-]+\+)?json\b~', $type) === 1) {
                $this->parsed = self::json((string) $this->getBody());
            }
            $this->parsedKnown = true;
        }
        return $this->parsed;
    }

    public function withParsedBody(mixed $data): static
    {
        if ($data !== null && !is_array($data) && !is_object($data)) {
            throw new \InvalidArgumentException('A parsed body must be an array, an object or null.');
        }
        $new = clone $this;
        $new->parsed = $data;
        $new->parsedKnown = true;
        return $new;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    public function withAttribute(string $name, mixed $value): static
    {
        $new = clone $this;
        $new->attributes[$name] = $value;
        return $new;
    }

    public function withoutAttribute(string $name): static
    {
        if (!array_key_exists($name, $this->attributes)) {
            return $this;
        }
        $new = clone $this;
        unset($new->attributes[$name]);
        return $new;
    }

    public function query(string $name, mixed $default = null): mixed
    {
        return $this->getQueryParams()[$name] ?? $default;
    }

    public function input(string $name, mixed $default = null): mixed
    {
        $parsed = $this->getParsedBody();
        return is_array($parsed) ? $parsed[$name] ?? $default : $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->getCookieParams()[$name] ?? null;
        return is_string($value) ? $value : $default;
    }

    public function file(string $name): ?UploadedFileInterface
    {
        $file = $this->getUploadedFiles()[$name] ?? null;
        return $file instanceof UploadedFileInterface ? $file : null;
    }

    public function cancellation(): Cancellation
    {
        // A request the engine did not receive is never cancelled.
        return $this->source->cancellation ?? ($this->token ??= new Cancellation());
    }

    protected function load(): void
    {
        if (!$this->loaded) {
            $this->headers = $this->source->headers;
            $this->loaded = true;
        }
    }

    /**
     * Fills the parsed body and the uploaded files from one reading of a
     * multipart body, leaving alone whichever was set explicitly.
     */
    private function readMultipart(): void
    {
        [$fields, $files] = Multipart::parse($this->getHeaderLine('content-type'), $this->bodyResource());
        if (!$this->filesKnown) {
            $this->files = $files;
            $this->filesKnown = true;
        }
        if (!$this->parsedKnown) {
            $this->parsed = $fields;
            $this->parsedKnown = true;
        }
    }

    /**
     * The body as a seekable resource, to read a large one where it is
     * stored: the engine's own storage for a request it received, with no
     * copy made.
     *
     * @return resource
     */
    private function bodyResource(): mixed
    {
        if ($this->body === '' && $this->source !== null) {
            $stream = $this->source->body->stream();
            if ($stream !== null) {
                return $stream;
            }
        }
        $body = $this->getBody();
        if ($body instanceof Stream) {
            return $body->handle();
        }
        // Another implementation's stream is copied once, to disk when it is large.
        $copy = fopen('php://temp', 'w+b');
        if ($copy === false) {
            throw new \RuntimeException('Cannot allocate request body storage.');
        }
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            fwrite($copy, $body->read(65_536));
        }
        return $copy;
    }

    /**
     * @return array<mixed>|null A JSON object or array; null for an empty body or a JSON scalar.
     */
    private static function json(string $body): ?array
    {
        if ($body === '') {
            return null;
        }
        try {
            $value = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new HttpException(400, 'The JSON body is malformed.', previous: $error);
        }
        return is_array($value) ? $value : null;
    }

    /**
     * Sets Host from the URI's authority, first among the headers.
     */
    private function hostFromUri(): void
    {
        $host = $this->uri->getHost();
        if ($host === '') {
            return;
        }
        $port = $this->uri->getPort();
        unset($this->headers['host']);
        $this->headers = ['host' => [$port === null ? $host : $host . ':' . $port]] + $this->headers;
        $this->names['host'] = 'Host';
    }

    private static function assertMethod(string $method): void
    {
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $method) !== 1) {
            throw new \InvalidArgumentException('A request method must be an HTTP token.');
        }
    }
}
