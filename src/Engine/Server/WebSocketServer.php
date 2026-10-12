<?php

declare(strict_types=1);

namespace Crab\Engine\Server;

use Closure;
use Crab\Engine\Http\Request;
use Crab\Engine\Http\Response;
use Crab\Engine\Http\Upgrade;
use Crab\Engine\Network\Connection;
use Crab\Engine\WebSocket\Config;
use Crab\Engine\WebSocket\Handshake;
use Crab\Engine\WebSocket\WebSocketSession;

final class WebSocketServer
{
    private HttpServer $http;
    private Closure $onMessage;
    private ?Closure $onOpen;
    private ?Closure $onClose;
    private ?Closure $accept;
    private Config $config;

    /**
     * @param callable(WebSocketSession, string, \Crab\Engine\WebSocket\MessageType): void $onMessage
     * @param null|callable(WebSocketSession, Request): void $onOpen
     * @param null|callable(WebSocketSession, int, string): void $onClose
     * @param list<string> $subprotocols Server preference order; no extensions are negotiated.
     * @param null|callable(Request): bool $accept Application authentication / Origin policy.
     */
    public function __construct(
        callable $onMessage,
        ?callable $onOpen = null,
        ?callable $onClose = null,
        ?Config $config = null,
        private readonly array $subprotocols = [],
        ?callable $accept = null,
    ) {
        foreach ($subprotocols as $protocol) {
            if (!is_string($protocol) || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $protocol) !== 1) {
                throw new \InvalidArgumentException('WebSocket subprotocols must be HTTP tokens.');
            }
        }
        $this->onMessage = Closure::fromCallable($onMessage);
        $this->onOpen = $onOpen === null ? null : Closure::fromCallable($onOpen);
        $this->onClose = $onClose === null ? null : Closure::fromCallable($onClose);
        $this->accept = $accept === null ? null : Closure::fromCallable($accept);
        $this->config = $config ?? new Config();
        $this->http = new HttpServer($this->handshake(...));
    }

    public function attach(Connection $connection): void
    {
        $this->http->attach($connection);
    }

    public function __invoke(Connection $connection): void
    {
        $this->attach($connection);
    }

    private function handshake(Request $request): Response|Upgrade
    {
        $handshake = new Handshake($request, $this->subprotocols, $this->accept);
        return $handshake->response->status === 101
            ? $handshake->upgrade($this->onMessage, $this->onOpen, $this->onClose, $this->config)
            : $handshake->response;
    }
}
