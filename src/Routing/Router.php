<?php

declare(strict_types=1);

namespace Crab\Routing;

use Crab\Contracts\Http\Middleware;
use Crab\Contracts\Routing\RouteInfo;
use Crab\Contracts\Routing\Router as RouterContract;
use Crab\Contracts\Validation\Validator as ValidatorContract;
use Crab\Http\ErrorResponse;
use Crab\Http\MiddlewareStack;
use Crab\Http\Request;
use Crab\Validation\Validator;
use Crab\WebSocket\Connections;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A PSR-15 handler that dispatches by method and path. Paths without
 * parameters are one hash lookup; the others share one combined expression
 * per method, built when that method is first matched. Parameters reach the
 * route's handler as request attributes. A path no route matches is answered
 * with 404, or with 405 and the methods that would match.
 *
 * A closure, the name of a class with handle(), or a class and the name
 * of its method is called with what its signature asks for, and its
 * return value becomes the response; see ActionHandler. A PSR-15 handler,
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
     * A parameter in a pattern: {name}, or {name:expression}.
     */
    private const PARAMETER = '/\{([A-Za-z_][A-Za-z0-9_]*)(?::((?:[^{}]++|\{[^{}]*\})+))?\}/';

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
    /**
     * The key in $routes of each named route.
     *
     * @var array<string, string>
     */
    private array $names = [];
    private readonly ValidatorContract $validator;
    private ?Connections $websockets = null;
    /**
     * The prefix and middleware of each group being declared, outermost first.
     *
     * @var list<array{string, list<MiddlewareInterface|string>}>
     */
    private array $groups = [];
    /**
     * The middleware of every route that has some, to be made when a worker starts.
     *
     * @var array<string, MiddlewareStack>
     */
    private array $stacks = [];
    /**
     * Each route as declared, so that middleware chained onto it can wrap
     * it: its handler, the middleware around it, its expression and
     * parameter names, and its place among the method's dynamic routes.
     *
     * @var array<string, array{RequestHandlerInterface, list<MiddlewareInterface|string>, string, list<string>, int|null}>
     */
    private array $declared = [];

    /**
     * The container makes handlers that are given by name; the validator
     * builds the inputs of typed handlers.
     */
    public function __construct(
        private readonly ?ContainerInterface $container = null,
        ?ValidatorContract $validator = null,
        private readonly ?\Closure $socketFactory = null,
    ) {
        $this->validator = $validator ?? new Validator();
    }

    /**
     * A parameter matches one path segment unless its expression says
     * otherwise.
     */
    public function add(
        string $method,
        string $pattern,
        RequestHandlerInterface|\Closure|string|array $action,
    ): DeclaredRoute {
        $middleware = [];
        $prefix = '';
        foreach ($this->groups as [$groupPrefix, $groupMiddleware]) {
            $prefix .= $groupPrefix;
            $middleware = [...$middleware, ...$groupMiddleware];
        }
        if ($prefix !== '') {
            // "/" inside a group is the group's own path.
            $pattern = $pattern === '/' ? $prefix : $prefix . $pattern;
        }
        [$expression, $names] = self::compile($pattern);
        $key = $method . ' ' . $pattern;
        if (isset($this->routes[$key])) {
            throw new \LogicException("Route {$method} {$pattern} is already defined.");
        }
        if ($method === 'WS' && !$action instanceof \Closure && !is_array($action) && !self::isActionClass($action)) {
            throw new \LogicException(
                "The action of WS {$pattern} must be a closure or a class with handle(Socket \$socket, ...). Use Route::websocket() with an action, rather than a PSR-15 handler.",
            );
        }
        if ($action instanceof \Closure || is_array($action) || self::isActionClass($action)) {
            $websockets = null;
            if ($method === 'WS') {
                $this->websockets ??= $this->socketFactory === null ? new Connections() : ($this->socketFactory)();
                $websockets = $this->websockets;
            }
            $handler = new ActionHandler(
                $method,
                $pattern,
                $names,
                $action,
                $this->validator,
                $this->container,
                $websockets,
            );
            $described = $handler->name;
        } elseif (is_string($action)) {
            if ($this->container === null) {
                throw new \LogicException(
                    "Route {$method} {$pattern} names its handler, which needs a router with a container.",
                );
            }
            if (!$this->container->has($action)) {
                // Said now, and not by the first request for it.
                throw new \LogicException(
                    "Route {$method} {$pattern} points at {$action}, and there is no such class or container entry.",
                );
            }
            $handler = new ContainerHandler($this->container, $action);
            $described = $action;
        } else {
            $handler = $action;
            $described = $action::class;
        }
        $this->routes[$key] = new RouteInfo($method, $pattern, $described);
        $slot = null;
        if ($names !== []) {
            $slot = count($this->dynamic[$method] ?? []);
            unset($this->combined[$method]);
        }
        $this->declared[$key] = [$handler, [], $expression, $names, $slot];
        // A route's own middleware, chained onto it, goes inside its groups'.
        $this->wrap($key, $middleware);
        return new DeclaredRoute(
            fn(string $name) => $this->name($key, $name),
            fn(array $middleware) => $this->wrap($key, $this->checked($middleware, "Route {$key}")),
        );
    }

    /**
     * Adds middleware inside what is already around a declared route, and
     * puts the route, wrapped in all of it, where requests find it.
     *
     * @param list<MiddlewareInterface|string> $middleware
     */
    private function wrap(string $key, array $middleware): void
    {
        [$handler, $around, $expression, $names, $slot] = $this->declared[$key];
        $around = self::once([...$around, ...$middleware]);
        $this->declared[$key][1] = $around;
        $route = $this->routes[$key];
        // The middleware as the route list names it: by class.
        $described = [];
        foreach ($around as $layer) {
            $described[] = is_string($layer) ? $layer : $layer::class;
        }
        $this->routes[$key] = new RouteInfo($route->method, $route->pattern, $route->action, $described, $route->name);
        unset($this->stacks[$key]);
        if ($around !== []) {
            $stack = new MiddlewareStack($handler, $this->container);
            $stack->add(...$around);
            $this->stacks[$key] = $stack;
            $handler = $stack;
        }
        if ($slot === null) {
            $this->static[$route->method][$route->pattern] = $handler;
        } else {
            $this->dynamic[$route->method][$slot] = [$expression, $names, $handler];
        }
    }

    private function name(string $key, string $name): void
    {
        $route = $this->routes[$key];
        if (trim($name) === '') {
            throw new \InvalidArgumentException(
                "Route {$key} is given an empty name. Give one such as \"orders.show\".",
            );
        }
        if ($route->name !== null) {
            throw new \LogicException("Route {$key} is named \"{$route->name}\" already. A route has one name.");
        }
        if (isset($this->names[$name])) {
            throw new \LogicException(
                "Route {$key} is named \"{$name}\", which route {$this->names[$name]} already is. Give each route its own name.",
            );
        }
        $this->names[$name] = $key;
        $this->routes[$key] = new RouteInfo($route->method, $route->pattern, $route->action, $route->middleware, $name);
    }

    public function prefix(string $prefix): RouteGroup
    {
        return $this->newGroup()->prefix($prefix);
    }

    public function middleware(MiddlewareInterface|string ...$middleware): RouteGroup
    {
        return $this->newGroup()->middleware(...$middleware);
    }

    private function newGroup(): RouteGroup
    {
        return new RouteGroup(function (string $prefix, array $middleware, \Closure $routes): void {
            $this->groups[] = [
                $prefix,
                $this->checked($middleware, $prefix === '' ? 'A group' : "The group {$prefix}"),
            ];
            try {
                $routes($this);
            } finally {
                array_pop($this->groups);
            }
        });
    }

    /**
     * Each middleware once: where the same name or the same object is
     * given again, by a group inside another or by the route itself, it
     * runs once, at the later place. Two objects of one class are two
     * middleware and both run, since each was given its own options:
     * leaving one out could drop a check the application asked for.
     *
     * @param list<MiddlewareInterface|string> $middleware
     * @return list<MiddlewareInterface|string>
     */
    private static function once(array $middleware): array
    {
        $unique = [];
        foreach ($middleware as $layer) {
            $key = is_string($layer) ? 'name ' . $layer : 'object ' . spl_object_id($layer);
            // Taken out first, so that it is put back at the later place.
            unset($unique[$key]);
            $unique[$key] = $layer;
        }
        return array_values($unique);
    }

    /**
     * Refuses, where it is declared, middleware that could never run: a
     * value that is not one, and a name that is neither a middleware
     * class nor an entry of the container.
     *
     * @param array<mixed> $middleware
     * @param string $of What it was given to, to say so: "Route GET /me".
     * @return list<MiddlewareInterface|string>
     */
    private function checked(array $middleware, string $of): array
    {
        foreach ($middleware as $layer) {
            if ($layer instanceof MiddlewareInterface) {
                continue;
            }
            if (!is_string($layer)) {
                throw new \InvalidArgumentException(
                    "{$of} takes PSR-15 middleware or their class names as its middleware, not "
                    . get_debug_type($layer)
                    . '.',
                );
            }
            if (class_exists($layer) || interface_exists($layer)) {
                if (!is_a($layer, MiddlewareInterface::class, true)) {
                    throw new \InvalidArgumentException(
                        "{$of} names {$layer} as middleware, and it is not one. Make it implement "
                        . Middleware::class
                        . '.',
                    );
                }
                continue;
            }
            if ($this->container === null || !$this->container->has($layer)) {
                throw new \InvalidArgumentException(
                    "{$of} names {$layer} as middleware, and there is no such class. Check the name and its namespace.",
                );
            }
        }
        return array_values($middleware);
    }

    /**
     * Makes the middleware of every route and group now. A worker calls it as it
     * starts, so that middleware that cannot be made stops it then and
     * not on the first request to one route.
     */
    public function assemble(): void
    {
        foreach ($this->stacks as $stack) {
            $stack->assemble();
        }
    }

    /**
     * Declares an action that lasts for the connection. Query fields, the
     * user and pattern parameters are checked before returning 101.
     */
    public function hasWebSockets(): bool
    {
        return $this->websockets !== null;
    }

    public function websocket(string $pattern, \Closure|string|array $action): DeclaredRoute
    {
        return $this->add('WS', $pattern, $action);
    }

    public function get(string $pattern, RequestHandlerInterface|\Closure|string|array $action): DeclaredRoute
    {
        return $this->add('GET', $pattern, $action);
    }

    public function post(string $pattern, RequestHandlerInterface|\Closure|string|array $action): DeclaredRoute
    {
        return $this->add('POST', $pattern, $action);
    }

    public function put(string $pattern, RequestHandlerInterface|\Closure|string|array $action): DeclaredRoute
    {
        return $this->add('PUT', $pattern, $action);
    }

    public function patch(string $pattern, RequestHandlerInterface|\Closure|string|array $action): DeclaredRoute
    {
        return $this->add('PATCH', $pattern, $action);
    }

    public function delete(string $pattern, RequestHandlerInterface|\Closure|string|array $action): DeclaredRoute
    {
        return $this->add('DELETE', $pattern, $action);
    }

    /**
     * By route, "GET /orders", what the container makes for a request to
     * it: its action's class or the entry that handles it, and the
     * middleware around it that is given by name, its groups' first.
     *
     * @return array<string, list<string>>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->declared as $key => [$handler, $around]) {
            $entry = match (true) {
                $handler instanceof ActionHandler => $handler->entry(),
                $handler instanceof ContainerHandler => $handler->id,
                default => null,
            };
            $entries[$key] = [
                ...($entry === null ? [] : [$entry]),
                ...array_values(array_filter($around, is_string(...))),
            ];
        }
        return $entries;
    }

    public function routes(): array
    {
        return array_values($this->routes);
    }

    public function input(RouteInfo $route): ?array
    {
        $handler = $this->declared["{$route->method} {$route->pattern}"][0] ?? null;
        return $handler instanceof ActionHandler ? $handler->input() : null;
    }

    public function route(string $name): ?RouteInfo
    {
        return isset($this->names[$name]) ? $this->routes[$this->names[$name]] : null;
    }

    public function path(string $name, array $parameters = [], array $query = []): string
    {
        $route = $this->route($name);
        if ($route === null) {
            throw new \InvalidArgumentException(
                "There is no route named \"{$name}\". `php cua route:list` shows the names routes have.",
            );
        }
        preg_match_all(self::PARAMETER, $route->pattern, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $path = '';
        $offset = 0;
        $used = [];
        foreach ($matches as $match) {
            $path .= self::encode(substr($route->pattern, $offset, $match[0][1] - $offset), keepSlashes: true);
            $parameter = $match[1][0];
            if (!array_key_exists($parameter, $parameters)) {
                throw new \InvalidArgumentException(
                    "Route \"{$name}\" ({$route->pattern}) needs the parameter {$parameter}.",
                );
            }
            $value = self::text($parameters[$parameter], $name, $parameter);
            $expression = $match[2][0] ?? null;
            if (
                $expression === null
                    ? $value === ''
                    : preg_match('~^(?:' . str_replace('~', '\~', $expression) . ')$~D', $value) !== 1
            ) {
                throw new \InvalidArgumentException(
                    "Route \"{$name}\" ({$route->pattern}) cannot take \"{$value}\" as its parameter {$parameter}.",
                );
            }
            // A parameter with an expression of its own may span segments, "{path:.+}"; one without is one segment.
            $path .= self::encode($value, keepSlashes: $expression !== null);
            $used[$parameter] = true;
            $offset = $match[0][1] + strlen($match[0][0]);
        }
        $path .= self::encode(substr($route->pattern, $offset), keepSlashes: true);
        $unknown = array_diff(array_keys($parameters), array_keys($used));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                "Route \"{$name}\" ({$route->pattern}) has no parameter "
                . implode(', ', $unknown)
                . '. Pass what goes after "?" as the query.',
            );
        }
        if ($query !== []) {
            $path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return $path;
    }

    /**
     * A parameter's value as the text it is written as in a path.
     */
    private static function text(mixed $value, string $name, string $parameter): string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }
        if (is_int($value) || is_string($value) || $value instanceof \Stringable) {
            return (string) $value;
        }
        throw new \InvalidArgumentException(
            "Route \"{$name}\" takes text, a whole number or a backed enum as its parameter {$parameter}, not "
            . get_debug_type($value)
            . '.',
        );
    }

    private static function encode(string $text, bool $keepSlashes): string
    {
        $encoded = rawurlencode($text);
        return $keepSlashes ? str_replace('%2F', '/', $encoded) : $encoded;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $path = $request instanceof Request ? $request->path() : $request->getUri()->getPath();
        if ($path === '') {
            // An absolute-form target may name only the authority.
            $path = '/';
        }
        $parameters = [];
        $handler = $this->find($method, $path, $parameters);
        // HTTP-only routers do not read upgrade headers or search another table.
        if (
            $this->websockets !== null
            && $method === 'GET'
            && ($handler === null || $request->getHeaderLine('Upgrade') !== '')
        ) {
            $socketParameters = [];
            $socketHandler = $this->find('WS', $path, $socketParameters);
            if ($socketHandler !== null) {
                $handler = $socketHandler;
                $parameters = $socketParameters;
            }
        }
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
     * Whether a route was given the name of an action class: one with a
     * handle() method that is not a PSR-15 handler, whose handle() takes
     * the request and nothing else.
     */
    private static function isActionClass(mixed $action): bool
    {
        return (
            is_string($action)
            && method_exists($action, 'handle')
            && !is_a($action, RequestHandlerInterface::class, true)
        );
    }

    /**
     * @param array<string, string> $parameters Filled with the decoded parameters of a match.
     */
    private function find(string $method, string $path, array &$parameters): ?RequestHandlerInterface
    {
        $handler = $this->static[$method][$path] ?? null;
        if ($handler === null && str_contains($path, '%')) {
            // A route is declared as it reads, "/café"; a request names it encoded.
            $handler = $this->static[$method][rawurldecode($path)] ?? null;
        }
        if ($handler !== null || !isset($this->dynamic[$method])) {
            return $handler;
        }
        foreach ($this->combined[$method] ??= $this->combine($method) as $expression) {
            if (preg_match($expression, $path, $match) !== 1) {
                continue;
            }

            [, $names, $handler] = $this->dynamic[$method][(int) $match['MARK']];
            foreach ($names as $index => $name) {
                $parameters[$name] = rawurldecode($match[$index + 1]);
            }
            return $handler;
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
        $httpGet = false;
        foreach (array_keys($this->static + $this->dynamic) as $method) {
            if ($this->find((string) $method, $path, $ignored) === null) {
                continue;
            }

            $allowed[] = $method === 'WS' ? 'GET' : (string) $method;
            $httpGet = $httpGet || $method === 'GET';
        }
        if ($allowed === []) {
            return ErrorResponse::make($request, 404);
        }
        $allowed = array_values(array_unique($allowed));
        if ($httpGet && !in_array('HEAD', $allowed, true)) {
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
        preg_match_all(self::PARAMETER, $pattern, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
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
