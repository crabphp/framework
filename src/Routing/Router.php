<?php

declare(strict_types=1);

namespace Crab\Routing;

use Crab\Contracts\Routing\RouteInfo;
use Crab\Contracts\Routing\Router as RouterContract;
use Crab\Contracts\Validation\Validator as ValidatorContract;
use Crab\Http\ErrorResponse;
use Crab\Validation\Validator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A PSR-15 handler that dispatches by method and path. Paths without
 * parameters are one hash lookup; the others share one combined expression
 * per method, built when that method is first matched. Parameters reach the
 * route's handler as request attributes. A path no route matches is answered
 * with 404, or with 405 and the methods that would match.
 *
 * A closure, the name of a class with __invoke(), or a class and the name
 * of its method is called with what its signature asks for, and its
 * return value becomes the response; see TypedHandler. A PSR-15 handler,
 * or the name of a container entry that is one, receives the request and
 * returns the response itself.
 */
final class Router implements RouterContract
{
    /**
     * Routes per combined expression, keeping each within PCRE's limits.
     */
    private const CHUNK = 32;

    /**
     * @var array<string, array<string, RequestHandlerInterface>>
     */
    private array $static = [];
    /**
     * Per method: expression, parameter names and handler of each route.
     *
     * @var array<string, list<array{string, list<string>, RequestHandlerInterface}>>
     */
    private array $dynamic = [];
    /**
     * @var array<string, list<string>>
     */
    private array $combined = [];
    /**
     * Every route, in the order added and keyed by method and pattern.
     *
     * @var array<string, RouteInfo>
     */
    private array $routes = [];
    private readonly ValidatorContract $validator;

    /**
     * The container makes handlers that are given by name; the validator
     * builds the inputs of typed handlers.
     */
    public function __construct(private readonly ?ContainerInterface $container = null, ?ValidatorContract $validator = null)
    {
        $this->validator = $validator ?? new Validator();
    }

    /**
     * A parameter matches one path segment unless its expression says
     * otherwise.
     */
    public function add(string $method, string $pattern, RequestHandlerInterface|\Closure|string|array $handler): static
    {
        [$expression, $names] = self::compile($pattern);
        $key = $method . ' ' . $pattern;
        if (isset($this->routes[$key])) {
            throw new \LogicException("Route {$method} {$pattern} is already defined.");
        }
        if ($handler instanceof \Closure || is_array($handler) || (is_string($handler) && method_exists($handler, '__invoke'))) {
            $handler = new TypedHandler($method, $pattern, $names, $handler, $this->validator, $this->container);
            $this->routes[$key] = $handler->route;
        } elseif (is_string($handler)) {
            if ($this->container === null) {
                throw new \LogicException("Route {$method} {$pattern} names its handler, which needs a router with a container.");
            }
            $this->routes[$key] = new RouteInfo($method, $pattern, $handler, array_fill_keys($names, 'string'));
            $handler = new ContainerHandler($this->container, $handler);
        } else {
            $this->routes[$key] = new RouteInfo($method, $pattern, $handler::class, array_fill_keys($names, 'string'));
        }
        if ($names !== []) {
            $this->dynamic[$method][] = [$expression, $names, $handler];
            unset($this->combined[$method]);
        } else {
            $this->static[$method][$pattern] = $handler;
        }
        return $this;
    }

    public function get(string $pattern, RequestHandlerInterface|\Closure|string|array $handler): static
    {
        return $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, RequestHandlerInterface|\Closure|string|array $handler): static
    {
        return $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, RequestHandlerInterface|\Closure|string|array $handler): static
    {
        return $this->add('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, RequestHandlerInterface|\Closure|string|array $handler): static
    {
        return $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, RequestHandlerInterface|\Closure|string|array $handler): static
    {
        return $this->add('DELETE', $pattern, $handler);
    }

    public function routes(): array
    {
        return array_values($this->routes);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        if ($path === '') {
            // An absolute-form target may name only the authority.
            $path = '/';
        }
        $parameters = [];
        $handler = $this->find($method, $path, $parameters);
        if ($handler === null && $method === 'HEAD') {
            $handler = $this->find('GET', $path, $parameters);
        }
        if ($handler === null) {
            return $this->unmatched($request, $path);
        }
        foreach ($parameters as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }
        return $handler->handle($request);
    }

    /**
     * @param array<string, string> $parameters Filled with the decoded parameters of a match.
     */
    private function find(string $method, string $path, array &$parameters): ?RequestHandlerInterface
    {
        $handler = $this->static[$method][$path] ?? null;
        if ($handler !== null || !isset($this->dynamic[$method])) {
            return $handler;
        }
        foreach ($this->combined[$method] ??= $this->combine($method) as $expression) {
            if (preg_match($expression, $path, $match) === 1) {
                [, $names, $handler] = $this->dynamic[$method][(int) $match['MARK']];
                foreach ($names as $index => $name) {
                    $parameters[$name] = rawurldecode($match[$index + 1]);
                }
                return $handler;
            }
        }
        return null;
    }

    /**
     * 405 with the methods that do match the path, otherwise 404.
     */
    private function unmatched(ServerRequestInterface $request, string $path): ResponseInterface
    {
        $allowed = [];
        $ignored = [];
        foreach (array_keys($this->static + $this->dynamic) as $method) {
            if ($this->find((string) $method, $path, $ignored) !== null) {
                $allowed[] = (string) $method;
            }
        }
        if ($allowed === []) {
            return ErrorResponse::make($request, 404);
        }
        if (in_array('GET', $allowed, true) && !in_array('HEAD', $allowed, true)) {
            $allowed[] = 'HEAD';
        }
        return ErrorResponse::make($request, 405, headers: ['Allow' => implode(', ', $allowed)]);
    }

    /**
     * Each route is one branch that reports its own index when it matches.
     *
     * @return list<string>
     */
    private function combine(string $method): array
    {
        $expressions = [];
        foreach (array_chunk($this->dynamic[$method], self::CHUNK, true) as $routes) {
            $branches = [];
            foreach ($routes as $index => $route) {
                $branches[] = $route[0] . '(*MARK:' . $index . ')';
            }
            $expressions[] = '~^(?|' . implode('|', $branches) . ')$~D';
        }
        return $expressions;
    }

    /**
     * @return array{string, list<string>} The pattern's expression and its parameter names.
     */
    private static function compile(string $pattern): array
    {
        if ($pattern === '' || $pattern[0] !== '/') {
            throw new \InvalidArgumentException('A route pattern must start with "/".');
        }
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)(?::((?:[^{}]++|\{[^{}]*\})+))?\}/', $pattern, $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $expression = '';
        $names = [];
        $offset = 0;
        foreach ($matches as $match) {
            $expression .= self::literal(substr($pattern, $offset, $match[0][1] - $offset), $pattern);
            $name = $match[1][0];
            if (in_array($name, $names, true)) {
                throw new \InvalidArgumentException("Route {$pattern} repeats the parameter {$name}.");
            }
            $names[] = $name;
            $expression .= '(' . (isset($match[2]) ? self::constraint($match[2][0], $pattern) : '[^/]+') . ')';
            $offset = $match[0][1] + strlen($match[0][0]);
        }
        return [$expression . self::literal(substr($pattern, $offset), $pattern), $names];
    }

    private static function literal(string $text, string $pattern): string
    {
        if (strpbrk($text, '{}') !== false) {
            throw new \InvalidArgumentException("Route {$pattern} has a malformed parameter.");
        }
        return preg_quote($text, '~');
    }

    /**
     * A parameter's expression must be valid and must not capture, because
     * parameters are read by position.
     */
    private static function constraint(string $expression, string $pattern): string
    {
        $expression = str_replace('~', '\~', $expression);
        // The empty alternative always matches, so every group is reported.
        if (@preg_match('~' . $expression . '|~', '', $groups, PREG_UNMATCHED_AS_NULL) !== 1) {
            throw new \InvalidArgumentException("Route {$pattern} has an invalid parameter expression.");
        }
        if (count($groups) > 1) {
            throw new \InvalidArgumentException("Route {$pattern} must use non-capturing groups, (?:...).");
        }
        return $expression;
    }
}
