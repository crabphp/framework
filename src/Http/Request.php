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
        return !$this->hasToken('connection', 'close')
            && ($this->version === '1.1' || $this->hasToken('connection', 'keep-alive'));
    }

    public function path(): string
    {
        if (str_starts_with($this->target, '/')) {
            return explode('?', $this->target, 2)[0];
        }
        return parse_url($this->target, PHP_URL_PATH) ?: '/';
    }
}
