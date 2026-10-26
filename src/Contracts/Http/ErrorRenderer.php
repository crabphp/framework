<?php

declare(strict_types=1);

namespace Crab\Contracts\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Writes every error response in the application's own format. Bind one
 * in a provider's register() and it writes them all: the router's 404 and
 * 405, a validation 422, a middleware's 401 or 429, an HttpError such as
 * HttpException, and a failure's 500.
 *
 * It runs after all middleware, so the headers they gave the error, such
 * as Allow, WWW-Authenticate, Retry-After and CORS, are kept unless the
 * response it returns sets the same header. Its status and body are the
 * ones sent.
 */
interface ErrorRenderer
{
    public function render(Problem $problem, ServerRequestInterface $request): ResponseInterface;
}
