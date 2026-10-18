<?php

declare(strict_types=1);

namespace Crab\Database\Mysql;

/**
 * A prepared handle's row layout, checked against every execution's metadata.
 * Only definitions are kept, never row values; changed server metadata rebuilds
 * the layout before any row is decoded.
 *
 * @internal
 */
final class RowDecoder
{
    /**
     * @var list<string>
     */
    private array $metadata = [];
    /**
     * @var list<array{name: string, type: int, flags: int, decimals: int, length: int}>
     */
    private array $columns = [];
    /**
     * @var list<array{kind: string, width: int, format: string, adjustment: int, unsigned: bool}>
     */
    private array $layout = [];
    private int $bitmapLength = 0;
    private string $emptyBitmap = '';

    /**
     * The server repeats definitions even for a cached prepared handle.
     * Equality is checked before reusing its layout; DDL may change any field.
     *
     * @param list<string> $packets
     * @return list<array{name: string, type: int, flags: int, decimals: int, length: int}>
     */
    public function definitions(array $packets): array
    {
        if ($packets !== $this->metadata) {
            $columns = [];
            $layout = [];
            foreach ($packets as $packet) {
                $column = Binary::column($packet);
                $columns[] = $column;
                $layout[] = Binary::layout($column);
            }
            $this->metadata = $packets;
            $this->columns = $columns;
            $this->layout = $layout;
            $this->bitmapLength = intdiv(count($columns) + 9, 8);
            $this->emptyBitmap = str_repeat("\0", $this->bitmapLength);
        }
        return $this->columns;
    }

    /**
     * Common integer/string fields avoid per-cell metadata classification.
     * Exceptional types retain Binary's PDO conversions and bounds checks.
     *
     * @return array<string, mixed>
     */
    public function row(string $packet, bool $binary): array
    {
        if (!$binary) {
            return Binary::row($packet, $this->columns, false);
        }
        $size = strlen($packet);
        $offset = 1 + $this->bitmapLength;
        if ($size < $offset) {
            throw new \UnexpectedValueException('MySQL sent a truncated result row.');
        }
        $bitmap = substr($packet, 1, $this->bitmapLength);
        $nullable = $bitmap !== $this->emptyBitmap;
        $row = [];
        foreach ($this->columns as $index => $column) {
            if ($nullable && ord($bitmap[intdiv($index + 2, 8)]) & (1 << (($index + 2) % 8))) {
                $row[$column['name']] = null;
                continue;
            }
            $field = $this->layout[$index];
            if ($field['kind'] === 'integer') {
                $end = $offset + $field['width'];
                if ($end > $size) {
                    throw new \UnexpectedValueException('MySQL sent a truncated integer field.');
                }
                $value = unpack($field['format'], $packet, $offset)[1];
                if ($field['width'] === 8 && $field['unsigned'] && $value < 0) {
                    $value = Codec::unsigned64At($packet, $offset);
                } else {
                    $offset = $end;
                    if ($field['adjustment'] !== 0 && $value & ($field['adjustment'] >> 1)) {
                        $value -= $field['adjustment'];
                    }
                }
            } elseif ($field['kind'] === 'string') {
                $value = Codec::readString($packet, $offset);
            } else {
                $value = Binary::value($packet, $offset, $column);
            }
            $row[$column['name']] = $value;
        }
        if ($offset !== $size) {
            throw new \UnexpectedValueException('MySQL sent an invalid result row; reconnect before trying again.');
        }
        return $row;
    }
}
