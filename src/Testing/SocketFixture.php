<?php

declare(strict_types=1);

namespace Crab\Testing;

/**
 * Shared by Api's immutable variants. The fixture owns each socket until
 * test teardown, including when an assertion skips the developer's close.
 *
 * @internal
 */
final class SocketFixture
{
    /**
     * @var list<TestSocket>
     */
    private array $sockets = [];

    public function own(TestSocket $socket): TestSocket
    {
        $this->sockets[] = $socket;
        return $socket;
    }

    public function close(): void
    {
        $sockets = $this->sockets;
        $this->sockets = [];
        $failure = null;
        foreach ($sockets as $socket) {
            try {
                $socket->close();
            } catch (\Throwable $error) {
                $failure ??= $error;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
