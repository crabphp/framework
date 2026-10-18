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
        [':authority',                  ''],
        [':method',                     'GET'],
        [':method',                     'POST'],
        [':path',                       '/'],
        [':path',                       '/index.html'],
        [':scheme',                     'http'],
        [':scheme',                     'https'],
        [':status',                     '200'],
        [':status',                     '204'],
        [':status',                     '206'],
        [':status',                     '304'],
        [':status',                     '400'],
        [':status',                     '404'],
        [':status',                     '500'],
        ['accept-charset',              ''],
        ['accept-encoding',             'gzip, deflate'],
        ['accept-language',             ''],
        ['accept-ranges',               ''],
        ['accept',                      ''],
        ['access-control-allow-origin', ''],
        ['age',                         ''],
        ['allow',                       ''],
        ['authorization',               ''],
        ['cache-control',               ''],
        ['content-disposition',         ''],
        ['content-encoding',            ''],
        ['content-language',            ''],
        ['content-length',              ''],
        ['content-location',            ''],
        ['content-range',               ''],
        ['content-type',                ''],
        ['cookie',                      ''],
        ['date',                        ''],
        ['etag',                        ''],
        ['expect',                      ''],
        ['expires',                     ''],
        ['from',                        ''],
        ['host',                        ''],
        ['if-match',                    ''],
        ['if-modified-since',           ''],
        ['if-none-match',               ''],
        ['if-range',                    ''],
        ['if-unmodified-since',         ''],
        ['last-modified',               ''],
        ['link',                        ''],
        ['location',                    ''],
        ['max-forwards',                ''],
        ['proxy-authenticate',          ''],
        ['proxy-authorization',         ''],
        ['range',                       ''],
        ['referer',                     ''],
        ['refresh',                     ''],
        ['retry-after',                 ''],
        ['server',                      ''],
        ['set-cookie',                  ''],
        ['strict-transport-security',   ''],
        ['transfer-encoding',           ''],
        ['user-agent',                  ''],
        ['vary',                        ''],
        ['via',                         ''],
        ['www-authenticate',            ''],
    ];

    /**
     * The Huffman code of each byte, and of the end of a text last: the code and how many bits it is.
     */
    public const HUFFMAN = [
        [0x1ff8, 13],
        [0x7f_ffd8, 23],
        [0xfff_ffe2, 28],
        [0xfff_ffe3, 28],
        [0xfff_ffe4, 28],
        [0xfff_ffe5, 28],
        [0xfff_ffe6, 28],
        [0xfff_ffe7, 28],
        [0xfff_ffe8, 28],
        [0xff_ffea, 24],
        [0x3fff_fffc, 30],
        [0xfff_ffe9, 28],
        [0xfff_ffea, 28],
        [0x3fff_fffd, 30],
        [0xfff_ffeb, 28],
        [0xfff_ffec, 28],
        [0xfff_ffed, 28],
        [0xfff_ffee, 28],
        [0xfff_ffef, 28],
        [0xfff_fff0, 28],
        [0xfff_fff1, 28],
        [0xfff_fff2, 28],
        [0x3fff_fffe, 30],
        [0xfff_fff3, 28],
        [0xfff_fff4, 28],
        [0xfff_fff5, 28],
        [0xfff_fff6, 28],
        [0xfff_fff7, 28],
        [0xfff_fff8, 28],
        [0xfff_fff9, 28],
        [0xfff_fffa, 28],
        [0xfff_fffb, 28],
        [0x14, 6],
        [0x3f8, 10],
        [0x3f9, 10],
        [0xffa, 12],
        [0x1ff9, 13],
        [0x15, 6],
        [0xf8, 8],
        [0x7fa, 11],
        [0x3fa, 10],
        [0x3fb, 10],
        [0xf9, 8],
        [0x7fb, 11],
        [0xfa, 8],
        [0x16, 6],
        [0x17, 6],
        [0x18, 6],
        [0x0, 5],
        [0x1, 5],
        [0x2, 5],
        [0x19, 6],
        [0x1a, 6],
        [0x1b, 6],
        [0x1c, 6],
        [0x1d, 6],
        [0x1e, 6],
        [0x1f, 6],
        [0x5c, 7],
        [0xfb, 8],
        [0x7ffc, 15],
        [0x20, 6],
        [0xffb, 12],
        [0x3fc, 10],
        [0x1ffa, 13],
        [0x21, 6],
        [0x5d, 7],
        [0x5e, 7],
        [0x5f, 7],
        [0x60, 7],
        [0x61, 7],
        [0x62, 7],
        [0x63, 7],
        [0x64, 7],
        [0x65, 7],
        [0x66, 7],
        [0x67, 7],
        [0x68, 7],
        [0x69, 7],
        [0x6a, 7],
        [0x6b, 7],
        [0x6c, 7],
        [0x6d, 7],
        [0x6e, 7],
        [0x6f, 7],
        [0x70, 7],
        [0x71, 7],
        [0x72, 7],
        [0xfc, 8],
        [0x73, 7],
        [0xfd, 8],
        [0x1ffb, 13],
        [0x7_fff0, 19],
        [0x1ffc, 13],
        [0x3ffc, 14],
        [0x22, 6],
        [0x7ffd, 15],
        [0x3, 5],
        [0x23, 6],
        [0x4, 5],
        [0x24, 6],
        [0x5, 5],
        [0x25, 6],
        [0x26, 6],
        [0x27, 6],
        [0x6, 5],
        [0x74, 7],
        [0x75, 7],
        [0x28, 6],
        [0x29, 6],
        [0x2a, 6],
        [0x7, 5],
        [0x2b, 6],
        [0x76, 7],
        [0x2c, 6],
        [0x8, 5],
        [0x9, 5],
        [0x2d, 6],
        [0x77, 7],
        [0x78, 7],
        [0x79, 7],
        [0x7a, 7],
        [0x7b, 7],
        [0x7ffe, 15],
        [0x7fc, 11],
        [0x3ffd, 14],
        [0x1ffd, 13],
        [0xfff_fffc, 28],
        [0xf_ffe6, 20],
        [0x3f_ffd2, 22],
        [0xf_ffe7, 20],
        [0xf_ffe8, 20],
        [0x3f_ffd3, 22],
        [0x3f_ffd4, 22],
        [0x3f_ffd5, 22],
        [0x7f_ffd9, 23],
        [0x3f_ffd6, 22],
        [0x7f_ffda, 23],
        [0x7f_ffdb, 23],
        [0x7f_ffdc, 23],
        [0x7f_ffdd, 23],
        [0x7f_ffde, 23],
        [0xff_ffeb, 24],
        [0x7f_ffdf, 23],
        [0xff_ffec, 24],
        [0xff_ffed, 24],
        [0x3f_ffd7, 22],
        [0x7f_ffe0, 23],
        [0xff_ffee, 24],
        [0x7f_ffe1, 23],
        [0x7f_ffe2, 23],
        [0x7f_ffe3, 23],
        [0x7f_ffe4, 23],
        [0x1f_ffdc, 21],
        [0x3f_ffd8, 22],
        [0x7f_ffe5, 23],
        [0x3f_ffd9, 22],
        [0x7f_ffe6, 23],
        [0x7f_ffe7, 23],
        [0xff_ffef, 24],
        [0x3f_ffda, 22],
        [0x1f_ffdd, 21],
        [0xf_ffe9, 20],
        [0x3f_ffdb, 22],
        [0x3f_ffdc, 22],
        [0x7f_ffe8, 23],
        [0x7f_ffe9, 23],
        [0x1f_ffde, 21],
        [0x7f_ffea, 23],
        [0x3f_ffdd, 22],
        [0x3f_ffde, 22],
        [0xff_fff0, 24],
        [0x1f_ffdf, 21],
        [0x3f_ffdf, 22],
        [0x7f_ffeb, 23],
        [0x7f_ffec, 23],
        [0x1f_ffe0, 21],
        [0x1f_ffe1, 21],
        [0x3f_ffe0, 22],
        [0x1f_ffe2, 21],
        [0x7f_ffed, 23],
        [0x3f_ffe1, 22],
        [0x7f_ffee, 23],
        [0x7f_ffef, 23],
        [0xf_ffea, 20],
        [0x3f_ffe2, 22],
        [0x3f_ffe3, 22],
        [0x3f_ffe4, 22],
        [0x7f_fff0, 23],
        [0x3f_ffe5, 22],
        [0x3f_ffe6, 22],
        [0x7f_fff1, 23],
        [0x3ff_ffe0, 26],
        [0x3ff_ffe1, 26],
        [0xf_ffeb, 20],
        [0x7_fff1, 19],
        [0x3f_ffe7, 22],
        [0x7f_fff2, 23],
        [0x3f_ffe8, 22],
        [0x1ff_ffec, 25],
        [0x3ff_ffe2, 26],
        [0x3ff_ffe3, 26],
        [0x3ff_ffe4, 26],
        [0x7ff_ffde, 27],
        [0x7ff_ffdf, 27],
        [0x3ff_ffe5, 26],
        [0xff_fff1, 24],
        [0x1ff_ffed, 25],
        [0x7_fff2, 19],
        [0x1f_ffe3, 21],
        [0x3ff_ffe6, 26],
        [0x7ff_ffe0, 27],
        [0x7ff_ffe1, 27],
        [0x3ff_ffe7, 26],
        [0x7ff_ffe2, 27],
        [0xff_fff2, 24],
        [0x1f_ffe4, 21],
        [0x1f_ffe5, 21],
        [0x3ff_ffe8, 26],
        [0x3ff_ffe9, 26],
        [0xfff_fffd, 28],
        [0x7ff_ffe3, 27],
        [0x7ff_ffe4, 27],
        [0x7ff_ffe5, 27],
        [0xf_ffec, 20],
        [0xff_fff3, 24],
        [0xf_ffed, 20],
        [0x1f_ffe6, 21],
        [0x3f_ffe9, 22],
        [0x1f_ffe7, 21],
        [0x1f_ffe8, 21],
        [0x7f_fff3, 23],
        [0x3f_ffea, 22],
        [0x3f_ffeb, 22],
        [0x1ff_ffee, 25],
        [0x1ff_ffef, 25],
        [0xff_fff4, 24],
        [0xff_fff5, 24],
        [0x3ff_ffea, 26],
        [0x7f_fff4, 23],
        [0x3ff_ffeb, 26],
        [0x7ff_ffe6, 27],
        [0x3ff_ffec, 26],
        [0x3ff_ffed, 26],
        [0x7ff_ffe7, 27],
        [0x7ff_ffe8, 27],
        [0x7ff_ffe9, 27],
        [0x7ff_ffea, 27],
        [0x7ff_ffeb, 27],
        [0xfff_fffe, 28],
        [0x7ff_ffec, 27],
        [0x7ff_ffed, 27],
        [0x7ff_ffee, 27],
        [0x7ff_ffef, 27],
        [0x7ff_fff0, 27],
        [0x3ff_ffee, 26],
        [0x3fff_ffff, 30],
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
    public function __construct(
        private readonly int $allowed = 4096,
    ) {
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
                if ($symbol >= 256) {
                    continue;
                }

                self::$codes[$bits][$code] = chr($symbol);
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
        if ($bits > 7 || $code !== ((1 << $bits) - 1)) {
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
        return (
            $this->table[$index - 62] ?? throw new \RuntimeException('a header refers to a field that is in no table')
        );
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
        if (($offset + $length) > strlen($block)) {
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
