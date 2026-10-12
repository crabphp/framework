<?php

declare(strict_types=1);

namespace Crab\Contracts\WebSocket;

use Crab\Contracts\Http\Request;
use Crab\Engine\Async\Cancellation;

/**
 * One connection, used by its action for the lifetime of the socket.
 * Arrays travel as JSON text; receiving waits only this action's fiber.
 *
 * @extends \IteratorAggregate<int, array|string|Binary>
 */
interface Socket extends \IteratorAggregate
{
    public Request $request { get; }

    public function accept(): void;

    public function cancellation(): Cancellation;

    public function send(array|string|Binary $message): void;

    public function sendBinary(string $bytes): void;

    public function join(string $channel): void;

    public function leave(string $channel): void;

    public function receive(): array|string|Binary|null;

    public function close(int $code = 1000, string $reason = ''): void;

    public function isClosed(): bool;
}
