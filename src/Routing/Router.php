<?php

declare(strict_types=1);

namespace Crab\Routing;

use Crab\Contracts\Routing\Router as RouterContract;
use Crab\Http\ClosureHandler;
use Crab\Http\Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A PSR-15 handler that dispatches by method and path. Paths without
 * parameters are one hash lookup; the others share one combined expression
 * per method, built when that method is first matched. Parameters reach the
 * route's handler as request attributes.
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
     * Method, pattern and a name for the handler of every route, in the
     * order added and keyed by method and pattern.
     *
     * @var array<string, array{string, string, string}>
     */
    private array $routes = [];

    /**
     * The container resolves handlers that are given by name.
     */
    public function __construct(
        private readonly ResponseFactoryInterface $responses = new Factory(),
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    /**
     * A parameter matches one path segment unless its expression says
     * otherwise.
     */
    public function add(string $method, string $pattern, RequestHandlerInterface|\Closure|string $handler): static
    {
        [$expression, $names] = self::compile($pattern);
        if (isset($this->routes[$method . ' ' . $pattern])) {
            throw new \LogicException("Route {$method} {$pattern} is already defined.");
        }
        $name = match (true) {
            is_string($handler) => $handler,
            $handler instanceof \Closure => 'Closure',
            default => $handler::class,
        };
        if ($handler instanceof \Closure) {
            $handler = new ClosureHandler($handler);
        } elseif (is_string($handler)) {
            if ($this->container === null) {
                throw new \LogicException("Route {$method} {$pattern} names its handler, which needs a router with a container.");
            }
            $handler = new ContainerHandler($this->container, $handler);
        }
        $this->routes[$method . ' ' . $pattern] = [$method, $pattern, $name];
        if ($names !== []) {
            $this->dynamic[$method][] = [$expression, $names, $handler];
            unset($this->combined[$method]);
        } else {
            $this->static[$method][$pattern] = $handler;
        }
        return $this;
    }

    public function get(string $pattern, RequestHandlerInterface|\Closure|string $handler): static
    {
        return $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, RequestHandlerInterface|\Closure|string $handler): static
    {
        return $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, RequestHandlerInterface|\Closure|string $handler): static
    {
        return $this->add('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, RequestHandlerInterface|\Closure|string $handler): static
    {
        return $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, RequestHandlerInterface|\Closure|string $handler): static
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
            return $this->unmatched($path);
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
    private function unmatched(string $path): ResponseInterface
    {
        $allowed = [];
        $ignored = [];
        foreach (array_keys($this->static + $this->dynamic) as $method) {
            if ($this->find((string) $method, $path, $ignored) !== null) {
                $allowed[] = (string) $method;
            }
        }
        if ($allowed === []) {
            return $this->responses->createResponse(404);
        }
        if (in_array('GET', $allowed, true) && !in_array('HEAD', $allowed, true)) {
            $allowed[] = 'HEAD';
        }
        return $this->responses->createResponse(405)->withHeader('Allow', implode(', ', $allowed));
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
