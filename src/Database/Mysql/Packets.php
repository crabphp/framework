<?php

declare(strict_types=1);

namespace Crab\Database\Mysql;

/**
 * Frames logical messages into MySQL packets with a shared sequence counter.
 * An exact 16 MiB boundary needs an empty final packet to end the message.
 *
 * @internal
 */
final class Packets
{
    public const LIMIT = 16_777_215;

    /**
     * @return \Generator<int, string>
     */
    public static function encode(string $payload, int &$sequence): \Generator
    {
        $offset = 0;
        do {
            $chunk = substr($payload, $offset, self::LIMIT);
            $length = strlen($chunk);
            yield substr(pack('V', $length), 0, 3) . chr($sequence) . $chunk;
            $sequence = ($sequence + 1) & 255;
            $offset += $length;
        } while ($length === self::LIMIT);
    }

    /**
     * Reads incrementally without repeatedly copying a partially received message.
     *
     * @param \Closure(int): string $read Returns exactly the requested bytes.
     */
    public static function read(\Closure $read, int &$sequence): string
    {
        $parts = [];
        do {
            $header = $read(4);
            if (strlen($header) !== 4) {
                throw new \UnexpectedValueException('MySQL sent a truncated packet header.');
            }
            $word = unpack('V', $header)[1];
            $length = $word & self::LIMIT;
            $received = $word >> 24;
            if ($received !== $sequence) {
                throw new \UnexpectedValueException(
                    'MySQL packet sequence is out of order; reconnect before trying again.',
                );
            }
            $sequence = ($sequence + 1) & 255;
            $parts[] = $read($length);
        } while ($length === self::LIMIT);
        return count($parts) === 1 ? $parts[0] : implode('', $parts);
    }
}
