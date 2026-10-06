<?php

declare(strict_types=1);

namespace Crab\Routing;

use Crab\Contracts\Routing\RouteInfo;
use Crab\Contracts\Routing\Router;
use Crab\Contracts\Validation\Validator;
use Psr\Http\Message\ResponseInterface;

/**
 * Writes the OpenAPI 3.1 description of an application from its routes.
 * Nothing is annotated for it: a route's parameters, input class and
 * return type, as its handler declares them, are the description.
 */
final class OpenApi
{
    private const JSON_TYPES = ['string' => 'string', 'int' => 'integer', 'float' => 'number', 'bool' => 'boolean', 'array' => 'array'];

    /**
     * The schema of each class met so far, by the name it is listed under.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $schemas = [];
    /**
     * @var array<string, class-string> The class behind each of those names.
     */
    private array $classes = [];

    public function __construct(private readonly Router $router, private readonly Validator $validator)
    {
    }

    /**
     * @return array<string, mixed> The document, ready for json_encode().
     */
    public function document(string $title, string $version): array
    {
        $this->schemas = [];
        $this->classes = [];
        $paths = [];
        foreach ($this->router->routes() as $route) {
            // OpenAPI names a parameter without the expression it must match.
            $path = (string) preg_replace('/\{([A-Za-z_][A-Za-z0-9_]*):(?:[^{}]++|\{[^{}]*\})+\}/', '{$1}', $route->pattern);
            $paths[$path][strtolower($route->method)] = $this->operation($route);
        }
        $document = [
            'openapi' => '3.1.0',
            'info' => ['title' => $title, 'version' => $version],
            'paths' => $paths === [] ? new \stdClass() : $paths,
        ];
        if ($this->schemas !== []) {
            ksort($this->schemas);
            $document['components'] = ['schemas' => $this->schemas];
        }
        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(RouteInfo $route): array
    {
        $operation = [];
        $parameters = [];
        $checked = false;
        foreach ($route->parameters as $name => $type) {
            $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => self::JSON_TYPES[$type]]];
            $checked = $checked || $type !== 'string';
        }
        if ($route->inputFrom === 'query') {
            $schema = $route->fields ?? $this->validator->schema($route->input);
            $required = $schema['required'] ?? [];
            foreach ((array) $schema['properties'] as $name => $property) {
                $parameters[] = ['name' => $name, 'in' => 'query', 'required' => in_array($name, $required, true), 'schema' => $property];
            }
        }
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }
        if ($route->inputFrom === 'body') {
            // A class is listed once and referred to; loose fields belong to this route alone.
            $schema = $route->fields ?? $this->reference($route->input);
            $operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => $schema]]];
        }
        $operation['responses'] = $this->success($route);
        if ($route->authenticated) {
            $operation['responses']['401'] = ['description' => 'Authentication is required.'];
        }
        if ($route->inputFrom !== null || $checked) {
            $operation['responses']['422'] = [
                'description' => 'The request is invalid.',
                'content' => ['application/json' => ['schema' => $this->invalid()]],
            ];
        }
        return $operation;
    }

    /**
     * The response of a request that was handled, from what the handler
     * declares it returns.
     *
     * @return array<string, array<string, mixed>>
     */
    private function success(RouteInfo $route): array
    {
        $output = $route->output;
        if ($output === 'void' || $output === 'null') {
            return ['204' => ['description' => 'No Content']];
        }
        if ($output === 'string' && !$route->outputList) {
            return ['200' => ['description' => 'OK', 'content' => ['text/plain' => ['schema' => ['type' => 'string']]]]];
        }
        if (isset(self::JSON_TYPES[$output])) {
            $schema = ['type' => self::JSON_TYPES[$output]];
        } elseif ($output !== null && class_exists($output) && !is_a($output, ResponseInterface::class, true) && !is_a($output, \JsonSerializable::class, true)) {
            $schema = $this->reference($output);
        } else {
            // Not declared, or a response the handler builds itself: there is nothing to describe it by.
            return ['200' => ['description' => 'OK']];
        }
        if ($route->outputList) {
            $schema = ['type' => 'array', 'items' => $schema];
        }
        return ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => $schema]]]];
    }

    /**
     * Lists a class under components and returns the reference to it. Two
     * classes with the same short name are told apart by their full names.
     *
     * @param class-string $class
     * @return array{'$ref': string}
     */
    private function reference(string $class): array
    {
        $name = substr($class, (int) strrpos('\\' . $class, '\\'));
        if (($this->classes[$name] ?? $class) !== $class) {
            $name = str_replace('\\', '.', $class);
        }
        if (!isset($this->schemas[$name])) {
            $this->classes[$name] = $class;
            $this->schemas[$name] = $this->validator->schema($class);
        }
        return ['$ref' => '#/components/schemas/' . $name];
    }

    /**
     * The body of a 422, as ErrorResponse writes it.
     *
     * @return array{'$ref': string}
     */
    private function invalid(): array
    {
        $this->schemas['ValidationError'] ??= [
            'type' => 'object',
            'properties' => ['error' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'integer'],
                    'message' => ['type' => 'string'],
                    'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ],
                'required' => ['status', 'message'],
            ]],
            'required' => ['error'],
        ];
        return ['$ref' => '#/components/schemas/ValidationError'];
    }
}
