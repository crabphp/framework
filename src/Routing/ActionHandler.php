<?php

declare(strict_types=1);

namespace Crab\Routing;

use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Http\Request;
use Crab\Contracts\Validation\ObjectRule;
use Crab\Contracts\Validation\Rule;
use Crab\Contracts\Validation\Validator;
use Crab\Contracts\WebSocket\Socket;
use Crab\Http\ErrorResponse;
use Crab\Http\Response;
use Crab\Validation\Data;
use Crab\Validation\Fields;
use Crab\Validation\JsonSchema;
use Crab\Validation\RejectUnknown;
use Crab\Validation\ValidationException;
use Crab\WebSocket\Connections;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The PSR-15 handler of a route whose action is a closure, a class with
 * handle() or a method of a class. It calls the action with what its
 * signature asks for. Each parameter is one of these, and says which by
 * its name, its type or its mark:
 *
 *     a route parameter    named as in the pattern: string, int, float or bool
 *     a query field        marked #[FromQuery]: an int, float, string, bool,
 *                          array, enum or date of the query string, by its name
 *     the query            marked #[FromQuery] on a class, or on Data described
 *                          with #[Fields]: the whole query string
 *     the body             a class, or Data described with #[Fields]: the
 *                          whole body, JSON or a form with its files
 *     the request          typed as a PSR-7 server request
 *     the user             typed as a class that implements Identity: whoever
 *                          middleware put on the request's "user" attribute
 *
 * A request whose path, query or body does not fit is answered with 422
 * and the problems by field, and one with no user where the action takes
 * one with 401; in neither case is the action called.
 *
 * What the action returns is the response: a PSR-7 response as it is, a
 * string as plain text, nothing as 204, anything else as JSON.
 *
 * The signature is read once, when the route is added, so an action that
 * asks for something else, or for a body on a request that has none,
 * stops the application from starting.
 */
final class ActionHandler implements RequestHandlerInterface
{
    /**
     * A name for the action, for the route list: "Closure", its class, or "Class::method".
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
     * The rules on the route parameters that have any, by name.
     *
     * @var array<string, list<Rule>>
     */
    private array $rules = [];
    /**
     * Parameters that receive the logged-in user: the class each must be,
     * and whether it may be null when nobody is logged in.
     *
     * @var array<string, array{class-string, bool}>
     */
    private array $users = [];
    /**
     * Parameters that are fields of the query string, one each.
     *
     * @var list<string>
     */
    private array $query = [];
    /**
     * The parameter that is the whole query string, and its class.
     *
     * @var array{string, class-string}|null
     */
    private ?array $queryInput = null;
    /**
     * The parameter that is the body, and its class.
     *
     * @var array{string, class-string}|null
     */
    private ?array $body = null;
    /**
     * Whether a request of the route's method has a body: GET, HEAD,
     * DELETE and a socket upgrade do not.
     */
    private readonly bool $hasBody;
    private ?string $socket = null;
    private readonly string $route;
    /**
     * The action's class and its method, when it is not a closure.
     *
     * @var array{class-string, string}|null
     */
    private readonly ?array $method;
    private ?\Closure $closure;

    /**
     * @param list<string> $names The pattern's parameter names.
     * @param \Closure|class-string|array{class-string, string} $action A closure, a class with handle(), or a class and the name of its method. The container makes the class.
     */
    public function __construct(
        string $method,
        string $pattern,
        array $names,
        \Closure|string|array $action,
        private readonly Validator $validator,
        private readonly ?ContainerInterface $container = null,
        private readonly ?Connections $websockets = null,
    ) {
        $route = $this->route = "{$method} {$pattern}";
        if ($action instanceof \Closure) {
            $this->closure = $action;
            $this->method = null;
            $this->signature = new \ReflectionFunction($action);
            $name = 'Closure';
        } else {
            $this->closure = null;
            $this->method = self::method($action, $route);
            $this->signature = new \ReflectionMethod(...$this->method);
            $name = $this->method[1] === 'handle' ? $this->method[0] : implode('::', $this->method);
        }
        $this->name = $name;
        $this->hasBody = !in_array($method, ['GET', 'HEAD', 'DELETE', 'WS'], true);

        foreach ($this->signature->getParameters() as $parameter) {
            $this->read($parameter, $names, $route, $pattern);
        }
        if ($method === 'WS' && $this->socket === null) {
            throw new \LogicException(
                "The action of {$route} needs a " . Socket::class . ' parameter. Declare handle(Socket $socket, ...).',
            );
        }
        if ($this->queryInput !== null && $this->query !== []) {
            throw new \LogicException(
                "The action of {$route} takes the query string as {$this->queryInput[1]} \${$this->queryInput[0]} and also its field \${$this->query[0]}; put every query field in the one, or take them all as fields.",
            );
        }
        try {
            // Read now, so that a field or class that cannot be validated is reported at startup.
            foreach ([$this->queryInput, $this->body] as $input) {
                if ($input !== null && ($input[1] === Data::class || !class_exists($input[1]))) {
                    $validator->inspectParameters($this->signature, [$input[0]]);
                } elseif ($input !== null) {
                    $validator->inspect($input[1]);
                }
            }
            if ($this->query !== []) {
                $validator->inspectParameters($this->signature, $this->query);
            }
        } catch (\LogicException $error) {
            throw new \LogicException(
                "The action of {$route} cannot take the request's data: {$error->getMessage()} If it is a service, it belongs in the constructor of an action class.",
                previous: $error,
            );
        }
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $arguments = [];
        $pathErrors = [];
        $queryErrors = [];
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
            $arguments[$name] = self::parameter((string) $request->getAttribute($name), $type, $name, $pathErrors);
            foreach ($arguments[$name] === null ? [] : $this->rules[$name] ?? [] as $rule) {
                $problem = $rule->check($arguments[$name]);
                if ($problem !== null) {
                    $pathErrors[$name][] = $problem;
                }
            }
        }
        if ($this->query !== [] || $this->queryInput !== null) {
            // A query string carries everything as text, "3" for 3.
            $query = $request->getQueryParams();
            try {
                if ($this->queryInput === null) {
                    $arguments += $this->validator->validateParameters($this->signature, $this->query, $query, true);
                } else {
                    $arguments += $this->whole($this->queryInput, $query, true);
                }
            } catch (ValidationException $invalid) {
                $queryErrors = $invalid->errors;
            }
        }
        if ($this->body !== null) {
            $data = $request->getParsedBody();
            if (!is_array($data)) {
                // No body at all: every field is missing, and is reported as that.
                $data = [];
            }
            // A form carries everything as text, "3" for 3; JSON says what each value is.
            $form =
                preg_match(
                    '~^(?:application/x-www-form-urlencoded|multipart/form-data)\b~i',
                    $request->getHeaderLine('Content-Type'),
                ) === 1;
            if ($form) {
                // A form's files are its fields too, where its text fields would be: "photos[]" beside "title".
                $data = array_replace_recursive($data, $request->getUploadedFiles());
            }
            try {
                $arguments += $this->whole($this->body, $data, $form);
            } catch (ValidationException $invalid) {
                $errors = $invalid->errors;
            }
        }
        if ($errors !== [] || $queryErrors !== [] || $pathErrors !== []) {
            return ErrorResponse::make(
                $request,
                422,
                'The request is invalid.',
                fields: $errors,
                query: $queryErrors,
                path: $pathErrors,
            );
        }
        // By name, so that a field the request leaves out takes the default the action gives it.
        if ($this->socket !== null) {
            if (!$request instanceof Request) {
                throw new \LogicException(
                    'A socket route needs a Crab HTTP request; use Http\\Request or the engine adapter.',
                );
            }
            $action = $this->closure ??= $this->callable();
            $name = $this->socket;
            try {
                $logger = $this->container !== null && $this->container->has(\Psr\Log\LoggerInterface::class)
                    ? $this->container->get(\Psr\Log\LoggerInterface::class)
                    : null;
                return $this->websockets->upgrade(
                    $request,
                    $this->route,
                    static function (Socket $socket) use ($action, $name, $arguments): mixed {
                        return $action(...[$name => $socket, ...$arguments]);
                    },
                    $logger,
                );
            } catch (ValidationException $invalid) {
                return ErrorResponse::make($request, 422, 'The request is invalid.', fields: $invalid->errors);
            }
        }
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
     * What the action takes, as JSON Schema; see Router::input().
     *
     * @return array{parameters: array<string, mixed>, query?: array<string, mixed>, body?: array<string, mixed>, user: array{class: string, required: bool}|null}
     */
    public function input(): array
    {
        $parameters = [];
        foreach ($this->parameters as $name => $type) {
            $parameters[$name] = [
                'type' => ['int' => 'integer', 'float' => 'number', 'bool' => 'boolean'][$type] ?? 'string',
                ...JsonSchema::rules($this->rules[$name] ?? []),
            ];
        }
        $input = ['parameters' => $parameters === [] ? new \stdClass() : $parameters];
        if ($this->query !== []) {
            $input['query'] = $this->validator->parameterSchema($this->signature, $this->query);
        } elseif ($this->queryInput !== null) {
            $input['query'] = $this->schema($this->queryInput);
        }
        if ($this->body !== null) {
            $input['body'] = $this->schema($this->body);
        }
        $user = null;
        foreach ($this->users as [$class, $optional]) {
            $user = ['class' => $class, 'required' => !$optional];
        }
        $input['user'] = $user;
        return $input;
    }

    /**
     * The schema of a parameter that is a whole query string or body.
     *
     * @param array{string, class-string} $input
     * @return array<string, mixed>
     */
    private function schema(array $input): array
    {
        [$name, $class] = $input;
        return $class === Data::class
            ? $this->validator->parameterSchema($this->signature, [$name])['properties'][$name]
            : $this->validator->schema($class);
    }

    /**
     * A whole query string or body as the parameter that takes it: an
     * object of its class, or the Data it describes. The data as a whole
     * stands for that one parameter, so a problem is reported by the
     * field's own name, "lines.0.quantity", without the parameter's.
     *
     * @param array{string, class-string} $input
     * @param array<mixed> $data
     * @return array<string, object>
     */
    private function whole(array $input, array $data, bool $text): array
    {
        [$name, $class] = $input;
        if ($class !== Data::class) {
            return [$name => $this->validator->validate($class, $data, $text)];
        }
        try {
            return $this->validator->validateParameters($this->signature, [$name], [$name => $data], $text);
        } catch (ValidationException $invalid) {
            $errors = [];
            foreach ($invalid->errors as $field => $problems) {
                $errors[str_starts_with($field, $name . '.') ? substr($field, strlen($name) + 1) : $field] = $problems;
            }
            throw new ValidationException($errors);
        }
    }

    /**
     * Sorts one parameter of the action into what it receives.
     *
     * @param list<string> $names The pattern's parameter names.
     */
    private function read(\ReflectionParameter $parameter, array $names, string $route, string $pattern): void
    {
        $name = $parameter->getName();
        $type = $parameter->getType();
        if ($type === null) {
            // Check before route names, since a name alone cannot declare the parameter's type.
            throw new \LogicException(
                "The action {$this->name} of {$route} takes \${$name} with no type. Say what it is: a parameter of the path or a query field by its type, such as string \${$name}, the body by its class, or the request, as "
                . ServerRequestInterface::class
                . '.',
            );
        }
        $fromQuery = $parameter->getAttributes(FromQuery::class) !== [];
        if ($fromQuery && $type instanceof \ReflectionUnionType && !$parameter->isVariadic()) {
            // A query field of several scalar kinds, int|bool; the validator says whether it can be one.
            $this->query[] = $name;
            return;
        }
        if ($parameter->isVariadic() || !$type instanceof \ReflectionNamedType) {
            throw new \LogicException("The action of {$route} takes \${$name}, which must have one plain type.");
        }
        $typeName = $type->getName();
        $marked = static function (string $what) use ($route, $name): \LogicException {
            return new \LogicException(
                "The action of {$route} marks \${$name} #[FromQuery], but it is {$what}, which is not read from the query string; drop the mark.",
            );
        };
        if (is_a($typeName, Socket::class, true)) {
            if ($fromQuery) {
                throw $marked('the socket');
            }
            if ($this->websockets === null) {
                throw new \LogicException(
                    "The action of {$route} takes a Socket parameter. Declare it with Route::websocket() instead of an HTTP route.",
                );
            }
            if ($typeName !== Socket::class && $typeName !== \Crab\WebSocket\Socket::class) {
                throw new \LogicException(
                    "The action of {$route} takes {$typeName}; use " . Socket::class . ' for the socket parameter.',
                );
            }
            if ($this->socket !== null || $type->allowsNull() || $parameter->isPassedByReference()) {
                throw new \LogicException(
                    "The action of {$route} needs one non-null Socket parameter, passed by value.",
                );
            }
            $this->socket = $name;
        } elseif (in_array($name, $names, true)) {
            if ($fromQuery) {
                throw $marked("a parameter of the path {$pattern}");
            }
            if (!in_array($typeName, ['string', 'int', 'float', 'bool'], true)) {
                throw new \LogicException(
                    "The action of {$route} takes the route parameter \${$name} as {$typeName}; it can be string, int, float or bool.",
                );
            }
            $this->parameters[$name] = $typeName;
            // The rules on it, #[Range(min: 1)] int $id, hold for a part of the address as for any field.
            foreach ($parameter->getAttributes(Rule::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $this->rules[$name][] = $attribute->newInstance();
            }
        } elseif (is_a($typeName, ServerRequestInterface::class, true)) {
            if ($fromQuery) {
                throw $marked('the request');
            }
            $this->requests[] = $name;
        } elseif (is_a($typeName, Identity::class, true)) {
            if ($fromQuery) {
                throw $marked('the logged-in user');
            }
            $this->users[$name] = [$typeName, $type->allowsNull()];
        } elseif ($typeName === UploadedFileInterface::class) {
            throw new \LogicException(
                $fromQuery
                    ? "The action of {$route} takes the file \${$name} from the query string, which carries no file. A file is a field of the body: declare it in the body's input class."
                    : "The action of {$route} takes the file \${$name}. A file is a field of the body: declare it in the body's input class, public UploadedFileInterface \${$name}.",
            );
        } elseif (self::isField($typeName) && $parameter->getAttributes(Fields::class) === []) {
            if (!$fromQuery) {
                throw new \LogicException(
                    "The action of {$route} takes {$typeName} \${$name}, which is not a parameter of the path {$pattern}. A field of the query string is marked #[FromQuery] {$typeName} \${$name}; a field of the body belongs in the body's input class, or in Data described with #[Fields].",
                );
            }
            $this->query[] = $name;
        } elseif ($typeName === Data::class || class_exists($typeName) || self::isField($typeName)) {
            // Data is the whole of the query string or the body, described on the parameter with #[Fields];
            // a field's type with #[Fields] is a mistake for it, which the validator names below.
            $what = $typeName === Data::class ? 'describe their fields in one' : 'put their fields in one class';
            if ($typeName !== Data::class) {
                // What shapes a class is said on the class, where every route that takes it sees it.
                $shaping = [
                    ...$parameter->getAttributes(RejectUnknown::class),
                    ...$parameter->getAttributes(ObjectRule::class, \ReflectionAttribute::IS_INSTANCEOF),
                ];
                if ($shaping !== []) {
                    throw new \LogicException(
                        "The action of {$route} marks \${$name} #[{$shaping[0]->getName()}], which belongs on the class {$typeName} itself.",
                    );
                }
            }
            if ($fromQuery) {
                if ($this->queryInput !== null) {
                    throw new \LogicException(
                        "The action of {$route} takes the query string twice, as {$this->queryInput[1]} and {$typeName}; {$what}.",
                    );
                }
                $this->queryInput = [$name, $typeName];
            } elseif (!$this->hasBody) {
                throw new \LogicException(
                    "The action of {$route} takes {$typeName} \${$name} as its body, and "
                    . ($this->websockets === null ? 'a ' . strtok($route, ' ') . ' request' : 'a socket upgrade')
                    . ' has none. Mark it #[FromQuery] to read the query string; if it is a service, it belongs in the constructor of an action class.',
                );
            } elseif ($this->body !== null) {
                throw new \LogicException(
                    "The action of {$route} takes two bodies, {$this->body[1]} and {$typeName}, and a request has one; {$what}.",
                );
            } else {
                $this->body = [$name, $typeName];
            }
        } else {
            throw new \LogicException(
                "The action of {$route} takes {$typeName} \${$name}, which is neither a parameter of the path, a query field, the body, the request nor the user. Services belong in the constructor of an action class.",
            );
        }
    }

    /**
     * Whether a parameter of this type is one value of the request's data.
     */
    private static function isField(string $type): bool
    {
        return (
            in_array($type, ['int', 'float', 'string', 'bool', 'array', 'mixed'], true)
            || $type === \DateTimeImmutable::class
            || $type === \DateTimeInterface::class
            || $type === UploadedFileInterface::class
            || enum_exists($type)
        );
    }

    /**
     * The action's class and method, checked to be something that can be called.
     *
     * @param string|array<mixed> $action
     * @return array{class-string, string}
     */
    private static function method(string|array $action, string $route): array
    {
        if (is_string($action)) {
            $action = [$action, 'handle'];
        }
        $wellFormed = array_is_list($action) && count($action) === 2 && is_string($action[0]) && is_string($action[1]);
        if (!$wellFormed) {
            throw new \LogicException(
                "The action of {$route} is an array, which must be a class and the name of its method: [Users::class, 'show'].",
            );
        }
        [$class, $method] = $action;
        if (!class_exists($class)) {
            throw new \LogicException("The action of {$route} names the class {$class}, which does not exist.");
        }
        if (!method_exists($class, $method) || !new \ReflectionMethod($class, $method)->isPublic()) {
            throw new \LogicException(
                "The action of {$route} names {$class}::{$method}(), which is not a public method of that class.",
            );
        }
        return [$class, $method];
    }

    /**
     * The action class's method as a closure, made in the worker on the
     * first request so that its services are the worker's own.
     */
    private function callable(): \Closure
    {
        [$class, $method] = $this->method;
        $object = $this->container?->has($class) ? $this->container->get($class) : new $class();
        return $object->$method(...);
    }

    /**
     * A route parameter, which arrives as text, as the type the action asks for.
     *
     * @param array<string, list<string>> $errors
     */
    private static function parameter(
        string $value,
        string $type,
        string $name,
        array &$errors,
    ): string|int|float|bool|null {
        if ($type === 'string') {
            return $value;
        }
        // Only when the number read is the number written: one past what an integer holds would be another.
        if (
            $type === 'int'
            && preg_match('/^-?\d+$/D', $value) === 1
            && (string) (int) $value === preg_replace('/^(-?)0+(?=\d)/', '$1', $value)
        ) {
            return (int) $value;
        }
        if ($type === 'float' && is_numeric($value)) {
            $number = (float) $value;
            if (is_finite($number)) {
                return $number;
            }
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
