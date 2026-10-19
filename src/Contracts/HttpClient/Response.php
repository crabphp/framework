<?php

declare(strict_types=1);

namespace Crab\Contracts\HttpClient;

use Psr\Http\Message\ResponseInterface;

/**
 * What another server answered: a PSR-7 response with the two questions
 * most code asks of one.
 */
interface Response extends ResponseInterface
{
    /**
     * Whether the status is a success, 200 to 299.
     */
    public function ok(): bool;

    /**
     * Throws when the status is not a success, and otherwise returns the
     * response, so that what follows may count on one:
     *
     *     $user = $http->get($url)->throw()->json();
     *
     * @throws \Psr\Http\Client\ClientExceptionInterface Naming the request and the status; the component's ResponseException, which holds the response.
     */
    public function throw(): static;

    /**
     * The body read as JSON, an object as an array.
     *
     * @throws \JsonException When the body is not JSON.
     * @throws \LengthException When a JSON object has more than 1000 members.
     */
    public function json(): mixed;
}
