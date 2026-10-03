<?php

declare(strict_types=1);

namespace Crab\Engine\Server;

use Crab\Engine\Network\Connection;

final class TcpServer
{
    private \Closure $handler;

    public function __construct(callable $handler)
    {
        $this->handler = $handler(...);
    }

    public function __invoke(Connection $connection): void
    {
        $connection->onData($this->handler);
    }

    public function attach(Connection $connection): void
    {
        $this($connection);
    }
}
