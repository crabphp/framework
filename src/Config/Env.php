<?php

declare(strict_types=1);

namespace Crab\Config;

/**
 * Environment variables, with values from a .env file for those the real
 * environment does not set.
 */
final class Env
{
    /**
     * @var array<string, string>
     */
    private static array $values = [];

    /**
     * Reads KEY=value lines. Values may be quoted; double quotes understand
     * \n, \" and \\. Returns false when the file does not exist.
     */
    public static function load(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException("Cannot read {$path}.");
        }
        foreach ($lines as $number => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $match) !== 1) {
                throw new \InvalidArgumentException("{$path} line " . ($number + 1) . ' is not KEY=value.');
            }
            self::$values[$match[1]] = self::value($match[2]);
        }
        return true;
    }

    /**
     * The real environment wins over the file. The words true, false and
     * null become those values; anything else stays a string.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        if ($value === false) {
            if (!isset(self::$values[$key])) {
                return $default;
            }
            $value = self::$values[$key];
        }
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $value,
        };
    }

    private static function value(string $raw): string
    {
        if ($raw !== '' && $raw[0] === '"' && preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $raw, $match) === 1) {
            return strtr($match[1], ['\\n' => "\n", '\\"' => '"', '\\\\' => '\\']);
        }
        if ($raw !== '' && $raw[0] === "'" && preg_match("/^'([^']*)'/", $raw, $match) === 1) {
            return $match[1];
        }
        // Unquoted: a comment starts at a # that follows whitespace.
        return rtrim(preg_replace('/\s+#.*$/', '', $raw));
    }
}
