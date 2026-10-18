<?php

declare(strict_types=1);

namespace Crab\Engine\Http;

use Crab\Engine\Async\Cancellation;

/**
 * A read-only request. A received request keeps its validated head and builds
 * the complete field map, an empty body and its cancellation token only when
 * a handler asks for them.
 */
final class Request
{
    /**
     * Lowercase names to value lists, in arrival order.
     *
     * @var array<string, list<string>>
     */
    public array $headers {
        get {
            if ($this->head !== '') {
                $this->fields = Parser::split($this->head);
                $this->head = '';
            }
            return $this->fields;
        }
    }

    /**
     * The complete request body; empty when the request carried none.
     */
    public Body $body {
        get => $this->content ??= new Body();
    }

    /**
     * Lowercase trailer names to value lists.
     *
     * @var array<string, list<string>>
     */
    public array $trailers {
        get => $this->trailerFields;
    }

    /**
     * Cancelled when the request completes, times out, upgrades or loses its
     * connection.
     */
    public Cancellation $cancellation {
        get {
            if ($this->token === null) {
                $this->token = new Cancellation();
                if ($this->completed) {
                    $this->token->cancel();
                }
            }
            return $this->token;
        }
    }

    /**
     * @var array<string, list<string>>
     */
    private array $fields = [];
    /**
     * @var array<string, list<string>>
     */
    private array $trailerFields = [];
    private ?Body $content = null;
    private ?Cancellation $token = null;
    private bool $completed = false;

    /**
     * The parameters after $cancellation describe a head the parser has
     * already validated; application code leaves them at their defaults.
     *
     * @param array<string, list<string>> $headers
     * @param array<string, list<string>> $trailers
     * @param string $head @internal Validated head; replaces $headers, which is built from it on demand.
     * @param string|null $connection @internal Joined Connection values of $head.
     */
    /**
     * The address the connection came from, "203.0.113.7:51324"; empty when the request did not come over one.
     */
    public string $peer = '';

    public function __construct(
        public readonly string $method,
        public readonly string $target,
        public readonly string $version,
        array $headers,
        ?Body $body = null,
        array $trailers = [],
        ?Cancellation $cancellation = null,
        private string $head = '',
        private ?string $connection = null,
    ) {
        // Only what a request actually carries is stored.
        if ($headers !== []) {
            $this->fields = $headers;
        }
        if ($body !== null) {
            $this->content = $body;
        }
        if ($trailers !== []) {
            $this->trailerFields = $trailers;
        }
        if ($cancellation !== null) {
            $this->token = $cancellation;
        }
    }

    /**
     * Cancels an observed token; an unobserved one is created cancelled later.
     *
     * @internal
     */
    public function complete(): void
    {
        $this->completed = true;
        $this->token?->cancel();
    }

    public function header(string $name): ?string
    {
        $name = strtolower($name);
        if ($this->head === '') {
            $values = $this->fields[$name] ?? null;
            return $values === null ? null : implode(', ', $values);
        }
        if ($name === 'connection') {
            return $this->connection;
        }
        // One field does not need the complete map.
        $values = Parser::find($this->head, $name);
        return $values === null ? null : implode(', ', $values);
    }

    public function hasToken(string $name, #[\SensitiveParameter] string $token): bool
    {
        $header = $this->header($name);
        if ($header === null) {
            return $token === '';
        }
        $tokens = array_map(trim(...), explode(',', strtolower($header)));
        return in_array(strtolower($token), $tokens, true);
    }

    public function keepAlive(): bool
    {
        if ($this->head !== '') {
            $connection = $this->connection;
        } else {
            // Caller-supplied maps may hold references; read them every time.
            $values = $this->fields['connection'] ?? null;
            $connection = $values === null ? null : implode(', ', $values);
        }
        if ($connection === null) {
            return $this->version === '1.1';
        }
        // The two usual spellings need no token-list scan.
        if ($connection === 'keep-alive' || $connection === 'Keep-Alive') {
            return true;
        }
        if ($connection === 'close') {
            return false;
        }
        $tokens = array_map(trim(...), explode(',', strtolower($connection)));
        return !in_array('close', $tokens, true) && ($this->version === '1.1' || in_array('keep-alive', $tokens, true));
    }

    public function path(): string
    {
        $target = $this->target;
        if ($target[0] === '/') {
            $query = strpos($target, '?');
            return $query === false ? $target : substr($target, 0, $query);
        }
        return parse_url($this->target, PHP_URL_PATH) ?: '/';
    }
}
