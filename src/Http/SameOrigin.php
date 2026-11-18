<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Http\Handler;
use Crab\Contracts\Http\Middleware;
use Crab\Contracts\Http\Request;
use Crab\Contracts\Http\UsesContainer;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Middleware that refuses a request that can change something unless it
 * comes from the application's own pages: any method but GET, HEAD,
 * OPTIONS and TRACE, and every WebSocket handshake. A browser sends its
 * cookies, a session's among them, with requests another site starts, so
 * a route that acts on a cookie needs this whether or not anyone is
 * logged in: a public login form, a route that only uses the session.
 *
 * The browser's Sec-Fetch-Site must say same-origin, or its Origin must
 * be the application's, app.url, or one allowed; a request with neither
 * header is refused. Requests that only read are not checked, so keep
 * them free of side effects.
 *
 *     ->middleware(SameOrigin::class)
 *     ->middleware(new SameOrigin(origins: ['https://admin.example.com']))
 *
 * CookieAuth does the same check itself; a route behind it needs no
 * SameOrigin as well.
 */
final readonly class SameOrigin implements Middleware, UsesContainer
{
    private const array SAFE = ['GET' => true, 'HEAD' => true, 'OPTIONS' => true, 'TRACE' => true];

    /**
     * @param string|null $site The application's public address: its origin is the one its own pages send. From app.url
     *     when not given; without either, the request's own origin, as its Host and scheme say.
     * @param list<string> $origins Other origins whose pages may send requests that change something.
     */
    public function __construct(
        private ?string $site = null,
        private array $origins = [],
    ) {
        foreach ([$site, ...$origins] as $origin) {
            if ($origin !== null && self::origin($origin) === null) {
                throw new \InvalidArgumentException(
                    "\"{$origin}\" is not an origin: write its scheme and host, such as https://shop.example.com.",
                );
            }
        }
    }

    public function withContainer(ContainerInterface $container): static
    {
        if ($this->site !== null || !$container->has(Config::class)) {
            return $this;
        }
        $url = $container->get(Config::class)->get('app.url');
        return is_string($url) && $url !== '' ? new self($url, $this->origins) : $this;
    }

    public function handle(Request $request, Handler $next): ResponseInterface
    {
        if (self::changes($request) && !$this->allows($request)) {
            return self::refusal($request);
        }
        return $next->handle($request);
    }

    /**
     * The answer to a request that changes something from somewhere else.
     */
    public static function refusal(ServerRequestInterface $request): ResponseInterface
    {
        return ErrorResponse::make($request, 403, 'This request did not come from this application\'s pages.');
    }

    /**
     * Whether a request can change something: a method other than reading, or a
     * WebSocket handshake, which a browser makes with the page's cookies too.
     */
    public static function changes(ServerRequestInterface $request): bool
    {
        return (
            !isset(self::SAFE[strtoupper($request->getMethod())])
            || stripos($request->getHeaderLine('Upgrade'), 'websocket') !== false
        );
    }

    /**
     * Whether a browser says the request comes from the application's own pages.
     */
    public function allows(ServerRequestInterface $request): bool
    {
        if (strtolower($request->getHeaderLine('Sec-Fetch-Site')) === 'same-origin') {
            return true;
        }
        $origin = self::origin($request->getHeaderLine('Origin'));
        if ($origin === null) {
            return false;
        }
        if ($origin === ($this->site !== null ? self::origin($this->site) : self::requestOrigin($request))) {
            return true;
        }
        foreach ($this->origins as $allowed) {
            if (self::origin($allowed) === $origin) {
                return true;
            }
        }
        return false;
    }

    /**
     * The request's own origin, as its scheme and Host say, for an application with no app.url.
     */
    private static function requestOrigin(ServerRequestInterface $request): ?string
    {
        $uri = $request->getUri();
        $host = $uri->getHost() !== '' ? $uri->getHost() : $request->getHeaderLine('Host');
        if ($host === '') {
            return null;
        }
        $port = $uri->getPort();
        return self::origin(
            ($uri->getScheme() !== '' ? $uri->getScheme() : 'http')
            . '://'
            . $host
            . ($port !== null ? ':' . $port : ''),
        );
    }

    /**
     * An origin in one form: lowercase scheme and host, the port only when not the scheme's own.
     */
    public static function origin(string $url): ?string
    {
        if ($url === '' || $url === 'null') {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $default = match ($scheme) {
            'https' => 443,
            'http' => 80,
            default => null,
        };
        if ($port === $default) {
            $port = null;
        }
        return $scheme . '://' . strtolower($parts['host']) . ($port === null ? '' : ':' . $port);
    }
}
