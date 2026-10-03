<?php

declare(strict_types=1);

namespace Crab\Engine\Server;

use Closure;
use Crab\Engine\Http\Request;
use Crab\Engine\Http\Response;
use Crab\Engine\Http\Upgrade;
use Crab\Engine\Network\Connection;
use Crab\Engine\WebSocket\Config;
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
            if (!is_string($protocol) || !self::isToken($protocol)) {
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
        if ($request->method !== 'GET' || $request->version !== '1.1'
            || !$request->hasToken('upgrade', 'websocket')
            || !$request->hasToken('connection', 'upgrade') || $request->body->size() !== 0) {
            return new Response(400, ['Connection' => 'close'], 'Invalid WebSocket upgrade.');
        }

        if ($request->header('sec-websocket-version') !== '13') {
            return new Response(426, ['Connection' => 'close', 'Sec-WebSocket-Version' => '13']);
        }

        $key = $request->header('sec-websocket-key') ?? '';
        $decoded = base64_decode($key, true);
        if ($decoded === false || strlen($decoded) !== 16 || base64_encode($decoded) !== $key) {
            return new Response(400, ['Connection' => 'close'], 'Invalid WebSocket key.');
        }

        $offered = [];
        if (($header = $request->header('sec-websocket-protocol')) !== null) {
            foreach (explode(',', $header) as $item) {
                $protocol = trim($item);
                if (!self::isToken($protocol) || isset($offered[$protocol])) {
                    return new Response(400, ['Connection' => 'close'], 'Invalid WebSocket subprotocol.');
                }
                $offered[$protocol] = true;
            }
        }

        if ($this->accept !== null && !($this->accept)($request)) {
            return new Response(403, ['Connection' => 'close']);
        }

        $headers = [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Accept' => base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)),
        ];
        $selected = null;
        foreach ($this->subprotocols as $protocol) {
            if (isset($offered[$protocol])) {
                $headers['Sec-WebSocket-Protocol'] = $selected = $protocol;
                break;
            }
        }

        return new Upgrade(new Response(101, $headers), function (
            Connection $connection,
            Request $request,
            string $unreadBytes,
        ) use ($selected): void {
            $session = new WebSocketSession(
                $connection, $this->onMessage, $this->config, $this->onClose, $selected,
            );
            try {
                if ($this->onOpen !== null) {
                    ($this->onOpen)($session, $request);
                }
                if ($unreadBytes !== '') {
                    $session->receive($unreadBytes);
                }
            } catch (\Throwable) {
                $session->close(1011, 'Handler failed');
            }
        });
    }

    private static function isToken(string $value): bool
    {
        return preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $value) === 1;
    }
}
