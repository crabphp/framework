<?php

declare(strict_types=1);

namespace Crab\WebSocket;

use Crab\Contracts\Http\Request;
use Crab\Engine\Http\Request as EngineRequest;
use Crab\Engine\WebSocket\Handshake;
use Crab\Http\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Admission shared by socket routes in a worker. Actions start inside HTTP
 * and offer their upgrade to middleware only at their first socket use.
 */
final class Connections
{
    private int $reserved = 0;

    public function __construct(
        private readonly Settings $settings = new Settings(),
        private readonly ?\Closure $memberships = null,
    ) {}

    /**
     * Reserves before running application code. The request fiber waits for
     * either the action's first use or its ordinary HTTP exception.
     *
     * @param \Closure(Socket): mixed $action The action with its validated arguments.
     */
    public function upgrade(
        Request $request,
        string $route,
        \Closure $action,
        ?LoggerInterface $logger = null,
    ): ResponseInterface {
        if ($request->getBody()->getSize() !== 0) {
            return ErrorResponse::make($request, 400, 'A WebSocket upgrade cannot carry a request body.', [
                'Connection' => 'close',
            ]);
        }
        $handshake = $this->handshake($request);
        $response = $handshake->response;
        if ($response->status !== 101) {
            return ErrorResponse::make($request, $response->status, $response->body, $response->headers);
        }
        if ($this->reserved >= $this->settings->maxConnections) {
            return ErrorResponse::make(
                $request,
                503,
                'The worker has reached websocket.max_connections. Try again shortly.',
                ['Retry-After' => '1'],
            );
        }
        ++$this->reserved;
        $admission = new Admission(function (): void {
            --$this->reserved;
        });
        $opening = new Opening(
            $request,
            $response,
            $action,
            $this->settings,
            $admission,
            $route,
            $logger,
            $this->memberships,
        );
        return $opening->response();
    }

    private function handshake(Request $request): Handshake
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = $values;
        }
        $engineRequest = new EngineRequest(
            $request->getMethod(),
            $request->getRequestTarget(),
            $request->getProtocolVersion(),
            $headers,
        );
        return new Handshake($engineRequest);
    }
}
