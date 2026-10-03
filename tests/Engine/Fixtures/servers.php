<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Crab\Engine\Http\Request;
use Crab\Engine\Http\Response;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\UdpEndpoint;
use Crab\Engine\Runtime;
use Crab\Engine\Server\HttpServer;
use Crab\Engine\Server\TcpServer;
use Crab\Engine\Server\WebSocketServer;
use Crab\Engine\WebSocket\MessageType;
use Crab\Engine\WebSocket\WebSocketSession;

if (function_exists('posix_setsid') && posix_setsid() === -1) {
    throw new RuntimeException('Could not isolate the server fixture process group.');
}

[$http, $ws, $tcp, $udp] = array_map(intval(...), array_slice($argv, 1));
$runtime = new Runtime(shutdownTimeout: 0.2);
$runtime->group()
    ->listen("tcp://127.0.0.1:$http", new HttpServer(
        static fn (Request $request) => new Response(body: $request->body->contents() ?: $request->target),
    ))
    ->listen("tcp://127.0.0.1:$ws", new WebSocketServer(
        static fn (WebSocketSession $session, string $message, MessageType $type) => $session->sendBinary($message),
    ))
    ->listen("tcp://127.0.0.1:$tcp", new TcpServer(
        static fn (Connection $connection, string $bytes) => $connection->tryWrite($bytes),
    ))
    ->udp("udp://127.0.0.1:$udp", static fn (UdpEndpoint $endpoint, string $data, string $peer) => $endpoint->send($data, $peer))
    ->onStart(static function (): void {
        fwrite(STDOUT, "ready\n");
        fflush(STDOUT);
    });
$runtime->run(singleProcess: true);
