<?php

declare(strict_types=1);

namespace Crab\Database\Mysql;

/**
 * Prepared statement parameters and rows use MySQL's binary value format.
 * Dates and decimals remain strings, matching PDO with native prepares.
 *
 * @internal
 */
final class Binary
{
    private const DECIMAL = 0;
    private const TINY = 1;
    private const SHORT = 2;
    private const LONG = 3;
    private const FLOAT = 4;
    private const DOUBLE = 5;
    private const NULL = 6;
    private const TIMESTAMP = 7;
    private const LONGLONG = 8;
    private const INT24 = 9;
    private const DATE = 10;
    private const TIME = 11;
    private const DATETIME = 12;
    private const YEAR = 13;
    private const VARCHAR = 15;
    private const BIT = 16;
    private const VECTOR = 242;
    private const JSON = 245;
    private const NEWDECIMAL = 246;
    private const ENUM = 247;
    private const SET = 248;
    private const TINY_BLOB = 249;
    private const MEDIUM_BLOB = 250;
    private const LONG_BLOB = 251;
    private const BLOB = 252;
    private const VAR_STRING = 253;
    private const STRING = 254;
    private const GEOMETRY = 255;
    private const UNSIGNED = 32;
    private const ZEROFILL = 64;
    private const EXECUTE = 0x17;
    private const DATE_LENGTH = 4;
    private const DATETIME_LENGTH = 7;
    private const DATETIME_FRACTIONS_LENGTH = 11;
    private const TIME_LENGTH = 8;
    private const TIME_FRACTIONS_LENGTH = 12;
    private const MAX_FRACTIONS = 6;
    private const COLUMN_FIELDS_LENGTH = 12;

    /**
     * Retains display width because PDO represents ZEROFILL as padded strings.
     *
     * @return array{name: string, type: int, flags: int, decimals: int, length: int}
     */
    public static function column(string $packet): array
    {
        $offset = 0;
        for ($i = 0; $i < 4; ++$i) {
            Codec::skipString($packet, $offset);
        }
        $name = Codec::readString($packet, $offset);
        Codec::skipString($packet, $offset); // Original column name is not returned.
        $fixed = Codec::readLength($packet, $offset);
        if ($fixed !== self::COLUMN_FIELDS_LENGTH || (strlen($packet) - $offset) < $fixed || $name === null) {
            throw new \UnexpectedValueException('MySQL sent truncated column metadata.');
        }
        // Decode the fixed-width run once; the leading charset and trailing
        // filler are not needed for binary-safe PHP result strings.
        $column = unpack('x2/Vlength/Ctype/vflags/Cdecimals', $packet, $offset);
        $column['name'] = $name;
        return $column;
    }

    /**
     * Compiles stable wire types once for a prepared handle's current metadata.
     * YEAR, ZEROFILL and floating/date/BIT types keep their PDO-specific path.
     *
     * @param array{type: int, flags: int} $column
     * @return array{kind: string, width: int, format: string, adjustment: int, unsigned: bool}
     */
    public static function layout(array $column): array
    {
        $type = $column['type'];
        $width = match ($type) {
            self::TINY => 1,
            self::SHORT => 2,
            self::LONG, self::INT24 => 4,
            self::LONGLONG => 8,
            default => 0,
        };
        $unsigned = (bool) ($column['flags'] & self::UNSIGNED);
        $format = match ($width) {
            1 => 'C',
            2 => 'v',
            4 => 'V',
            8 => 'P',
            default => '',
        };
        $kind = 'value';
        if ($width !== 0 && !($column['flags'] & self::ZEROFILL)) {
            $kind = 'integer';
        } elseif (in_array(
            $type,
            [
                self::DECIMAL,
                self::VARCHAR,
                self::VECTOR,
                self::JSON,
                self::NEWDECIMAL,
                self::ENUM,
                self::SET,
                self::TINY_BLOB,
                self::MEDIUM_BLOB,
                self::LONG_BLOB,
                self::BLOB,
                self::VAR_STRING,
                self::STRING,
                self::GEOMETRY,
            ],
            true,
        )) {
            $kind = 'string';
        }
        return [
            'kind' => $kind,
            'width' => $width,
            'format' => $format,
            'adjustment' => !$unsigned && $width > 0 && $width < 8 ? 1 << ($width * 8) : 0,
            'unsigned' => $unsigned,
        ];
    }

    /**
     * @param list<array{name: string, type: int, flags: int, decimals: int, length?: int}> $columns
     * @return array<string, mixed>
     */
    public static function row(string $packet, array $columns, bool $binary): array
    {
        $offset = $binary ? 1 : 0;
        $bitmap = $binary ? Codec::bytes($packet, $offset, intdiv(count($columns) + 9, 8)) : '';
        $row = [];
        foreach ($columns as $index => $column) {
            if ($binary && ord($bitmap[intdiv($index + 2, 8)]) & (1 << (($index + 2) % 8))) {
                $value = null;
            } else {
                $value = $binary
                    ? self::value($packet, $offset, $column)
                    : self::text(Codec::readString($packet, $offset), $column);
            }
            $row[$column['name']] = $value;
        }
        if ($offset !== strlen($packet)) {
            throw new \UnexpectedValueException('MySQL sent an invalid result row; reconnect before trying again.');
        }
        return $row;
    }

    /**
     * @param array{type: int, flags: int, decimals: int, length?: int} $column
     */
    public static function value(string $packet, int &$offset, array $column): mixed
    {
        $type = $column['type'];
        switch ($type) {
            case self::BIT:
                return self::bits(Codec::readString($packet, $offset));
            case self::TINY:
            case self::SHORT:
            case self::LONG:
            case self::LONGLONG:
            case self::INT24:
            case self::YEAR:
                $length = match ($type) {
                    self::TINY => 1,
                    self::SHORT, self::YEAR => 2,
                    self::LONGLONG => 8,
                    default => 4,
                };
                $unsigned = (bool) ($column['flags'] & self::UNSIGNED);
                $value = $length === 8 && $unsigned
                    ? Codec::unsigned64At($packet, $offset)
                    : Codec::integer($packet, $offset, $length);
                if ($length < 8 && !$unsigned && $value & (1 << (($length * 8) - 1))) {
                    $value -= 1 << ($length * 8);
                }
                if ($column['flags'] & self::ZEROFILL) {
                    return str_pad((string) $value, $column['length'], '0', STR_PAD_LEFT);
                }
                return $type === self::YEAR ? sprintf('%04d', $value) : $value;
            case self::FLOAT:
                $value = unpack('g', Codec::bytes($packet, $offset, 4))[1];
                return self::pdoFloat($value, $column['decimals']);
            case self::DOUBLE:
                return unpack('e', Codec::bytes($packet, $offset, 8))[1];
            case self::DATE:
            case self::DATETIME:
            case self::TIMESTAMP:
            case self::TIME:
                return self::date($packet, $offset, $type, $column['decimals']);
            case self::NULL:
                return null;
            case self::DECIMAL:
            case self::VARCHAR:
            case self::VECTOR:
            case self::JSON:
            case self::NEWDECIMAL:
            case self::ENUM:
            case self::SET:
            case self::TINY_BLOB:
            case self::MEDIUM_BLOB:
            case self::LONG_BLOB:
            case self::BLOB:
            case self::VAR_STRING:
            case self::STRING:
            case self::GEOMETRY:
                return Codec::readString($packet, $offset);
            default:
                throw new \UnexpectedValueException(
                    "MySQL returned unsupported binary type {$type}; use a supported column type.",
                );
        }
    }

    /**
     * @param list<mixed> $values
     */
    public static function execute(int $statement, array $values): string
    {
        $packet = chr(self::EXECUTE) . pack('V', $statement) . "\0" . pack('V', 1);
        if ($values === []) {
            return $packet;
        }
        $bitmap = str_repeat("\0", intdiv(count($values) + 7, 8));
        $types = $data = '';
        foreach ($values as $index => $value) {
            if ($value === null) {
                $bitmap[intdiv($index, 8)] = chr(ord($bitmap[intdiv($index, 8)]) | (1 << ($index % 8)));
                $types .= chr(self::NULL) . "\0";
            } elseif (is_int($value) || is_bool($value)) {
                $types .= chr(self::LONGLONG) . "\0";
                $data .= pack('P', (int) $value);
            } else {
                $value = match (true) {
                    is_string($value) => $value,
                    is_float($value) => (string) $value,
                    $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
                    $value instanceof \Stringable => (string) $value,
                    default => throw new \InvalidArgumentException(
                        'A ' . get_debug_type($value) . ' cannot be bound to a statement.',
                    ),
                };
                $types .= chr(self::VAR_STRING) . "\0";
                $data .= Codec::string($value);
            }
        }
        return $packet . $bitmap . "\x01" . $types . $data;
    }

    /**
     * Text BIT fields still contain big-endian bytes, not decimal text.
     *
     * @param array{type: int, flags: int} $column
     */
    private static function text(?string $value, array $column): mixed
    {
        if ($value === null) {
            return null;
        }
        $type = $column['type'];
        if ($type === self::BIT) {
            return self::bits($value);
        }
        if ($column['flags'] & self::ZEROFILL) {
            return $value;
        }
        if (in_array($type, [self::TINY, self::SHORT, self::LONG, self::LONGLONG, self::INT24], true)) {
            $integer = (int) $value;
            return (string) $integer === $value ? $integer : $value;
        }
        return in_array($type, [self::FLOAT, self::DOUBLE], true) ? (float) $value : $value;
    }

    private static function bits(?string $bytes): int|string
    {
        if ($bytes === null || strlen($bytes) > 8) {
            throw new \UnexpectedValueException('MySQL sent an invalid BIT value.');
        }
        return Codec::unsigned64(str_pad(strrev($bytes), 8, "\0"));
    }

    /**
     * mysqlnd rounds FLOAT through six significant digits or its declared scale.
     */
    private static function pdoFloat(float $value, int $decimals): float
    {
        if ($decimals >= 31) {
            $text = str_replace(',', '.', sprintf('%.6G', $value));
        } else {
            $text = sprintf('%.*F', $decimals, $value);
        }
        return (float) $text;
    }

    private static function date(string $packet, int &$offset, int $type, int $decimals): string
    {
        $length = Codec::integer($packet, $offset, 1);
        $bytes = Codec::bytes($packet, $offset, $length);
        $p = 0;
        if ($type === self::TIME) {
            if (!in_array($length, [0, self::TIME_LENGTH, self::TIME_FRACTIONS_LENGTH], true)) {
                throw new \UnexpectedValueException('MySQL sent an invalid time value.');
            }
            $negative = $length ? Codec::integer($bytes, $p, 1) : 0;
            $days = $length ? Codec::integer($bytes, $p, 4) : 0;
            $hour = $length ? Codec::integer($bytes, $p, 1) : 0;
            $minute = $length ? Codec::integer($bytes, $p, 1) : 0;
            $second = $length ? Codec::integer($bytes, $p, 1) : 0;
            $value = sprintf('%s%02d:%02d:%02d', $negative ? '-' : '', ($days * 24) + $hour, $minute, $second);
            $microseconds = $length === self::TIME_FRACTIONS_LENGTH ? Codec::integer($bytes, $p, 4) : 0;
        } else {
            if (!in_array(
                $length,
                [0, self::DATE_LENGTH, self::DATETIME_LENGTH, self::DATETIME_FRACTIONS_LENGTH],
                true,
            )) {
                throw new \UnexpectedValueException('MySQL sent an invalid date value.');
            }
            $year = $length ? Codec::integer($bytes, $p, 2) : 0;
            $month = $length ? Codec::integer($bytes, $p, 1) : 0;
            $day = $length ? Codec::integer($bytes, $p, 1) : 0;
            $value = sprintf('%04d-%02d-%02d', $year, $month, $day);
            if ($type === self::DATE) {
                return $value;
            }
            $hour = $length >= self::DATETIME_LENGTH ? Codec::integer($bytes, $p, 1) : 0;
            $minute = $length >= self::DATETIME_LENGTH ? Codec::integer($bytes, $p, 1) : 0;
            $second = $length >= self::DATETIME_LENGTH ? Codec::integer($bytes, $p, 1) : 0;
            $value .= sprintf(' %02d:%02d:%02d', $hour, $minute, $second);
            $microseconds = $length === self::DATETIME_FRACTIONS_LENGTH ? Codec::integer($bytes, $p, 4) : 0;
        }
        if ($decimals > 0 && $decimals <= self::MAX_FRACTIONS) {
            $value .= '.' . substr(sprintf('%06d', $microseconds), 0, $decimals);
        }
        return $value;
    }
}
