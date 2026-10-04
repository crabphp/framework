<?php

declare(strict_types=1);

namespace Crab\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gives every request an id: the X-Request-Id it arrived with when that is
 * a plain token, otherwise a new one. The id is the request's "request_id"
 * attribute and is returned in the response's X-Request-Id.
 */
final class RequestId implements MiddlewareInterface
{
    public const ATTRIBUTE = 'request_id';

    /**
     * Distinguishes this worker's ids from every other worker's.
     */
    private readonly string $prefix;
    private int $count = 0;

    public function __construct()
    {
        $this->prefix = bin2hex(random_bytes(6));
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $id = $request->getHeaderLine('X-Request-Id');
        if ($id === '' || strlen($id) > 128 || preg_match('/^[A-Za-z0-9._-]+$/D', $id) !== 1) {
            $id = $this->prefix . '-' . dechex(++$this->count);
        }
        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $id))->withHeader('X-Request-Id', $id);
    }
}
