<?php

namespace Crab\Engine\Protocol;

use Crab\Engine\Connection\TcpConnection;

final class TextProtocol implements Protocol
{
    public function input(string $buffer, TcpConnection $connection): int
    {
        $position = strpos($buffer, "\n");

        if ($position === false) {
            return 0;
        }

        return $position + 1;
    }

    public function decode(string $packet, TcpConnection $connection): mixed
    {
        return rtrim($packet, "\r\n");
    }

    public function encode(mixed $message, TcpConnection $connection): string
    {
        return (string) $message . "\n";
    }
}
