<?php

declare(strict_types=1);

namespace Crab\Engine\Http;

use Crab\Engine\Async\Cancellation;

final readonly class Request
{
    /**
     * @param array<string, list<string>> $headers
     * @param array<string, list<string>> $trailers
     */
    public function __construct(
        public string $method,
        public string $target,
        public string $version,
        public array $headers,
        public Body $body,
        public array $trailers = [],
        public Cancellation $cancellation = new Cancellation(),
    ) {}

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? null;
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
        if (!isset($this->headers['connection'])) {
            return $this->version === '1.1';
        }
        $values = $this->headers['connection'];
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
