<?php

namespace Crab\Engine;

use Crab\Engine\EventLoop\EventLoop;
use Crab\Engine\Net\SocketAddress;
use Crab\Engine\Net\TcpServer;
use Crab\Engine\Protocol\TextProtocol;

class Server
{
    public int $processCount = 1 {
        set {
            if ($value < 1) {
                throw new \InvalidArgumentException('Server processCount must be at least 1.');
            }

            $this->processCount = $value;
        }
    }

    private mixed $onConnect = null;

    private mixed $onMessage = null;

    private mixed $onClose = null;

    private ?TcpServer $tcpServer = null;

    private function __construct(
        private string $listenAddress,
    ) {}

    public static function listen(string $address): self
    {
        $server = new self($address);

        Crab::addServer($server);

        return $server;
    }

    public function onConnect(callable $callback): self
    {
        $this->onConnect = $callback;
        return $this;
    }

    public function onMessage(callable $callback): self
    {
        $this->onMessage = $callback;
        return $this;
    }

    public function onClose(callable $callback): self
    {
        $this->onClose = $callback;
        return $this;
    }

    private function protocolFor(string $scheme): ?object
    {
        return match ($scheme) {
            'text' => new TextProtocol(),
            'tcp' => null,
            default => throw new \InvalidArgumentException("Unsupported scheme: $scheme"),
        };
    }

    public function attach(EventLoop $loop): void
    {
        $address = SocketAddress::parse($this->listenAddress);
        $protocol = $this->protocolFor($address->scheme);
        $serverAddress = new SocketAddress('tcp', $address->host, $address->port);

        $this->tcpServer = new TcpServer($loop, $serverAddress, onClient: function ($socket, string $remote) use (
            $loop,
            $protocol,
        ): void {
            $connection = new Connection\TcpConnection($loop, $socket, $remote);
            $connection->useProtocol($protocol);

            if ($this->onMessage !== null) {
                $connection->onMessage = function ($connection, mixed $message): void {
                    $this->safeCall($this->onMessage, $connection, $message);
                };
            }

            if ($this->onClose !== null) {
                $connection->onClose = function ($connection, mixed $message): void {
                    $this->safeCall($this->onClose, $connection, $message);
                };
            }

            if ($this->onConnect !== null) {
                ($this->onConnect)($connection);
            }
        });

        $this->tcpServer->listen();
    }

    private function safeCall(callable $callback, mixed ...$args): void
    {
        try {
            $callback(...$args);
        } catch (\Throwable $e) {
            fwrite(STDERR, (string) $e);
        }
    }
}
