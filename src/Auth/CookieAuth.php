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
 * request that can change something (any method but GET, HEAD, OPTIONS and
 * TRACE) must come from the application's own pages: the browser's
 * Sec-Fetch-Site must say same-origin, or its Origin must be the request's
 * own or one allowed. That is checked whether or not there is a cookie, so
 * a login form behind this middleware is covered too. Requests that only
 * read are not checked, so keep them free of side effects.
 *
 *     $token = $tokens->issue($user, 'browser');
 *     return Response::redirect('/', 303)->withCookie($auth->cookie($token));
 *
 *     return Response::redirect('/', 303)->withCookie($auth->logout($request));
 *
 * TokenAuthPlugin binds it with the "auth.cookie" settings. On a route it
 * is CookieAuth::class, or an object for other options, which takes the
 * rest from the bound one:
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
     * @param TokenStore|null $tokens Taken, with the options below, from the bound CookieAuth when given as route options.
     * @param string $name The cookie's name.
     * @param list<string> $origins Other origins whose pages may send requests that change something,
     *     such as 'https://shop.example.com'; the request's own origin always may.
     * @param bool $secure Whether the cookie is sent only over HTTPS. Keep it on in production.
     * @param int|null $maxAge Seconds the browser keeps the cookie; null keeps it until the browser closes.
     */
    public function __construct(
        private bool $required = true,
        private ?string $login = null,
        private ?TokenStore $tokens = null,
        private ?IdentityResolver $users = null,
        private string $name = 'auth',
        private array $origins = [],
        private bool $secure = true,
        private ?int $maxAge = null,
    ) {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new \InvalidArgumentException(
                "\"{$name}\" cannot be a cookie's name: use letters, digits, _, . and -.",
            );
        }
        if ($maxAge !== null && $maxAge < 1) {
            throw new \InvalidArgumentException('A cookie that keeps the user logged in lasts at least one second.');
        }
    }

    public function withContainer(ContainerInterface $container): static
    {
        if ($this->tokens !== null && $this->users !== null) {
            return $this;
        }
        $bound = $container->get(self::class);
        return new self(
            $this->required,
            $this->login ?? $bound->login,
            $bound->tokens,
            $bound->users,
            $bound->name,
            $bound->origins,
            $bound->secure,
            $bound->maxAge,
        );
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->tokens === null || $this->users === null) {
            throw new \LogicException(
                'Cookie authentication needs its services: register TokenAuthPlugin and put CookieAuth on a route.',
            );
        }
        if (!isset(self::SAFE[strtoupper($request->getMethod())]) && !$this->fromOwnPages($request)) {
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
            $response = $handler->handle($request);
            // A cookie whose token no longer works, after logging out elsewhere or expiring, is cleared.
            return $token === null ? $response : $response->withAddedHeader('Set-Cookie', (string) $this->forget());
        }
        $refused = $this->login !== null && in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)
            ? Response::redirect($this->login, 303)
            : ErrorResponse::make($request, 401, 'Authentication is required.');
        return $token === null ? $refused : $refused->withAddedHeader('Set-Cookie', (string) $this->forget());
    }

    /**
     * The cookie that logs a browser in with a token from TokenStore::issue().
     */
    public function cookie(#[\SensitiveParameter] string $token): Cookie
    {
        return new Cookie(
            $this->name,
            $token,
            maxAge: $this->maxAge,
            secure: $this->secure,
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
        $token = $request->getCookieParams()[$this->name] ?? null;
        return is_string($token) && $token !== '' ? $token : null;
    }

    private function forget(): Cookie
    {
        $forget = Cookie::forget($this->name);
        return new Cookie($forget->name, '', 0, secure: $this->secure, httpOnly: true, sameSite: 'Lax');
    }

    /**
     * Whether a browser says the request comes from the application's own pages.
     */
    private function fromOwnPages(ServerRequestInterface $request): bool
    {
        if (strtolower($request->getHeaderLine('Sec-Fetch-Site')) === 'same-origin') {
            return true;
        }
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '' || $origin === 'null') {
            return false;
        }
        $origin = self::origin($origin);
        if ($origin === null) {
            return false;
        }
        $uri = $request->getUri();
        $host = $uri->getHost() !== '' ? $uri->getHost() : $request->getHeaderLine('Host');
        $own = self::origin(
            ($uri->getScheme() !== '' ? $uri->getScheme() : 'http')
            . '://'
            . $host
            . ($uri->getPort() !== null ? ':' . $uri->getPort() : ''),
        );
        if ($origin === $own) {
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
     * An origin in one form: lowercase scheme and host, the port only when not the scheme's own.
     */
    private static function origin(string $url): ?string
    {
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
