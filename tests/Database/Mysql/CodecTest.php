<?php

declare(strict_types=1);

namespace Crab\Tests\Database\Mysql;

use Crab\Database\Mysql\Binary;
use Crab\Database\Mysql\Codec;
use Crab\Database\Mysql\Packets;
use Crab\Database\Mysql\Parameters;
use PHPUnit\Framework\TestCase;

/**
 * Wire boundaries are exercised without a database so malformed messages
 * and platform integer limits remain covered in every test environment.
 */
final class CodecTest extends TestCase
{
    public function testLengthEncodedIntegersRoundTripAtEveryBoundary(): void
    {
        foreach ([0, 250, 251, 65_535, 65_536, 16_777_215, 16_777_216, PHP_INT_MAX] as $value) {
            $offset = 0;
            $packet = Codec::length($value);
            self::assertSame($value, Codec::readLength($packet, $offset));
            self::assertSame(strlen($packet), $offset);
        }
        $offset = 0;
        self::assertNull(Codec::readLength("\xfb", $offset));
        self::assertSame('18446744073709551615', Codec::unsigned64(str_repeat("\xff", 8)));
        self::assertSame('9223372036854775808', Codec::unsigned64("\0\0\0\0\0\0\0\x80"));
    }

    public function testPacketsSplitAndJoinAcrossTheMaximumSizeAndSequenceWrap(): void
    {
        foreach ([0, 5, Packets::LIMIT, Packets::LIMIT + 100] as $size) {
            $value = str_repeat('a', $size);
            $sequence = 255;
            $bytes = implode('', iterator_to_array(Packets::encode($value, $sequence)));
            $offset = 0;
            $next = 255;
            $read = static function (int $length) use ($bytes, &$offset): string {
                return Codec::bytes($bytes, $offset, $length);
            };
            self::assertSame($value, Packets::read($read, $next));
            self::assertSame($sequence, $next);
            self::assertSame(strlen($bytes), $offset);
        }
    }

    public function testOutOfOrderPacketsAndTruncatedValuesAreRefused(): void
    {
        try {
            $offset = 0;
            Codec::readString("\x04ab", $offset);
            self::fail('A truncated value was accepted.');
        } catch (\UnexpectedValueException) {
        }
        $sequence = 1;
        $this->expectException(\UnexpectedValueException::class);
        Packets::read(static fn(): string => "\0\0\0\x02", $sequence);
    }

    public function testBinaryValuesKeepTheTypesAndSignednessPdoUses(): void
    {
        $cases = [
            [1, 0, "\xff", -1],
            [1, 32, "\xff", 255],
            [2, 0, pack('v', 65_535), -1],
            [3, 0, pack('V', 4_294_967_295), -1],
            [9, 32, pack('V', 16_777_215), 16_777_215],
            [8, 0, pack('P', PHP_INT_MIN), PHP_INT_MIN],
            [8, 32, str_repeat("\xff", 8), '18446744073709551615'],
            [13, 32, pack('v', 2026), '2026'],
            [4, 0, pack('g', 1.5), 1.5],
            [5, 0, pack('e', 2.5), 2.5],
            [16, 32, Codec::string("\x01\xff"), 511],
            [6, 0, '', null],
        ];
        foreach ([0, 15, 245, 246, 247, 248, 249, 250, 251, 252, 253, 254, 255] as $type) {
            $cases[] = [$type, 0, Codec::string("a\0b"), "a\0b"];
        }
        foreach ($cases as [$type, $flags, $packet, $expected]) {
            $offset = 0;
            self::assertSame($expected, Binary::value($packet, $offset, [
                'type' => $type,
                'flags' => $flags,
                'decimals' => 31,
            ]));
            self::assertSame(strlen($packet), $offset);
        }
    }

    public function testBinaryDatesTimesAndNullBitmapsUseTheirDistinctLayouts(): void
    {
        foreach ([
            [10, 0, "\x04" . pack('vCC', 2026, 10, 11), '2026-10-11'],
            [12, 0, "\x07" . pack('vCCCCC', 2026, 10, 11, 9, 8, 7), '2026-10-11 09:08:07'],
            [7, 3, "\x0b" . pack('vCCCCCV', 2026, 10, 11, 9, 8, 7, 123_000), '2026-10-11 09:08:07.123'],
            [11, 6, "\x0c" . pack('CVCCCV', 1, 2, 3, 4, 5, 600), '-51:04:05.000600'],
            [12, 0, "\0", '0000-00-00 00:00:00'],
            [11, 0, "\0", '00:00:00'],
        ] as [$type, $decimals, $packet, $expected]) {
            $offset = 0;
            self::assertSame($expected, Binary::value($packet, $offset, [
                'type' => $type,
                'flags' => 0,
                'decimals' => $decimals,
            ]));
        }
        $columns = [];
        for ($i = 0; $i < 9; ++$i) {
            $columns[] = ['name' => 'c' . $i, 'type' => 1, 'flags' => 32, 'decimals' => 0];
        }
        self::assertSame(
            [0, 1, null, 3, 4, 5, 6, 7, null],
            array_values(Binary::row("\0\x10\x04\0\x01\x03\x04\x05\x06\x07", $columns, true)),
        );
        $execute = Binary::execute(5, [
            null,
            true,
            -10,
            1.25,
            new \DateTimeImmutable('2026-10-11'),
            new class implements \Stringable {
                public function __toString(): string
                {
                    return 'value';
                }
            },
        ]);
        self::assertSame("\x01\x01", substr($execute, 10, 2));
        self::assertStringContainsString(Codec::string('1.25'), $execute);
        self::assertStringContainsString(Codec::string('2026-10-11 00:00:00'), $execute);
        self::assertStringContainsString(Codec::string('value'), $execute);
    }

    public function testNamedValuesNeverReplaceQuestionMarksInsideLiteralsOrComments(): void
    {
        $sql = "select :a, '?', `:b`, :second /* ? :c */ -- :d\n# :e\n";
        [$prepared, $keys] = Parameters::parse($sql);
        self::assertSame("select ?, '?', `:b`, ? /* ? :c */ -- :d\n# :e\n", $prepared);
        self::assertSame(['a', 'second'], $keys);
        self::assertSame([null, 'secret'], Parameters::values($keys, [':a' => null, 'second' => 'secret'], $sql));
    }

    public function testErrorsPreserveTheSqlStateAndServerNumber(): void
    {
        $error = Codec::error("\xff" . pack('v', 1045) . '#28000Access denied');
        self::assertSame(1045, $error->getCode());
        self::assertSame(['28000', 1045, 'Access denied'], $error->errorInfo);
    }
}
