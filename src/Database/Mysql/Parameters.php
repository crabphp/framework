<?php

declare(strict_types=1);

namespace Crab\Database\Mysql;

use Crab\Database\DatabaseException;

/**
 * Replaces named placeholders with positional ones without touching SQL literals.
 * Values are carried separately and are never rendered into the statement.
 *
 * @internal
 */
final class Parameters
{
    /**
     * Rewrites named parameters in SQL and active MySQL version comments.
     * Literals, ordinary comments and optimizer hints are not parameter sites.
     * The server status supplies NO_BACKSLASH_ESCAPES; version gates use its
     * handshake version. Positional arity remains the server's prepare count.
     * Nothing here renders a bound value into SQL.
     *
     * @return array{string, list<int|string>}
     */
    public static function parse(string $sql, bool $noBackslashEscapes = false, int $serverVersion = 999999): array
    {
        $result = '';
        $keys = [];
        $length = strlen($sql);
        for ($i = 0; $i < $length; ++$i) {
            $char = $sql[$i];
            if (in_array($char, ["'", '"', '`'], true)) {
                $quote = $char;
                $result .= $char;
                while (++$i < $length) {
                    $result .= $sql[$i];
                    if ($quote !== '`' && !$noBackslashEscapes && $sql[$i] === '\\' && $i + 1 < $length) {
                        $result .= $sql[++$i];
                    } elseif ($sql[$i] === $quote) {
                        if (($sql[$i + 1] ?? '') === $quote) {
                            $result .= $sql[++$i];
                        } else {
                            break;
                        }
                    }
                }
                continue;
            }
            if ($char === '#' || ($char === '-' && ($sql[$i + 1] ?? '') === '-' && ctype_space($sql[$i + 2] ?? ' '))) {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end;
                $result .= substr($sql, $i, $end - $i);
                $i = $end - 1;
                continue;
            }
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                if (($sql[$i + 2] ?? '') === '!') {
                    $prefix = '/*!';
                    $version = 0;
                    if (preg_match('/\G\/\*!([0-9]{6}(?=\s)|[0-9]{5})/', $sql, $match, offset: $i) === 1) {
                        $prefix = $match[0];
                        $version = (int) $match[1];
                    }
                    if ($version <= $serverVersion) {
                        $result .= $prefix;
                        $i += strlen($prefix) - 1;
                        continue;
                    }
                }
                $result .= substr($sql, $i, $end - $i);
                $i = $end - 1;
                continue;
            }
            if ($char === '?') {
                $keys[] = count($keys);
            } elseif ($char === ':' && preg_match('/\G:([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $match, offset: $i) === 1) {
                $keys[] = $match[1];
                $result .= '?';
                $i += strlen($match[0]) - 1;
                continue;
            }
            $result .= $char;
        }
        return [$result, $keys];
    }

    /**
     * @param list<int|string> $keys
     * @param array<int|string, mixed> $bindings
     * @return list<mixed>
     */
    public static function values(array $keys, array $bindings, string $sql): array
    {
        if (count($keys) !== count($bindings)) {
            throw new DatabaseException('The statement takes ' . count($keys) . ' values and was given ' . count($bindings), $sql);
        }
        $values = [];
        foreach ($keys as $key) {
            $key = array_key_exists($key, $bindings) ? $key : (is_string($key) ? ':' . $key : $key);
            if (!array_key_exists($key, $bindings)) {
                throw new DatabaseException('The statement was not given all its named or positional values.', $sql);
            }
            $values[] = $bindings[$key];
        }
        return $values;
    }
}
