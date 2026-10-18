<?php

declare(strict_types=1);

namespace Crab\Database\Mysql;

use Crab\Database\DatabaseException;

/**
 * Keeps at most 256 server-side statements, closing an evicted server handle.
 * Unsupported prepares fall back to text commands only without bound values.
 *
 * @internal
 */
final class Statements
{
    /**
     * Entries belong to this protocol session only. A null id remembers an
     * unbound-only text fallback; null keys use the server's positional count.
     *
     * @var array<string, array{id: ?int, keys: ?list<int|string>, takes: int, rows: ?RowDecoder}>
     */
    private array $kept = [];

    public function __construct(
        private readonly Protocol $protocol,
    ) {}

    /**
     * @param array<int|string, mixed> $bindings
     * @return array{rows: list<array<string, mixed>>, affected: int, id: int|string}
     */
    public function run(string $sql, array $bindings, string $mode, bool &$sent): array
    {
        $statement = $this->kept[$sql] ?? $this->prepare($sql, $bindings);
        if ($statement['takes'] !== count($bindings)) {
            throw new DatabaseException(
                'The statement takes ' . $statement['takes'] . ' values and was given ' . count($bindings),
                $sql,
            );
        }
        if ($statement['keys'] === null && array_is_list($bindings)) {
            $values = $bindings;
        } else {
            $keys = $statement['keys'] ?? ($statement['takes'] === 0 ? [] : range(0, $statement['takes'] - 1));
            $values = Parameters::values($keys, $bindings, $sql);
        }
        $payload = $statement['id'] === null ? "\x03" . $sql : Binary::execute($statement['id'], $values);
        $this->protocol->command($payload, $sent);
        try {
            return $this->protocol->result($statement['id'] !== null, $mode, $statement['rows']);
        } catch (ServerError $error) {
            // PDO drops a cached handle on execution errors. Never replay an
            // uncertain write here; the next application call prepares again.
            unset($this->kept[$sql]);
            if ($statement['id'] !== null) {
                $closed = false;
                $this->protocol->command("\x19" . pack('V', $statement['id']), $closed);
            }
            throw $error;
        }
    }

    /**
     * Reads all prepare metadata before caching a session-owned handle.
     * A refused prepare becomes a null-id entry only when no values are bound.
     *
     * @return array{id: ?int, keys: ?list<int|string>, takes: int, rows: ?RowDecoder}
     */
    private function prepare(string $sql, array $bindings): array
    {
        $prepared = $sql;
        $keys = null;
        if (str_contains($sql, ':')) {
            $noBackslashEscapes = (bool) ($this->protocol->status & Protocol::NO_BACKSLASH_ESCAPES);
            [$prepared, $parsed] = Parameters::parse($sql, $noBackslashEscapes, $this->protocol->serverVersion);
            foreach ($parsed as $key) {
                if (!is_string($key)) {
                    continue;
                }

                $keys = $parsed;
                break;
            }
        }
        $sent = false;
        $this->protocol->command("\x16" . $prepared, $sent);
        try {
            $packet = $this->protocol->receive();
        } catch (ServerError $error) {
            if ($error->getCode() !== 1295 || $bindings !== [] || $keys !== null) {
                throw $error;
            }
            return $this->keep($sql, ['id' => null, 'keys' => null, 'takes' => 0, 'rows' => null]);
        }
        if (($packet[0] ?? '') !== "\0") {
            throw new \UnexpectedValueException('MySQL sent an invalid prepared statement header.');
        }
        $offset = 1;
        $id = Codec::integer($packet, $offset, 4);
        $columns = Codec::integer($packet, $offset, 2);
        $takes = Codec::integer($packet, $offset, 2);
        $this->protocol->columns($takes);
        $rows = new RowDecoder();
        $this->protocol->columns($columns, $rows);
        return $this->keep($sql, ['id' => $id, 'keys' => $keys, 'takes' => $takes, 'rows' => $rows]);
    }

    /**
     * @param array{id: ?int, keys: ?list<int|string>, takes: int, rows: ?RowDecoder} $statement
     * @return array{id: ?int, keys: ?list<int|string>, takes: int, rows: ?RowDecoder}
     */
    private function keep(string $sql, array $statement): array
    {
        if (count($this->kept) >= 256) {
            $oldest = array_shift($this->kept);
            if ($oldest['id'] !== null) {
                $sent = false;
                $this->protocol->command("\x19" . pack('V', $oldest['id']), $sent);
            }
        }
        return $this->kept[$sql] = $statement;
    }
}
