<?php

declare(strict_types=1);

namespace Crab\Testing;

/**
 * A refused handshake is an HTTP answer. Keep that answer available for
 * the same status, header and JSON assertions as any API request.
 */
final class WebSocketRefused extends \RuntimeException
{
    public function __construct(public readonly ApiResponse $response)
    {
        parent::__construct("The WebSocket handshake was refused with HTTP {$response->status}: {$response->body()}");
    }
}
