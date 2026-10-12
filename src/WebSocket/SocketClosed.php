<?php

declare(strict_types=1);

namespace Crab\WebSocket;

/**
 * Sending can no longer continue. An action may let this end a send loop;
 * losing or closing a connection is an ordinary end, not a worker failure.
 */
final class SocketClosed extends \RuntimeException
{
}
