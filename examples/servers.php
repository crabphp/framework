<?php

declare(strict_types=1);

use Crab\Engine\Http\Response;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\UdpEndpoint;
use Crab\Engine\Network\WriteResult;
use Crab\Engine\Process\Worker;
use Crab\Engine\Runtime;
use Crab\Engine\Server\HttpServer;
use Crab\Engine\Server\TcpServer;
use Crab\Engine\Server\UdpServer;
use Crab\Engine\Server\WebSocketServer;
use Crab\Engine\WebSocket\MessageType;
use Crab\Engine\WebSocket\WebSocketSession;

$runtime = new Runtime();
$runtime->group(workers: (int) (getenv('CRAB_WORKERS') ?: 1))
    ->listen('tcp://127.0.0.1:8080', new HttpServer(
        static fn() => Response::text("Hello from Crab!\n"),
    ))
    ->listen('tcp://127.0.0.1:8081', new WebSocketServer(
        static function (WebSocketSession $session, string $message, MessageType $type): void {
            $result = $type === MessageType::Text ? $session->sendText($message) : $session->sendBinary($message);
            // This echo service chooses to disconnect an overloaded peer.
            if ($result === WriteResult::Backpressured) { $session->close(1013, 'Slow receiver'); }
        },
    ))
    ->listen('tcp://127.0.0.1:8082', new TcpServer(
        static function (Connection $connection, string $bytes): void {
            if ($connection->tryWrite($bytes) === WriteResult::Backpressured) { $connection->close(); }
        },
    ))
    ->udp('udp://127.0.0.1:8083', new UdpServer(
        static fn(UdpEndpoint $endpoint, string $packet, string $peer) => $endpoint->send($packet, $peer),
    ))
    ->onStart(static function (Worker $worker): void {
        fwrite(STDOUT, "Worker {$worker->index} ready; HTTP :8080, WebSocket :8081, TCP :8082, UDP :8083\n");
    });

return $runtime;
