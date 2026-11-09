<?php

declare(strict_types=1);

namespace Crab\Tests\Engine;

use Crab\Engine\Async\Async;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\Connector;
use Crab\Engine\Network\Listener;
use PHPUnit\Framework\TestCase;

final class ConnectorTest extends TestCase
{
    public function testItConnectsAWorkerLoopAndRelaysBytes(): void
    {
        $loop = new StreamSelectEventLoop();
        $listenerStream = Listener::bind('tcp://127.0.0.1:0');
        $address = (string) stream_socket_get_name($listenerStream, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        $listener = new Listener($loop, $listenerStream, static function (Connection $connection): void {
            $connection->onData(static function (Connection $connection, string $data): void {
                $connection->tryWrite($data);
            });
        });
        $listener->attach();
        $task = Task::spawn($loop, static function () use ($loop, $port): string {
            $connection = new Connector()->connect($loop, '127.0.0.1', $port, (hrtime(true) / 1_000_000_000) + 2);
            $received = '';
            $connection->tryWrite('hello from crab');
            Async::await($loop, static function (\Closure $resume) use ($connection, &$received): \Closure {
                $connection->onData(static function (Connection $connection, string $data) use (
                    &$received,
                    $resume,
                ): void {
                    $received .= $data;
                    $resume();
                });
                return static function (): void {};
            });
            $connection->close();
            return $received;
        });
        $loop->delay(3, $loop->stop(...));
        $loop->run();
        $listener->close();

        self::assertTrue($task->isComplete());
        self::assertSame('hello from crab', $task->result());
    }
}
