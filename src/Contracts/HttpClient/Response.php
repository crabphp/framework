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
     * The body read as JSON, an object as an array.
     *
     * @throws \JsonException When the body is not JSON.
     */
    public function json(): mixed;
}
