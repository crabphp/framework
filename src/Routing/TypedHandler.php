<?php

declare(strict_types=1);

namespace Crab\Routing;

use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Validation\Validator;
use Crab\Http\ErrorResponse;
use Crab\Http\Response;
use Crab\Validation\ValidationException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A closure, an invokable class or a method of a class as a route's
 * handler, called with what its signature asks for. Each parameter is one
 * of these:
 *
 *     the request          typed as a PSR-7 server request, or not typed at all
 *     the user             typed as a class that implements Identity: whoever
 *                          middleware put on the request's "user" attribute
 *     a route parameter    named as in the pattern: string, int, float or bool
 *     a field              any other int, float, string, bool, array, enum or
 *                          date: one value of the request's data, by its name
 *     the input            one class holding all the fields instead
 *
 * The request's data is the JSON body, or the query string for GET, HEAD
 * and DELETE. A request whose data does not fit is answered with 422 and
 * the problems by field, and one with no user where the handler takes one
 * with 401; in neither case is the handler called.
 *
 * What the handler returns is the response: a PSR-7 response as it is, a
 * string as plain text, nothing as 204, anything else as JSON.
 *
 * The signature is read once, when the route is added, so a handler that
 * asks for something else stops the application from starting.
 */
final class TypedHandler implements RequestHandlerInterface
{
    /**
     * A name for the handler, for the route list: "Closure", its class, or "Class::method".
     */
    public readonly string $name;
    private readonly \ReflectionFunctionAbstract $signature;
    /**
     * Parameters that receive the request.
     *
     * @var list<string>
     */
    private array $requests = [];
    /**
     * Parameters that are route parameters, with the type each is asked for as.
     *
     * @var array<string, string>
     */
    private array $parameters = [];
    /**
     * Parameters that receive the logged-in user: the class each must be,
     * and whether it may be null when nobody is logged in.
     *
     * @var array<string, array{class-string, bool}>
     */
    private array $users = [];
    /**
     * Parameters that are fields of the request's data.
     *
     * @var list<string>
     */
    private array $fields = [];
    /**
     * The parameter that is the input class, and that class.
     *
     * @var array{string, class-string}|null
     */
    private ?array $input = null;
    private readonly bool $fromQuery;
    /**
     * The handler class and its method, when it is not a closure.
     *
     * @var array{class-string, string}|null
     */
    private readonly ?array $method;
    private ?\Closure $closure;

    /**
     * @param list<string> $names The pattern's parameter names.
     * @param \Closure|class-string|array{class-string, string} $handler A closure, a class with __invoke(), or a class and the name of its method. The container makes the class.
     */
    public function __construct(
        string $method,
        string $pattern,
        array $names,
        \Closure|string|array $handler,
        private readonly Validator $validator,
        private readonly ?ContainerInterface $container = null,
    ) {
        $route = "{$method} {$pattern}";
        if ($handler instanceof \Closure) {
            $this->closure = $handler;
            $this->method = null;
            $this->signature = new \ReflectionFunction($handler);
            $name = 'Closure';
        } else {
            $this->closure = null;
            $this->method = self::method($handler, $route);
            $this->signature = new \ReflectionMethod(...$this->method);
            $name = $this->method[1] === '__invoke' ? $this->method[0] : implode('::', $this->method);
        }
        $this->fromQuery = in_array($method, ['GET', 'HEAD', 'DELETE'], true);

        foreach ($this->signature->getParameters() as $parameter) {
            $this->read($parameter, $names, $route);
        }
        if ($this->input !== null && $this->fields !== []) {
            throw new \LogicException("The handler of {$route} takes the input {$this->input[1]} and also the field \${$this->fields[0]}; put every field in the class, or take them all as parameters.");
        }
        try {
            // Read now, so that a field or class that cannot be validated is reported at startup.
            if ($this->input !== null) {
                $validator->inspect($this->input[1]);
            } elseif ($this->fields !== []) {
                $validator->inspectParameters($this->signature, $this->fields);
            }
        } catch (\LogicException $error) {
            throw new \LogicException("The handler of {$route} cannot take the request's data: {$error->getMessage()} If it is a service, it belongs in the constructor of a handler class.", previous: $error);
        }
        $this->name = $name;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $arguments = [];
        $errors = [];
        foreach ($this->requests as $name) {
            $arguments[$name] = $request;
        }
        foreach ($this->users as $name => [$class, $optional]) {
            $user = $request->getAttribute(Identity::ATTRIBUTE);
            if (!$user instanceof $class) {
                if (!$optional) {
                    return ErrorResponse::make($request, 401, 'Authentication is required.');
                }
                $user = null;
            }
            $arguments[$name] = $user;
        }
        foreach ($this->parameters as $name => $type) {
            $arguments[$name] = self::parameter((string) $request->getAttribute($name), $type, $name, $errors);
        }
        if ($this->input !== null || $this->fields !== []) {
            $data = $this->fromQuery ? $request->getQueryParams() : $request->getParsedBody();
            if (!is_array($data)) {
                // No body at all: every field is missing, and is reported as that.
                $data = [];
            }
            try {
                if ($this->input !== null) {
                    $arguments[$this->input[0]] = $this->validator->validate($this->input[1], $data, $this->fromQuery);
                } else {
                    $arguments += $this->validator->validateParameters($this->signature, $this->fields, $data, $this->fromQuery);
                }
            } catch (ValidationException $invalid) {
                $errors += $invalid->errors;
            }
        }
        if ($errors !== []) {
            return ErrorResponse::make($request, 422, 'The request is invalid.', fields: $errors);
        }
        // By name, so that a field the request leaves out takes the default the handler gives it.
        $result = ($this->closure ??= $this->callable())(...$arguments);
        if ($result instanceof ResponseInterface) {
            return $result;
        }
        if (is_string($result)) {
            return Response::text($result);
        }
        if ($result === null) {
            return Response::noContent();
        }
        return Response::json($result);
    }

    /**
     * Sorts one parameter of the handler into what it receives.
     *
     * @param list<string> $names The pattern's parameter names.
     */
    private function read(\ReflectionParameter $parameter, array $names, string $route): void
    {
        $name = $parameter->getName();
        $type = $parameter->getType();
        if ($parameter->isVariadic() || ($type !== null && !$type instanceof \ReflectionNamedType)) {
            throw new \LogicException("The handler of {$route} takes \${$name}, which must have one plain type.");
        }
        $typeName = $type?->getName();
        if (in_array($name, $names, true)) {
            $typeName ??= 'string';
            if (!in_array($typeName, ['string', 'int', 'float', 'bool'], true)) {
                throw new \LogicException("The handler of {$route} takes the route parameter \${$name} as {$typeName}; it can be string, int, float or bool.");
            }
            $this->parameters[$name] = $typeName;
        } elseif ($typeName === null || is_a($typeName, ServerRequestInterface::class, true)) {
            $this->requests[] = $name;
        } elseif (is_a($typeName, Identity::class, true)) {
            $this->users[$name] = [$typeName, $type->allowsNull()];
        } elseif (self::isField($typeName)) {
            $this->fields[] = $name;
        } elseif (class_exists($typeName)) {
            if ($this->input !== null) {
                throw new \LogicException("The handler of {$route} takes two inputs, {$this->input[1]} and {$typeName}; put their fields in one class.");
            }
            $this->input = [$name, $typeName];
        } else {
            throw new \LogicException("The handler of {$route} takes {$typeName} \${$name}, which is neither the request, the user, a parameter of the pattern, a field nor an input class. Services belong in the constructor of a handler class.");
        }
    }

    /**
     * Whether a parameter of this type is one value of the request's data.
     */
    private static function isField(string $type): bool
    {
        return in_array($type, ['int', 'float', 'string', 'bool', 'array', 'mixed'], true)
            || $type === \DateTimeImmutable::class
            || $type === \DateTimeInterface::class
            || enum_exists($type);
    }

    /**
     * The handler's class and method, checked to be something that can be called.
     *
     * @param string|array<mixed> $handler
     * @return array{class-string, string}
     */
    private static function method(string|array $handler, string $route): array
    {
        if (is_string($handler)) {
            $handler = [$handler, '__invoke'];
        }
        $wellFormed = array_is_list($handler) && count($handler) === 2 && is_string($handler[0]) && is_string($handler[1]);
        if (!$wellFormed) {
            throw new \LogicException("The handler of {$route} is an array, which must be a class and the name of its method: [UserController::class, 'show'].");
        }
        [$class, $method] = $handler;
        if (!class_exists($class)) {
            throw new \LogicException("The handler of {$route} names the class {$class}, which does not exist.");
        }
        if (!method_exists($class, $method) || !(new \ReflectionMethod($class, $method))->isPublic()) {
            throw new \LogicException("The handler of {$route} names {$class}::{$method}(), which is not a public method of that class.");
        }
        return [$class, $method];
    }

    /**
     * The handler class's method as a closure, made in the worker on the
     * first request so that its services are the worker's own.
     */
    private function callable(): \Closure
    {
        [$class, $method] = $this->method;
        $object = $this->container?->has($class) ? $this->container->get($class) : new $class();
        return $object->$method(...);
    }

    /**
     * A route parameter, which arrives as text, as the type the handler asks for.
     *
     * @param array<string, list<string>> $errors
     */
    private static function parameter(string $value, string $type, string $name, array &$errors): string|int|float|bool|null
    {
        if ($type === 'string') {
            return $value;
        }
        if ($type === 'int' && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }
        if ($type === 'float' && is_numeric($value)) {
            return (float) $value;
        }
        if ($type === 'bool' && in_array($value, ['true', '1', 'false', '0'], true)) {
            return $value === 'true' || $value === '1';
        }
        $errors[$name][] = match ($type) {
            'int' => 'must be a whole number',
            'float' => 'must be a number',
            default => 'must be true or false',
        };
        return null;
    }
}
