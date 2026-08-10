<?php

use Crab\Engine\Connection\TcpConnection;
use Crab\Engine\Crab;
use Crab\Engine\EventLoop\SelectLoop;
use Crab\Engine\Net\SocketAddress;
use Crab\Engine\Net\TcpServer;
use Crab\Engine\Protocol\TextProtocol;
use Crab\Engine\Server;

require_once __DIR__ . '/vendor/autoload.php';

$server = Server::listen('text://0.0.0.0:9000');
$server->processCount = 4;

$server->onConnect(function ($connection): void {
    echo "new connection\n";
});

$server->onMessage(function ($connection, string $message) {
    $connection->send("hello $message");
});

Crab::run();
