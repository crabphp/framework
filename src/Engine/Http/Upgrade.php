<?php

declare(strict_types=1);

namespace Crab\Engine\Http;

final readonly class Upgrade
{
    public \Closure $handler;

    /**
     * @param callable(\Crab\Engine\Network\Connection, Request, string):void $handler
     */
    public function __construct(
        public Response $response,
        callable $handler,
    ) {
        if ($response->status !== 101 || $response->body !== '') {
            throw new \InvalidArgumentException('Upgrade requires a bodyless 101 response.');
        }
        $this->handler = $handler(...);
    }
}
