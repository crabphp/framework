<?php

declare(strict_types=1);

namespace Crab\Database\Mysql;

/**
 * Little-endian and length-encoded values shared by MySQL's wire packets.
 * Every read checks its bounds before using bytes supplied by the server.
 *
 * @internal
 */
final class Codec
{
    /**
     * Copies a bounded field and advances its caller's packet offset.
     */
    public static function bytes(string $packet, int &$offset, int $length): string
    {
        if ($length < 0 || $offset < 0 || $length > (strlen($packet) - $offset)) {
            throw new \UnexpectedValueException('MySQL sent a truncated packet; reconnect before trying again.');
        }
        $value = substr($packet, $offset, $length);
        $offset += $length;
        return $value;
    }

    /**
     * Reads little-endian fields directly from the packet without a byte copy.
     * Eight-byte values retain their signed PHP bit pattern.
     */
    public static function integer(string $packet, int &$offset, int $length): int
    {
        if ($length < 0 || $offset < 0 || $length > (strlen($packet) - $offset)) {
            throw new \UnexpectedValueException('MySQL sent a truncated packet; reconnect before trying again.');
        }
        $value = match ($length) {
            1 => ord($packet[$offset]),
            2 => unpack('v', $packet, $offset)[1],
            3 => ord($packet[$offset]) | (ord($packet[$offset + 1]) << 8) | (ord($packet[$offset + 2]) << 16),
            4 => unpack('V', $packet, $offset)[1],
            8 => unpack('P', $packet, $offset)[1],
            default => self::oddWidth($packet, $offset, $length),
        };
        $offset += $length;
        return $value;
    }

    private static function oddWidth(string $packet, int $offset, int $length): int
    {
        $value = 0;
        for ($i = 0; $i < $length; ++$i) {
            $value |= ord($packet[$offset + $i]) << ($i * 8);
        }
        return $value;
    }

    /**
     * Unsigned 64-bit values beyond PHP's range stay decimal strings.
     */
    public static function unsigned64(string $bytes): int|string
    {
        $offset = 0;
        return self::unsigned64At($bytes, $offset);
    }

    /**
     * Avoids copying the common, representable unsigned BIGINT result.
     */
    public static function unsigned64At(string $packet, int &$offset): int|string
    {
        $start = $offset;
        $value = self::integer($packet, $offset, 8);
        if ($value >= 0) {
            return $value;
        }
        $parts = unpack('Vlow/Vhigh', $packet, $start);
        // Each partial product fits a 64-bit PHP integer, unlike the whole value.
        $low = $parts['low'] + (($parts['high'] % 1_000_000_000) * 4_294_967_296);
        $high = (intdiv($parts['high'], 1_000_000_000) * 4_294_967_296) + intdiv($low, 1_000_000_000);
        return (string) $high . str_pad((string) ($low % 1_000_000_000), 9, '0', STR_PAD_LEFT);
    }

    /**
     * Encodes a nonnegative length using MySQL's shortest length prefix.
     */
    public static function length(int $value): string
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('A MySQL encoded length must be nonnegative.');
        }
        return match (true) {
            $value < 251 => chr($value),
            $value <= 65_535 => "\xfc" . pack('v', $value),
            $value <= 16_777_215 => "\xfd" . substr(pack('V', $value), 0, 3),
            default => "\xfe" . pack('P', $value),
        };
    }

    /**
     * Distinguishes a NULL field from zero and an overflowing unsigned length.
     */
    public static function readLength(string $packet, int &$offset): int|string|null
    {
        if ($offset < 0 || $offset >= strlen($packet)) {
            throw new \UnexpectedValueException('MySQL sent a truncated packet; reconnect before trying again.');
        }
        $first = ord($packet[$offset++]);
        return match ($first) {
            251 => null,
            252 => self::integer($packet, $offset, 2),
            253 => self::integer($packet, $offset, 3),
            254 => self::unsigned64(self::bytes($packet, $offset, 8)),
            255 => throw new \UnexpectedValueException(
                'MySQL sent an invalid encoded length; reconnect before trying again.',
            ),
            default => $first,
        };
    }

    /**
     * Prefixes a binary-safe string with its byte length.
     */
    public static function string(string $value): string
    {
        return self::length(strlen($value)) . $value;
    }

    /**
     * Reads one bounded string; the text-row NULL marker remains null.
     */
    public static function readString(string $packet, int &$offset): ?string
    {
        if ($offset < 0 || $offset >= strlen($packet)) {
            throw new \UnexpectedValueException('MySQL sent a truncated packet; reconnect before trying again.');
        }
        $length = ord($packet[$offset]);
        if ($length < 251) {
            ++$offset;
        } else {
            $length = self::readLength($packet, $offset);
        }
        if ($length === null) {
            return null;
        }
        if (!is_int($length)) {
            throw new \UnexpectedValueException('MySQL sent a string larger than PHP can hold.');
        }
        if ($length > (strlen($packet) - $offset)) {
            throw new \UnexpectedValueException('MySQL sent a truncated packet; reconnect before trying again.');
        }
        $value = substr($packet, $offset, $length);
        $offset += $length;
        return $value;
    }

    /**
     * Metadata fields that are not retained still require complete bounds.
     */
    public static function skipString(string $packet, int &$offset): void
    {
        $length = self::readLength($packet, $offset);
        if (!is_int($length) || $length > (strlen($packet) - $offset)) {
            throw new \UnexpectedValueException('MySQL sent truncated column metadata.');
        }
        $offset += $length;
    }

    /**
     * Requires the terminator used by handshake names and authentication plugins.
     */
    public static function terminated(string $packet, int &$offset): string
    {
        $end = strpos($packet, "\0", $offset);
        if ($end === false) {
            throw new \UnexpectedValueException('MySQL sent an unterminated protocol value.');
        }
        $value = self::bytes($packet, $offset, $end - $offset);
        ++$offset;
        return $value;
    }

    /**
     * Preserves the server number, SQL state and message without adding values.
     */
    public static function error(string $packet): ServerError
    {
        $offset = 1;
        $number = self::integer($packet, $offset, 2);
        $state = 'HY000';
        if (($packet[$offset] ?? '') === '#') {
            ++$offset;
            $state = self::bytes($packet, $offset, 5);
        }
        return new ServerError(substr($packet, $offset), $number, $state);
    }

    /**
     * Validates every mandatory PROTOCOL_41 OK field, including warnings.
     * Optional information follows those fields and need not be retained.
     *
     * @return array{affected: int, id: int|string, status: int}
     */
    public static function ok(string $packet): array
    {
        $offset = 1;
        $affected = self::readLength($packet, $offset);
        $id = self::readLength($packet, $offset);
        if ($affected === null || $id === null) {
            throw new \UnexpectedValueException('MySQL sent a NULL count in an OK packet.');
        }
        $status = self::integer($packet, $offset, 2);
        self::integer($packet, $offset, 2);
        return ['affected' => (int) $affected, 'id' => $id, 'status' => $status];
    }
}
