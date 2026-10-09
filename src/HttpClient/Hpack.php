<?php

declare(strict_types=1);

namespace Crab\HttpClient;

/**
 * HPACK, the compression of HTTP/2's headers (RFC 7541): reads the header
 * blocks a server sends, keeping the table of fields it refers back to,
 * and writes the blocks a request is sent with.
 *
 * What is written uses neither that table nor the Huffman code, which a
 * sender may always leave out; what is read may use both.
 *
 * @internal
 */
final class Hpack
{
    /**
     * The fields every connection starts out knowing, numbered from 1.
     */
    public const STATIC = [
        [':authority', ''],
        [':method', 'GET'],
        [':method', 'POST'],
        [':path', '/'],
        [':path', '/index.html'],
        [':scheme', 'http'],
        [':scheme', 'https'],
        [':status', '200'],
        [':status', '204'],
        [':status', '206'],
        [':status', '304'],
        [':status', '400'],
        [':status', '404'],
        [':status', '500'],
        ['accept-charset', ''],
        ['accept-encoding', 'gzip, deflate'],
        ['accept-language', ''],
        ['accept-ranges', ''],
        ['accept', ''],
        ['access-control-allow-origin', ''],
        ['age', ''],
        ['allow', ''],
        ['authorization', ''],
        ['cache-control', ''],
        ['content-disposition', ''],
        ['content-encoding', ''],
        ['content-language', ''],
        ['content-length', ''],
        ['content-location', ''],
        ['content-range', ''],
        ['content-type', ''],
        ['cookie', ''],
        ['date', ''],
        ['etag', ''],
        ['expect', ''],
        ['expires', ''],
        ['from', ''],
        ['host', ''],
        ['if-match', ''],
        ['if-modified-since', ''],
        ['if-none-match', ''],
        ['if-range', ''],
        ['if-unmodified-since', ''],
        ['last-modified', ''],
        ['link', ''],
        ['location', ''],
        ['max-forwards', ''],
        ['proxy-authenticate', ''],
        ['proxy-authorization', ''],
        ['range', ''],
        ['referer', ''],
        ['refresh', ''],
        ['retry-after', ''],
        ['server', ''],
        ['set-cookie', ''],
        ['strict-transport-security', ''],
        ['transfer-encoding', ''],
        ['user-agent', ''],
        ['vary', ''],
        ['via', ''],
        ['www-authenticate', ''],
    ];

    /**
     * The Huffman code of each byte, and of the end of a text last: the code and how many bits it is.
     */
    public const HUFFMAN = [
        [0x1ff8, 13], [0x7fffd8, 23], [0xfffffe2, 28], [0xfffffe3, 28], [0xfffffe4, 28], [0xfffffe5, 28],
        [0xfffffe6, 28], [0xfffffe7, 28], [0xfffffe8, 28], [0xffffea, 24], [0x3ffffffc, 30], [0xfffffe9, 28],
        [0xfffffea, 28], [0x3ffffffd, 30], [0xfffffeb, 28], [0xfffffec, 28], [0xfffffed, 28], [0xfffffee, 28],
        [0xfffffef, 28], [0xffffff0, 28], [0xffffff1, 28], [0xffffff2, 28], [0x3ffffffe, 30], [0xffffff3, 28],
        [0xffffff4, 28], [0xffffff5, 28], [0xffffff6, 28], [0xffffff7, 28], [0xffffff8, 28], [0xffffff9, 28],
        [0xffffffa, 28], [0xffffffb, 28], [0x14, 6], [0x3f8, 10], [0x3f9, 10], [0xffa, 12],
        [0x1ff9, 13], [0x15, 6], [0xf8, 8], [0x7fa, 11], [0x3fa, 10], [0x3fb, 10],
        [0xf9, 8], [0x7fb, 11], [0xfa, 8], [0x16, 6], [0x17, 6], [0x18, 6],
        [0x0, 5], [0x1, 5], [0x2, 5], [0x19, 6], [0x1a, 6], [0x1b, 6],
        [0x1c, 6], [0x1d, 6], [0x1e, 6], [0x1f, 6], [0x5c, 7], [0xfb, 8],
        [0x7ffc, 15], [0x20, 6], [0xffb, 12], [0x3fc, 10], [0x1ffa, 13], [0x21, 6],
        [0x5d, 7], [0x5e, 7], [0x5f, 7], [0x60, 7], [0x61, 7], [0x62, 7],
        [0x63, 7], [0x64, 7], [0x65, 7], [0x66, 7], [0x67, 7], [0x68, 7],
        [0x69, 7], [0x6a, 7], [0x6b, 7], [0x6c, 7], [0x6d, 7], [0x6e, 7],
        [0x6f, 7], [0x70, 7], [0x71, 7], [0x72, 7], [0xfc, 8], [0x73, 7],
        [0xfd, 8], [0x1ffb, 13], [0x7fff0, 19], [0x1ffc, 13], [0x3ffc, 14], [0x22, 6],
        [0x7ffd, 15], [0x3, 5], [0x23, 6], [0x4, 5], [0x24, 6], [0x5, 5],
        [0x25, 6], [0x26, 6], [0x27, 6], [0x6, 5], [0x74, 7], [0x75, 7],
        [0x28, 6], [0x29, 6], [0x2a, 6], [0x7, 5], [0x2b, 6], [0x76, 7],
        [0x2c, 6], [0x8, 5], [0x9, 5], [0x2d, 6], [0x77, 7], [0x78, 7],
        [0x79, 7], [0x7a, 7], [0x7b, 7], [0x7ffe, 15], [0x7fc, 11], [0x3ffd, 14],
        [0x1ffd, 13], [0xffffffc, 28], [0xfffe6, 20], [0x3fffd2, 22], [0xfffe7, 20], [0xfffe8, 20],
        [0x3fffd3, 22], [0x3fffd4, 22], [0x3fffd5, 22], [0x7fffd9, 23], [0x3fffd6, 22], [0x7fffda, 23],
        [0x7fffdb, 23], [0x7fffdc, 23], [0x7fffdd, 23], [0x7fffde, 23], [0xffffeb, 24], [0x7fffdf, 23],
        [0xffffec, 24], [0xffffed, 24], [0x3fffd7, 22], [0x7fffe0, 23], [0xffffee, 24], [0x7fffe1, 23],
        [0x7fffe2, 23], [0x7fffe3, 23], [0x7fffe4, 23], [0x1fffdc, 21], [0x3fffd8, 22], [0x7fffe5, 23],
        [0x3fffd9, 22], [0x7fffe6, 23], [0x7fffe7, 23], [0xffffef, 24], [0x3fffda, 22], [0x1fffdd, 21],
        [0xfffe9, 20], [0x3fffdb, 22], [0x3fffdc, 22], [0x7fffe8, 23], [0x7fffe9, 23], [0x1fffde, 21],
        [0x7fffea, 23], [0x3fffdd, 22], [0x3fffde, 22], [0xfffff0, 24], [0x1fffdf, 21], [0x3fffdf, 22],
        [0x7fffeb, 23], [0x7fffec, 23], [0x1fffe0, 21], [0x1fffe1, 21], [0x3fffe0, 22], [0x1fffe2, 21],
        [0x7fffed, 23], [0x3fffe1, 22], [0x7fffee, 23], [0x7fffef, 23], [0xfffea, 20], [0x3fffe2, 22],
        [0x3fffe3, 22], [0x3fffe4, 22], [0x7ffff0, 23], [0x3fffe5, 22], [0x3fffe6, 22], [0x7ffff1, 23],
        [0x3ffffe0, 26], [0x3ffffe1, 26], [0xfffeb, 20], [0x7fff1, 19], [0x3fffe7, 22], [0x7ffff2, 23],
        [0x3fffe8, 22], [0x1ffffec, 25], [0x3ffffe2, 26], [0x3ffffe3, 26], [0x3ffffe4, 26], [0x7ffffde, 27],
        [0x7ffffdf, 27], [0x3ffffe5, 26], [0xfffff1, 24], [0x1ffffed, 25], [0x7fff2, 19], [0x1fffe3, 21],
        [0x3ffffe6, 26], [0x7ffffe0, 27], [0x7ffffe1, 27], [0x3ffffe7, 26], [0x7ffffe2, 27], [0xfffff2, 24],
        [0x1fffe4, 21], [0x1fffe5, 21], [0x3ffffe8, 26], [0x3ffffe9, 26], [0xffffffd, 28], [0x7ffffe3, 27],
        [0x7ffffe4, 27], [0x7ffffe5, 27], [0xfffec, 20], [0xfffff3, 24], [0xfffed, 20], [0x1fffe6, 21],
        [0x3fffe9, 22], [0x1fffe7, 21], [0x1fffe8, 21], [0x7ffff3, 23], [0x3fffea, 22], [0x3fffeb, 22],
        [0x1ffffee, 25], [0x1ffffef, 25], [0xfffff4, 24], [0xfffff5, 24], [0x3ffffea, 26], [0x7ffff4, 23],
        [0x3ffffeb, 26], [0x7ffffe6, 27], [0x3ffffec, 26], [0x3ffffed, 26], [0x7ffffe7, 27], [0x7ffffe8, 27],
        [0x7ffffe9, 27], [0x7ffffea, 27], [0x7ffffeb, 27], [0xffffffe, 28], [0x7ffffec, 27], [0x7ffffed, 27],
        [0x7ffffee, 27], [0x7ffffef, 27], [0x7fffff0, 27], [0x3ffffee, 26], [0x3fffffff, 30],
    ];

    /**
     * Fields whose value is never to be put in a table by anything on the way.
     */
    private const SECRET = ['authorization' => true, 'cookie' => true, 'proxy-authorization' => true];

    /**
     * The Huffman code by length and code, to the byte each stands for.
     *
     * @var array<int, array<int, string>>|null
     */
    private static ?array $codes = null;
    /**
     * The static table's names to the first number each has.
     *
     * @var array<string, int>|null
     */
    private static ?array $names = null;

    /**
     * The fields the server has asked to be remembered, the newest first.
     *
     * @var list<array{string, string}>
     */
    private array $table = [];
    private int $size = 0;
    private int $limit;

    /**
     * @param int $limit How large the table may be, as this side told the server; 4096 unless it said otherwise.
     */
    public function __construct(private readonly int $allowed = 4096)
    {
        $this->limit = $allowed;
    }

    /**
     * Reads one whole header block. Every block a connection receives
     * must be read, and in order, wanted or not: each may change the
     * table the next refers to.
     *
     * @return list<array{string, string}> The fields as name and value, in the order sent.
     * @throws \RuntimeException When the block is not valid, after which the connection cannot go on.
     */
    public function decode(string $block): array
    {
        $fields = [];
        $offset = 0;
        $length = strlen($block);
        while ($offset < $length) {
            $first = ord($block[$offset]);
            if (($first & 0x80) !== 0) {
                // A field that is in a table whole.
                $fields[] = $this->entry(self::integer($block, $offset, 7));
                continue;
            }
            if (($first & 0xE0) === 0x20) {
                // The server makes the table smaller, or as large again as it may be.
                $limit = self::integer($block, $offset, 5);
                if ($limit > $this->allowed) {
                    throw new \RuntimeException('the header table was made larger than allowed');
                }
                $this->limit = $limit;
                $this->evict();
                continue;
            }
            $remember = ($first & 0x40) !== 0;
            $index = self::integer($block, $offset, $remember ? 6 : 4);
            $name = $index === 0 ? self::text($block, $offset) : $this->entry($index)[0];
            $value = self::text($block, $offset);
            $fields[] = [$name, $value];
            if ($remember) {
                array_unshift($this->table, [$name, $value]);
                $this->size += strlen($name) + strlen($value) + 32;
                $this->evict();
            }
        }
        return $fields;
    }

    /**
     * Writes a header block. Nothing is kept between one and the next.
     *
     * @param list<array{string, string}> $fields Names in lowercase.
     */
    public static function encode(array $fields): string
    {
        if (self::$names === null) {
            self::$names = [];
            foreach (self::STATIC as $index => [$name]) {
                self::$names[$name] ??= $index + 1;
            }
        }
        $block = '';
        foreach ($fields as [$name, $value]) {
            $whole = array_search([$name, $value], self::STATIC, true);
            if ($whole !== false && $value !== '') {
                $block .= chr(0x80 | ($whole + 1));
                continue;
            }
            $flags = isset(self::SECRET[$name]) ? 0x10 : 0x00;
            if (isset(self::$names[$name])) {
                $block .= self::number(self::$names[$name], 4, $flags);
            } else {
                $block .= chr($flags) . self::number(strlen($name), 7, 0) . $name;
            }
            $block .= self::number(strlen($value), 7, 0) . $value;
        }
        return $block;
    }

    /**
     * Reads text written with the Huffman code.
     */
    public static function huffman(string $bytes): string
    {
        if (self::$codes === null) {
            self::$codes = [];
            foreach (self::HUFFMAN as $symbol => [$code, $bits]) {
                if ($symbol < 256) {
                    self::$codes[$bits][$code] = chr($symbol);
                }
            }
        }
        $codes = self::$codes;
        $text = '';
        $code = 0;
        $bits = 0;
        for ($i = 0, $length = strlen($bytes); $i < $length; ++$i) {
            $byte = ord($bytes[$i]);
            for ($bit = 7; $bit >= 0; --$bit) {
                $code = ($code << 1) | (($byte >> $bit) & 1);
                ++$bits;
                if (isset($codes[$bits][$code])) {
                    $text .= $codes[$bits][$code];
                    $code = 0;
                    $bits = 0;
                } elseif ($bits > 30) {
                    throw new \RuntimeException('a header is not valid Huffman code');
                }
            }
        }
        // What is left over fills the last byte, with ones.
        if ($bits > 7 || $code !== (1 << $bits) - 1) {
            throw new \RuntimeException('a header is not valid Huffman code');
        }
        return $text;
    }

    /**
     * @return array{string, string}
     */
    private function entry(int $index): array
    {
        if ($index >= 1 && $index <= 61) {
            return self::STATIC[$index - 1];
        }
        return $this->table[$index - 62] ?? throw new \RuntimeException('a header refers to a field that is in no table');
    }

    private function evict(): void
    {
        while ($this->size > $this->limit && $this->table !== []) {
            [$name, $value] = array_pop($this->table);
            $this->size -= strlen($name) + strlen($value) + 32;
        }
    }

    /**
     * Reads a number that begins in the low bits of a byte and goes on in the bytes after when it does not fit.
     */
    private static function integer(string $block, int &$offset, int $prefix): int
    {
        $max = (1 << $prefix) - 1;
        $value = ord($block[$offset++]) & $max;
        if ($value < $max) {
            return $value;
        }
        $shift = 0;
        do {
            if (!isset($block[$offset]) || $shift > 28) {
                throw new \RuntimeException('a header block is cut short');
            }
            $byte = ord($block[$offset++]);
            $value += ($byte & 0x7F) << $shift;
            $shift += 7;
        } while (($byte & 0x80) !== 0);
        return $value;
    }

    private static function text(string $block, int &$offset): string
    {
        if (!isset($block[$offset])) {
            throw new \RuntimeException('a header block is cut short');
        }
        $coded = (ord($block[$offset]) & 0x80) !== 0;
        $length = self::integer($block, $offset, 7);
        if ($offset + $length > strlen($block)) {
            throw new \RuntimeException('a header block is cut short');
        }
        $bytes = substr($block, $offset, $length);
        $offset += $length;
        return $coded ? self::huffman($bytes) : $bytes;
    }

    /**
     * Writes a number into the low bits of a byte, and into the bytes after when it does not fit.
     */
    private static function number(int $value, int $prefix, int $flags): string
    {
        $max = (1 << $prefix) - 1;
        if ($value < $max) {
            return chr($flags | $value);
        }
        $bytes = chr($flags | $max);
        $value -= $max;
        while ($value >= 128) {
            $bytes .= chr(($value & 0x7F) | 0x80);
            $value >>= 7;
        }
        return $bytes . chr($value);
    }
}
