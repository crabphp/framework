<?php

declare(strict_types=1);

namespace Crab\Database\Mysql;

/**
 * Reads the existing PDO MySQL DSN and the async driver's SSL stream options.
 * Charset selection goes in the handshake, without changing session variables.
 *
 * @internal
 */
final readonly class Settings
{
    public string $host;
    public int $port;
    public string $database;
    public ?string $socket;
    public int $charset;
    public bool $encrypted;
    /**
     * PHP stream SSL context options; certificate and peer-name verification
     * default to true before explicit user overrides are applied.
     *
     * @var array<string, mixed>
     */
    public array $ssl;

    /**
     * @param array<int|string, mixed> $options Integer PDO attributes are ignored.
     */
    public function __construct(string $dsn, array $options = [])
    {
        if (!str_starts_with($dsn, 'mysql:')) {
            throw new \InvalidArgumentException('The async MySQL driver needs a mysql: DSN.');
        }
        $parts = [];
        foreach (explode(';', substr($dsn, 6)) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }
        $this->host = $parts['host'] ?? 'localhost';
        $port = $parts['port'] ?? '3306';
        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new \InvalidArgumentException('Set the MySQL DSN port to an integer between 1 and 65535.');
        }
        $this->port = (int) $port;
        $this->database = $parts['dbname'] ?? '';
        $this->socket = ($parts['unix_socket'] ?? '') !== '' ? $parts['unix_socket'] : null;
        $charset = strtolower($parts['charset'] ?? 'utf8mb4');
        // mysqlnd uses modern utf8mb4_0900_ai_ci without an explicit charset,
        // but selects utf8mb4's primary general_ci collation when DSN requests it.
        $this->charset = match ($charset) {
            'utf8mb4' => isset($parts['charset']) ? 45 : 255,
            'utf8', 'utf8mb3' => 33,
            'latin1' => 8,
            'ascii' => 11,
            'binary' => 63,
            'latin2' => 9,
            'cp1251' => 51,
            'gbk' => 28,
            'sjis' => 13,
            'big5' => 1,
            'dec8' => 3,
            'cp850' => 4,
            'hp8' => 6,
            'koi8r' => 7,
            'swe7' => 10,
            'ujis' => 12,
            'hebrew' => 16,
            'tis620' => 18,
            'euckr' => 19,
            'koi8u' => 22,
            'gb2312' => 24,
            'greek' => 25,
            'cp1250' => 26,
            'latin5' => 30,
            'armscii8' => 32,
            'ucs2' => 35,
            'cp866' => 36,
            'keybcs2' => 37,
            'macce' => 38,
            'macroman' => 39,
            'cp852' => 40,
            'latin7' => 41,
            'utf16' => 54,
            'utf16le' => 56,
            'cp1256' => 57,
            'cp1257' => 59,
            'utf32' => 60,
            'geostd8' => 92,
            'cp932' => 95,
            'eucjpms' => 97,
            'gb18030' => 248,
            default => throw new \InvalidArgumentException("MySQL charset {$charset} is unknown; use a MySQL character set name such as utf8mb4."),
        };
        $ssl = $options['ssl'] ?? false;
        if (!is_bool($ssl) && !is_array($ssl)) {
            throw new \InvalidArgumentException('Set options["ssl"] on the database connection to true, false or PHP SSL stream options.');
        }
        $this->encrypted = $ssl !== false;
        $this->ssl = is_array($ssl) ? $ssl : [];
    }
}
