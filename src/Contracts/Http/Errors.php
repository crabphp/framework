<?php

declare(strict_types=1);

namespace Crab\Contracts\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Makes error responses the way the framework does, for a package: each
 * carries its Problem, so the application's ErrorRenderer, and any
 * Representation, writes it like every other error. A package returns
 * these and never builds an error response by hand.
 */
interface Errors
{
    /**
     * @param string $message Shown to the client; the status's reason phrase when empty.
     * @param array<string, string|list<string>> $headers
     * @param array<string, list<string>> $fields What is wrong with the request's body, by field.
     * @param array<string, list<string>> $query What is wrong with its query string, by field.
     * @param array<string, list<string>> $path What is wrong with the parameters of its path, by name.
     * @param array<string, string|int|bool> $context Safe values for whatever writes the problem; see Problem::$context.
     */
    public function make(
        ServerRequestInterface $request,
        int $status,
        string $message = '',
        array $headers = [],
        array $fields = [],
        array $query = [],
        array $path = [],
        array $context = [],
    ): ResponseInterface;

    /**
     * The problem an error response carries, or null for a response that
     * is not one of the framework's errors.
     */
    public function problem(ResponseInterface $response): ?Problem;
}
