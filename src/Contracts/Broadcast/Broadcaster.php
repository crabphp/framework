<?php

declare(strict_types=1);

namespace Crab\Contracts\Broadcast;

use Crab\Contracts\Database\Connection;

/**
 * Sends text or JSON to joined sockets, from any application entry point.
 * Each selection is independent; a transaction announces only after commit.
 */
interface Broadcaster
{
    public function to(string ...$channels): self;

    public function within(Connection $transaction): self;

    public function send(array|string $message): void;
}
