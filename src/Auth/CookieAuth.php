<?php

declare(strict_types=1);

namespace Crab\Auth;

use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Auth\IdentityResolver;
use Crab\Contracts\Auth\TokenStore;
use Crab\Contracts\Http\Middleware;
use Crab\Contracts\Http\UsesContainer;
use Crab\Http\Cookie;
use Crab\Http\ErrorResponse;
use Crab\Http\Response;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware for browsers: the user's token, the same one TokenAuth reads
 * from a header, travels in an HttpOnly cookie, and the user is passed on
 * as the request's "user" attribute. There is no session: the cookie holds
 * the token, and the token store says whose it is.
 *
 * A browser sends cookies with requests another site starts, so every
 * request that can change something, any method but GET, HEAD, OPTIONS
 * and TRACE, and every WebSocket handshake, which carries the cookie too,
 * must come from the application's own pages: the browser's Sec-Fetch-Site
 * must say same-origin, or its Origin must be the site's or one allowed.
 * That is checked whether or not there is a cookie, so a login form behind
 * this middleware is covered too. Requests that only read are not checked,
 * so keep them free of side effects.
 *
 *     $token = $tokens->issue($user, 'browser');
 *     return Response::redirect('/', 303)->withCookie($auth->cookie($token));
 *
 *     return Response::redirect('/', 303)->withCookie($auth->logout($request));
 *
 * TokenAuthPlugin binds it with the "auth.cookie" settings. On a route it
 * is CookieAuth::class, or an object for other options, which takes what
 * it does not set from the bound one:
 *
 *     ->middleware(CookieAuth::class)
 *     ->middleware(new CookieAuth(required: false))
 *     ->middleware(new CookieAuth(login: '/login'))
 */
final readonly class CookieAuth implements Middleware, UsesContainer
{
    private const array SAFE = ['GET' => true, 'HEAD' => true, 'OPTIONS' => true, 'TRACE' => true];

    /**
     * @param bool $required Whether a request without a known user is refused. When not, it goes through with no user.
     * @param string|null $login Where a refused GET or HEAD request is sent instead of being answered 401, such as '/login'.
     * @param TokenStore|null $tokens Taken from the bound CookieAuth, with every option left null, when given on a route.
     * @param string|null $name The cookie's name; "auth" when none is given or bound.
     * @param string|null $site The application's public address, app.url: its origin is the one its own pages send.
     *     Without one, the request's own origin is, as its Host and scheme say.
     * @param list<string>|null $origins Other origins whose pages may send requests that change something,
     *     such as 'https://admin.example.com'.
     * @param bool|null $secure Whether the cookie is sent only over HTTPS; yes when none is given or bound.
     * @param int|null $maxAge Seconds the browser keeps the cookie; null keeps it until the browser closes.
     */
    public function __construct(
        private bool $required = true,
        private ?string $login = null,
        private ?TokenStore $tokens = null,
        private ?IdentityResolver $users = null,
        private ?string $name = null,
        private ?string $site = null,
        private ?array $origins = null,
        private ?bool $secure = null,
        private ?int $maxAge = null,
    ) {
        if ($name !== null && !preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new \InvalidArgumentException(
                "\"{$name}\" cannot be a cookie's name: use letters, digits, _, . and -.",
            );
        }
        if ($maxAge !== null && $maxAge < 1) {
            throw new \InvalidArgumentException('A cookie that keeps the user logged in lasts at least one second.');
        }
        foreach ([$site, ...($origins ?? [])] as $origin) {
            if ($origin !== null && self::origin($origin) === null) {
                throw new \InvalidArgumentException(
                    "\"{$origin}\" is not an origin: write its scheme and host, such as https://shop.example.com.",
                );
            }
        }
    }

    public function withContainer(ContainerInterface $container): static
    {
        if ($this->tokens !== null && $this->users !== null) {
            return $this;
        }
        $bound = $container->get(self::class);
        if ($bound->tokens === null || $bound->users === null) {
            throw new \LogicException(
                'CookieAuth needs the token store and your IdentityResolver: list TokenAuthPlugin in app.php.',
            );
        }
        return new self(
            $this->required,
            $this->login ?? $bound->login,
            $bound->tokens,
            $bound->users,
            $this->name ?? $bound->name,
            $this->site ?? $bound->site,
            $this->origins ?? $bound->origins,
            $this->secure ?? $bound->secure,
            $this->maxAge ?? $bound->maxAge,
        );
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->tokens === null || $this->users === null) {
            throw new \LogicException(
                'CookieAuth needs the token store and your IdentityResolver: list TokenAuthPlugin in app.php.',
            );
        }
        if (self::changes($request) && !$this->fromOwnPages($request)) {
            return ErrorResponse::make($request, 403, 'This request did not come from this application\'s pages.');
        }
        if ($request->getAttribute(Identity::ATTRIBUTE) instanceof Identity) {
            // Whoever put the user there, middleware before this one or a test, has said who it is.
            return $handler->handle($request);
        }
        $token = $this->token($request);
        $owner = $token === null ? null : $this->tokens->owner($token);
        $user = $owner === null ? null : $this->users->resolve($owner);
        if ($user !== null) {
            return $handler->handle($request->withAttribute(Identity::ATTRIBUTE, $user));
        }
        if (!$this->required) {
            // A cookie whose token no longer works, after logging out elsewhere or expiring, is cleared,
            // unless the response sets it anew, as a login does.
            return $this->clearStale($handler->handle($request), $token);
        }
        $refused = $this->login !== null && in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)
            ? Response::redirect($this->login, 303)
            : ErrorResponse::make($request, 401, 'Authentication is required.');
        return $this->clearStale($refused, $token);
    }

    /**
     * The cookie that logs a browser in with a token from TokenStore::issue().
     */
    public function cookie(#[\SensitiveParameter] string $token): Cookie
    {
        return new Cookie(
            $this->name ?? 'auth',
            $token,
            maxAge: $this->maxAge,
            secure: $this->secure ?? true,
            httpOnly: true,
            sameSite: 'Lax',
        );
    }

    /**
     * Logs the request's browser out: revokes the token in its cookie, and
     * returns the cookie that clears it from the browser.
     */
    public function logout(ServerRequestInterface $request): Cookie
    {
        $token = $this->token($request);
        if ($token !== null) {
            $this->tokens?->revoke($token);
        }
        return $this->forget();
    }

    /**
     * The token in the request's cookie, or null when there is none.
     */
    public function token(ServerRequestInterface $request): ?string
    {
        $token = $request->getCookieParams()[$this->name ?? 'auth'] ?? null;
        return is_string($token) && $token !== '' ? $token : null;
    }

    private function forget(): Cookie
    {
        return new Cookie($this->name ?? 'auth', '', 0, secure: $this->secure ?? true, httpOnly: true, sameSite: 'Lax');
    }

    private function clearStale(ResponseInterface $response, #[\SensitiveParameter] ?string $token): ResponseInterface
    {
        if ($token === null) {
            return $response;
        }
        $prefix = ($this->name ?? 'auth') . '=';
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            if (str_starts_with($cookie, $prefix)) {
                return $response;
            }
        }
        return $response->withAddedHeader('Set-Cookie', (string) $this->forget());
    }

    /**
     * Whether a request can change something: a method other than reading, or a
     * WebSocket handshake, which a browser makes with the page's cookies too.
     */
    private static function changes(ServerRequestInterface $request): bool
    {
        return (
            !isset(self::SAFE[strtoupper($request->getMethod())])
            || stripos($request->getHeaderLine('Upgrade'), 'websocket') !== false
        );
    }

    /**
     * Whether a browser says the request comes from the application's own pages.
     */
    private function fromOwnPages(ServerRequestInterface $request): bool
    {
        if (strtolower($request->getHeaderLine('Sec-Fetch-Site')) === 'same-origin') {
            return true;
        }
        $origin = self::origin($request->getHeaderLine('Origin'));
        if ($origin === null) {
            return false;
        }
        if ($origin === ($this->site !== null ? self::origin($this->site) : $this->requestOrigin($request))) {
            return true;
        }
        foreach ($this->origins ?? [] as $allowed) {
            if (self::origin($allowed) === $origin) {
                return true;
            }
        }
        return false;
    }

    /**
     * The request's own origin, as its scheme and Host say, for an application with no app.url.
     */
    private function requestOrigin(ServerRequestInterface $request): ?string
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
    private static function origin(string $url): ?string
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
