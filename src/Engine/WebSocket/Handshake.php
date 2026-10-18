<?php

declare(strict_types=1);

namespace Crab\Engine\WebSocket;

use Crab\Engine\Http\Request;
use Crab\Engine\Http\Response;
use Crab\Engine\Http\Upgrade;
use Crab\Engine\Network\Connection;

/**
 * Checks an upgrade without taking ownership of its connection. A caller can
 * finish admission work before turning an accepted response into an Upgrade.
 */
final readonly class Handshake
{
    public Response $response;
    public ?string $subprotocol;

    /**
     * @param list<string> $subprotocols Server preference order.
     * @param null|callable(Request): bool $accept Authentication or Origin policy.
     */
    public function __construct(Request $request, array $subprotocols = [], ?callable $accept = null)
    {
        foreach ($subprotocols as $protocol) {
            if (!is_string($protocol) || !self::isToken($protocol)) {
                throw new \InvalidArgumentException('WebSocket subprotocols must be HTTP tokens.');
            }
        }
        if (
            $request->method !== 'GET'
            || $request->version !== '1.1'
            || !$request->hasToken('upgrade', 'websocket')
            || !$request->hasToken('connection', 'upgrade')
            || $request->body->size() !== 0
        ) {
            $this->response = new Response(400, ['Connection' => 'close'], 'Invalid WebSocket upgrade.');
            $this->subprotocol = null;
            return;
        }

        if ($request->header('sec-websocket-version') !== '13') {
            $this->response = new Response(426, ['Connection' => 'close', 'Sec-WebSocket-Version' => '13']);
            $this->subprotocol = null;
            return;
        }

        $key = $request->header('sec-websocket-key') ?? '';
        $decoded = base64_decode($key, true);
        if ($decoded === false || strlen($decoded) !== 16 || base64_encode($decoded) !== $key) {
            $this->response = new Response(400, ['Connection' => 'close'], 'Invalid WebSocket key.');
            $this->subprotocol = null;
            return;
        }

        $offered = [];
        if (($header = $request->header('sec-websocket-protocol')) !== null) {
            foreach (explode(',', $header) as $item) {
                $protocol = trim($item);
                if (!self::isToken($protocol) || isset($offered[$protocol])) {
                    $this->response = new Response(400, ['Connection' => 'close'], 'Invalid WebSocket subprotocol.');
                    $this->subprotocol = null;
                    return;
                }
                $offered[$protocol] = true;
            }
        }

        if ($accept !== null && !$accept($request)) {
            $this->response = new Response(403, ['Connection' => 'close']);
            $this->subprotocol = null;
            return;
        }

        $headers = [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Accept' => base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)),
        ];
        $selected = null;
        foreach ($subprotocols as $protocol) {
            if (!isset($offered[$protocol])) {
                continue;
            }

            $headers['Sec-WebSocket-Protocol'] = $selected = $protocol;
            break;
        }

        $this->response = new Response(101, $headers);
        $this->subprotocol = $selected;
    }

    /**
     * @param callable(WebSocketSession, string, MessageType): void $onMessage
     * @param null|callable(WebSocketSession, Request): void $onOpen
     * @param null|callable(WebSocketSession, int, string): void $onClose
     */
    public function upgrade(
        callable $onMessage,
        ?callable $onOpen = null,
        ?callable $onClose = null,
        ?Config $config = null,
    ): Upgrade {
        if ($this->response->status !== 101) {
            throw new \LogicException('The WebSocket handshake was refused; return its HTTP response instead.');
        }
        return new Upgrade($this->response, function (
            Connection $connection,
            Request $request,
            string $unreadBytes,
        ) use ($onMessage, $onOpen, $onClose, $config): void {
            $session = new WebSocketSession(
                $connection,
                $onMessage,
                $config ?? new Config(),
                $onClose,
                $this->subprotocol,
            );
            try {
                if ($onOpen !== null) {
                    $onOpen($session, $request);
                }
                if ($unreadBytes !== '') {
                    $session->receive($unreadBytes);
                }
            } catch (\Throwable $error) {
                $connection->reportFailure('WebSocket open handler failed', $error);
                $session->close(1011, 'Handler failed');
            }
        });
    }

    private static function isToken(string $value): bool
    {
        return preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $value) === 1;
    }
}
