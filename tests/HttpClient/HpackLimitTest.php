<?php

declare(strict_types=1);

namespace Crab\Tests\HttpClient;

use Crab\HttpClient\Hpack;
use PHPUnit\Framework\TestCase;

/**
 * A server may refer back to one remembered field with a single byte, so a
 * small header block can stand for an enormous one. The decoder bounds
 * what it decodes, not what it was sent.
 */
final class HpackLimitTest extends TestCase
{
    public function testABlockThatRepeatsOneRememberedFieldPastTheLimitIsRefused(): void
    {
        // One 4,000-byte field remembered, then 255 one-byte references to it: about 1 MB decoded from 4 KB.
        $block = self::remembered('x', str_repeat('v', 4000)) . str_repeat("\xBE", 255);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('headers are too long');
        new Hpack()->decode($block);
    }

    public function testHeadersUpToTheLimitAreDecoded(): void
    {
        // Fifteen references bring the list to 16 × 4,033 bytes, inside 64 KiB.
        $fields = new Hpack()->decode(self::remembered('x', str_repeat('v', 4000)) . str_repeat("\xBE", 15));

        self::assertCount(16, $fields);
        self::assertSame(['x', str_repeat('v', 4000)], $fields[15]);
    }

    /**
     * A literal field with a new name, to be remembered: 0x40, then the name and the value, each with its length.
     */
    private static function remembered(string $name, string $value): string
    {
        return "\x40" . self::text($name) . self::text($value);
    }

    private static function text(string $text): string
    {
        $length = strlen($text);
        if ($length < 127) {
            return chr($length) . $text;
        }
        $bytes = "\x7F";
        for ($rest = $length - 127; $rest >= 128; $rest >>= 7) {
            $bytes .= chr(($rest & 0x7F) | 0x80);
        }
        return $bytes . chr($rest) . $text;
    }
}
