<?php

declare(strict_types=1);

namespace Crab\Contracts\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Writes error responses for the requests of one protocol, in that
 * protocol's form: a package whose client speaks a format of its own
 * adds one to Representations, and the errors of its requests, the
 * router's 404 and a middleware's 401 included, are written by it. Every
 * other request keeps the application's format, its ErrorRenderer's or
 * Crab's own.
 *
 * It runs after all middleware, on the request as it arrived, so it sees
 * no user and no session, and must not look for them. The headers
 * middleware gave the error are kept unless the response it returns sets
 * the same header.
 */
interface Representation
{
    /**
     * The error as this protocol writes it, or null when the request is
     * not one of its own.
     *
     * @param Request $request The request as it arrived, before any middleware.
     */
    public function render(Problem $problem, Request $request): ?ResponseInterface;
}
