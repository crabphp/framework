<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Http\Middleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lets pages served from other origins call this API from a browser
 * (CORS). A browser refuses to show a page the answer to a request to
 * another origin unless the answer names that origin, and before any
 * request that is not a simple one it asks permission first, with an
 * OPTIONS request this middleware answers by itself.
 *
 *     new Cors(['https://app.example.com'])                      one site
 *     new Cors(['https://app.example.com', 'https://*.example.dev'])
 *     new Cors(['*'])                                            any site
 *     new Cors(['https://app.example.com'], credentials: true)   with cookies
 *
 * Put it before the framework's own middleware, so that an error is
 * readable by the page too: withMiddleware(prepend: [new Cors([...])]).
 *
 * It protects nothing. A request from an origin that is not listed is
 * still handled, only without the headers a browser needs to hand the
 * answer to the page; anything that is not a browser ignores them.
 */
final readonly class Cors implements Middleware
{
    private bool $any;
    /**
     * @var array<string, true>
     */
    private array $exact;
    /**
     * Expressions for origins given with a "*" in them.
     *
     * @var list<string>
     */
    private array $patterns;
    private string $methods;
    private string $headers;
    private string $exposed;
    private string $maxAge;

    /**
     * @param list<string> $origins Who may call: "https://app.example.com", with "*" standing for one part of the host ("https://*.example.com"), or "*" alone for anyone.
     * @param list<string> $methods The methods a page may use.
     * @param list<string> $headers The request headers a page may send; "*" for whichever it asks for.
     * @param list<string> $exposed Response headers a page may read beyond the few a browser always shows it.
     * @param bool $credentials Whether the browser may send cookies and HTTP authentication, and show the page the answer when it did.
     * @param int $maxAge For how many seconds a browser may remember a permission before asking again.
     */
    public function __construct(
        array $origins,
        array $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        array $headers = ['*'],
        array $exposed = [],
        private bool $credentials = false,
        int $maxAge = 600,
    ) {
        if ($origins === []) {
            throw new \LogicException(
                'Cors needs the origins that may call: [\'https://app.example.com\'], or [\'*\'] for any.',
            );
        }
        $exact = [];
        $patterns = [];
        $any = false;
        foreach ($origins as $origin) {
            if ($origin === '*') {
                $any = true;
                continue;
            }
            if (!is_string($origin) || preg_match('~^[a-z][a-z0-9+.-]*://[^/?#\s]+$~i', $origin) !== 1) {
                $given = is_string($origin) ? "\"{$origin}\"" : get_debug_type($origin);
                throw new \LogicException(
                    "Cors takes an origin as a scheme and a host with nothing after it, \"https://app.example.com\", not {$given}.",
                );
            }
            if (str_contains($origin, '*')) {
                // A star stands for one part of the host, never for a dot or a port.
                $patterns[] = '~^' . str_replace('\*', '[^.:/]+', preg_quote($origin, '~')) . '$~i';
            } else {
                $exact[strtolower($origin)] = true;
            }
        }
        if ($any && $credentials) {
            throw new \LogicException(
                'Cors cannot allow credentials for any origin: every site could then act as its visitor. Name the origins.',
            );
        }
        if ($maxAge < 0) {
            throw new \LogicException('Cors takes a max age of zero or more seconds.');
        }
        $this->any = $any;
        $this->exact = $exact;
        $this->patterns = $patterns;
        $this->methods = implode(', ', array_map(strtoupper(...), $methods));
        $this->headers = in_array('*', $headers, true) ? '*' : implode(', ', $headers);
        $this->exposed = implode(', ', $exposed);
        $this->maxAge = (string) $maxAge;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        // Wildcard permission and exposed headers are the same with no
        // Origin: a cache may serve that answer to a browser later.
        $allowed = $this->allows($origin);
        if (
            $origin !== ''
            && $request->getMethod() === 'OPTIONS'
            && $request->hasHeader('Access-Control-Request-Method')
        ) {
            return (
                $allowed
                    ? $this->permission($request, $origin)
                    : ErrorResponse::make($request, 403, 'This origin may not call the API.', [
                        'Vary' => 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers',
                    ])
            );
        }
        $response = $handler->handle($request);
        if (!$allowed) {
            return $response->withAddedHeader('Vary', 'Origin');
        }
        $response = $this->named($response, $origin);
        return $this->exposed === ''
            ? $response
            : $response->withHeader('Access-Control-Expose-Headers', $this->exposed);
    }

    private function allows(string $origin): bool
    {
        if ($this->any || isset($this->exact[strtolower($origin)])) {
            return true;
        }
        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $origin) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * The answer to a browser asking whether a request may be made.
     */
    private function permission(ServerRequestInterface $request, string $origin): ResponseInterface
    {
        // "*" cannot be sent where credentials are allowed, so the headers asked for are named back.
        $headers = $this->headers === '*' ? $request->getHeaderLine('Access-Control-Request-Headers') : $this->headers;
        $response = new Response(204, [
            'Access-Control-Allow-Methods' => $this->methods,
            'Access-Control-Max-Age' => $this->maxAge,
        ]);
        if ($headers !== '') {
            $response = $response->withHeader('Access-Control-Allow-Headers', $headers);
        }
        return $this
            ->named($response, $origin)
            ->withAddedHeader('Vary', 'Access-Control-Request-Method')
            ->withAddedHeader('Vary', 'Access-Control-Request-Headers');
    }

    /**
     * Names who may read the response: anyone, or this origin and no other.
     */
    private function named(ResponseInterface $response, string $origin): ResponseInterface
    {
        if ($this->any) {
            return $response->withHeader('Access-Control-Allow-Origin', '*');
        }
        // The answer differs by origin, which a cache between the two must know.
        $response = $response->withHeader('Access-Control-Allow-Origin', $origin)->withAddedHeader('Vary', 'Origin');
        return $this->credentials ? $response->withHeader('Access-Control-Allow-Credentials', 'true') : $response;
    }
}
