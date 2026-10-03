<?php

declare(strict_types=1);

namespace Crab\Engine\Server;

use Crab\Engine\Network\UdpEndpoint;

final class UdpServer
{
    private \Closure $handler;

    public function __construct(callable $handler)
    {
        $this->handler = $handler(...);
    }

    public function __invoke(UdpEndpoint $endpoint, string $payload, string $peer): void
    {
        ($this->handler)($endpoint, $payload, $peer);
    }
}
