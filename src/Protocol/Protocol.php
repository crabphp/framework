<?php

namespace Crab\Engine\Protocol;

use Crab\Engine\Connection\TcpConnection;

interface Protocol
{
    public function input(string $buffer, TcpConnection $connection): int;

    public function decode(string $packet, TcpConnection $connection): mixed;

    public function encode(mixed $message, TcpConnection $connection): string;
}
