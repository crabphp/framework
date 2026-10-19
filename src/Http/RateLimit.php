<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Cache\Cache;
use Crab\Contracts\Http\Middleware;
use Crab\Contracts\Http\Request;
use Crab\Contracts\Http\UsesContainer;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware that lets each client make so many requests in a length of
 * time, and answers the rest with 429 and when to try again:
 *
 *     Route::post('/login', Login::class)->middleware(new RateLimit(5, per: 60));
 *
 * A client is the logged-in user when the request has one, and the
 * address it comes from otherwise, an IPv6 address by its /64. The
 * counts are kept in the cache, so workers and servers that share a
 * cache share the limit.
 *
 * Time is cut into windows of $per seconds and each window counts from
 * zero, so across the edge of two windows a client can be let through
 * up to twice the limit in quick succession.
 */
final readonly class RateLimit implements Middleware, UsesContainer
{
    /**
     * @param int $limit Requests a client may make in one window.
     * @param int $per The length of a window, in seconds.
     * @param \Closure(ServerRequestInterface): string|null $by Who the request counts against, in place of the user or the address.
     * @param string $name Tells this limit from another; limits of one name, limit and length count together.
     * @param Cache|null $cache Where the counts are kept; taken from the container when the middleware is on a route.
     */
    public function __construct(
        private int $limit,
        private int $per = 60,
        private ?\Closure $by = null,
        private string $name = '',
        private ?Cache $cache = null,
        private ?ClockInterface $clock = null,
    ) {
        if ($limit < 1 || $per < 1) {
            throw new \InvalidArgumentException(
                "A rate limit is at least one request per one second, not {$limit} per {$per}.",
            );
        }
        if (preg_match('/^[A-Za-z0-9_-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("\"{$name}\" cannot name a rate limit: use letters, digits, - and _.");
        }
    }

    public function withContainer(ContainerInterface $container): static
    {
        if ($this->cache !== null) {
            return $this;
        }
        if (!$container->has(Cache::class)) {
            throw new \LogicException(
                'A rate limit keeps its counts in the cache: register the cache provider, CacheProvider.',
            );
        }
        return new self(
            $this->limit,
            $this->per,
            $this->by,
            $this->name,
            $container->get(Cache::class),
            $this->clock ?? ($container->has(ClockInterface::class) ? $container->get(ClockInterface::class) : null),
        );
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->cache === null) {
            throw new \LogicException(
                'This rate limit has no cache to keep its counts in. Put it on a route or in withMiddleware(), or give it one: new RateLimit(60, cache: $cache).',
            );
        }
        $now = $this->clock?->now()->getTimestamp() ?? time();
        $window = intdiv($now, $this->per);
        $left = (($window + 1) * $this->per) - $now;
        // Hashed, since an address or an id may hold what a cache key may not.
        $key = "rate.{$this->name}.{$this->limit}-{$this->per}." . sha1($this->client($request)) . ".{$window}";
        $count = $this->cache->increment($key, 1, $left);
        $headers = [
            'X-RateLimit-Limit' => (string) $this->limit,
            'X-RateLimit-Remaining' => (string) max(0, $this->limit - $count),
        ];
        if ($count > $this->limit) {
            return ErrorResponse::make($request, 429, "Too many requests. Try again in {$left} seconds.", [
                ...$headers,
                'Retry-After' => (string) $left,
            ]);
        }
        $response = $handler->handle($request);
        foreach ($headers as $header => $value) {
            $response = $response->withHeader($header, $value);
        }
        return $response;
    }

    private function client(ServerRequestInterface $request): string
    {
        if ($this->by !== null) {
            return ($this->by)($request);
        }
        $user = $request->getAttribute(Identity::ATTRIBUTE);
        if ($user instanceof Identity) {
            return 'user ' . $user->id;
        }
        $address = $request instanceof Request
            ? $request->ip()
            : (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        return 'address ' . self::network($address);
    }

    /**
     * An IPv6 client is counted by its /64: one machine is usually given
     * that many addresses and may take a new one for every request.
     */
    private static function network(string $address): string
    {
        $packed = str_contains($address, ':') ? @inet_pton($address) : false;
        return $packed === false || strlen($packed) !== 16
            ? $address
            : (string) inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
}
