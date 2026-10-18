<?php

declare(strict_types=1);

namespace Crab\Database\Mysql;

use Crab\Engine\Async\Cancellation;

/**
 * Exchanges commands and drains result sets, including every result of CALL.
 * Only the requested first result is retained; later results are discarded.
 *
 * @internal
 */
final class Protocol
{
    public const IN_TRANSACTION = 1;
    public const MORE_RESULTS = 8;
    public const NO_BACKSLASH_ESCAPES = 512;

    private int $sequence = 0;
    public int $status = 0;
    public int $serverVersion = 999_999;
    private readonly \Closure $read;

    public function __construct(
        private readonly Stream $stream,
        private readonly ?Cancellation $cancellation = null,
    ) {
        $this->read = fn(int $length): string => $this->stream->read($length, cancellation: $this->cancellation);
    }

    public function command(string $payload, bool &$sent): void
    {
        $this->sequence = 0;
        foreach (Packets::encode($payload, $this->sequence) as $packet) {
            $this->stream->write($packet, $sent, cancellation: $this->cancellation);
        }
    }

    public function receive(): string
    {
        $packet = Packets::read($this->read, $this->sequence);
        if (($packet[0] ?? '') === "\xff") {
            throw Codec::error($packet);
        }
        return $packet;
    }

    /**
     * Parameter and column definitions have their own EOF terminators.
     *
     * @return list<array{name: string, type: int, flags: int, decimals: int, length: int}>
     */
    public function columns(int $count, ?RowDecoder $decoder = null): array
    {
        $columns = [];
        $packets = [];
        for ($i = 0; $i < $count; ++$i) {
            $packet = $this->receive();
            if ($decoder === null) {
                $columns[] = Binary::column($packet);
            } else {
                $packets[] = $packet;
            }
        }
        if ($count > 0) {
            $this->eof($this->receive());
        }
        return $decoder === null ? $columns : $decoder->definitions($packets);
    }

    /**
     * Select retains all first-result rows; first retains one; execute/insert
     * drain without retaining rows. Result rows determine affected count too.
     * All modes consume every following result before another command.
     *
     * @return array{rows: list<array<string, mixed>>, affected: int, id: int|string}
     */
    public function result(bool $binary, string $mode, ?RowDecoder $decoder = null): array
    {
        $first = null;
        do {
            $packet = $this->receive();
            $rows = [];
            $affected = 0;
            $id = 0;
            if (($packet[0] ?? '') === "\0") {
                $ok = Codec::ok($packet);
                $affected = $ok['affected'];
                $id = $ok['id'];
                $this->status = $ok['status'];
            } elseif (($packet[0] ?? '') === "\xfb") {
                // Refusing without closing would leave the server waiting for a file.
                throw new \UnexpectedValueException(
                    'The async MySQL driver refuses LOCAL INFILE; load data through bound statements instead.',
                );
            } else {
                $offset = 0;
                $count = Codec::readLength($packet, $offset);
                if (!is_int($count) || $count < 1) {
                    throw new \UnexpectedValueException('MySQL sent an invalid result header.');
                }
                $rowDecoder = $first === null && $decoder !== null ? $decoder : new RowDecoder();
                $this->columns($count, $rowDecoder);
                while (true) {
                    $packet = $this->receive();
                    if (($packet[0] ?? '') === "\xfe" && strlen($packet) < 9) {
                        $this->eof($packet);
                        break;
                    }
                    ++$affected;
                    $row = $rowDecoder->row($packet, $binary);
                    if ($first === null && ($mode === 'select' || $mode === 'first' && $rows === [])) {
                        $rows[] = $row;
                    }
                }
            }
            $first ??= ['rows' => $rows, 'affected' => $affected, 'id' => $id];
        } while ($this->status & self::MORE_RESULTS);
        return $first;
    }

    private function eof(string $packet): void
    {
        if (($packet[0] ?? '') !== "\xfe" || strlen($packet) !== 5) {
            throw new \UnexpectedValueException('MySQL sent an invalid result terminator.');
        }
        $offset = 3;
        $this->status = Codec::integer($packet, $offset, 2);
    }
}
