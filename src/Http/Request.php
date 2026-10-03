<?php

declare(strict_types=1);

namespace Crab\Engine\Http;

use Crab\Engine\Async\Cancellation;

/**
 * An immutable request. A received request keeps its validated head and
 * builds the complete field map only when a handler asks for it.
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
            if ($this->partial) {
                $this->fields = Parser::split($this->head, $this->fieldsAt, $this->fields);
                $this->partial = false;
            }
            return $this->fields;
        }
    }

    /**
     * The complete map, or only the parser's framing fields while partial.
     *
     * @var array<string, list<string>>
     */
    private array $fields;
    private bool $partial = false;
    private string $head = '';
    private int $fieldsAt = 0;

    /**
     * @param array<string, list<string>> $headers
     * @param array<string, list<string>> $trailers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $target,
        public readonly string $version,
        array $headers,
        public readonly Body $body,
        public readonly array $trailers = [],
        public readonly Cancellation $cancellation = new Cancellation(),
    ) {
        $this->fields = $headers;
    }

    /**
     * A request whose head the parser has validated. $framing holds the
     * fields the parser already extracted: Host, Connection, Expect,
     * Content-Length and Transfer-Encoding.
     *
     * @internal
     *
     * @param array<string, list<string>> $framing
     * @param array<string, list<string>> $trailers
     */
    public static function received(
        string $method,
        string $target,
        string $version,
        string $head,
        int $fieldsAt,
        array $framing,
        Body $body,
        array $trailers,
    ): self {
        $request = new self($method, $target, $version, $framing, $body, $trailers, new Cancellation());
        $request->partial = true;
        $request->head = $head;
        $request->fieldsAt = $fieldsAt;
        return $request;
    }

    public function header(string $name): ?string
    {
        $name = strtolower($name);
        if (!$this->partial || isset(Parser::FRAMING_NAMES[$name])) {
            $values = $this->fields[$name] ?? null;
        } else {
            // One field does not need the complete map.
            $values = Parser::find($this->head, $this->fieldsAt, $name);
        }
        return $values === null ? null : implode(', ', $values);
    }

    public function hasToken(string $name, string $token): bool
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
        $values = $this->fields['connection'] ?? null;
        if ($values === null) {
            return $this->version === '1.1';
        }
        if (isset($values[0]) && !isset($values[1])) {
            // The two usual spellings need no token-list scan.
            if ($values[0] === 'keep-alive' || $values[0] === 'Keep-Alive') {
                return true;
            }
            if ($values[0] === 'close') {
                return false;
            }
        }
        return !$this->hasToken('connection', 'close')
            && ($this->version === '1.1' || $this->hasToken('connection', 'keep-alive'));
    }

    public function path(): string
    {
        if (str_starts_with($this->target, '/')) {
            $query = strpos($this->target, '?');
            return $query === false ? $this->target : substr($this->target, 0, $query);
        }
        return parse_url($this->target, PHP_URL_PATH) ?: '/';
    }
}
