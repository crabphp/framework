<?php

declare(strict_types=1);

namespace Crab\Engine\Server;

use Crab\Engine\Network\Connection;

/**
 * Serves raw bytes: every accepted connection's data goes to one handler.
 * A protocol that keeps state per connection, or needs to hear of its
 * close or the worker's drain, says so in $onConnect, which is given each
 * connection before any of its bytes are read.
 *
 *     $group->listen('tcp://127.0.0.1:9000', new TcpServer(
 *         static fn (Connection $connection, string $bytes) => $connection->tryWrite($bytes),
 *         static fn (Connection $connection) => $connection->onClose($forget),
 *     ));
 */
final class TcpServer
{
    private \Closure $handler;
    private ?\Closure $onConnect;

    /**
     * @param callable(Connection, string): void $handler Receives what a connection read; must not suspend.
     * @param (callable(Connection): void)|null $onConnect Receives each accepted connection once; must not suspend.
     */
    public function __construct(callable $handler, ?callable $onConnect = null)
    {
        $this->handler = $handler(...);
        $this->onConnect = $onConnect === null ? null : $onConnect(...);
    }

    public function __invoke(Connection $connection): void
    {
        if ($this->onConnect !== null) {
            ($this->onConnect)($connection);
            if ($connection->isClosed()) {
                return;
            }
        }
        $connection->onData($this->handler);
    }

    public function attach(Connection $connection): void
    {
        $this($connection);
    }
}
