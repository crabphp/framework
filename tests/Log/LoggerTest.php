<?php

declare(strict_types=1);

namespace Crab\Tests\Log;

use Crab\Log\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class LoggerTest extends TestCase
{
    public function testWritesOneLinePerRecord(): void
    {
        $stream = fopen('php://memory', 'w+b');
        $logger = new Logger($stream);
        self::assertInstanceOf(LoggerInterface::class, $logger);
        $logger->info('User {id} signed in from {ip}', [
            'id' => 7,
            'ip' => '127.0.0.1',
            'agent' => 'curl/8',
            'tags' => ['a'],
        ]);
        $logger->debug('plain');
        $logger->notice('{method} {target}: {message}', [
            'method' => 'GET',
            'target' => '/x{message}',
            'message' => 'boom',
        ]);
        $logger->warning('{missing} stays', ['other' => "caf\xC3\xA9 /path"]);

        $lines = explode("\n", rtrim(self::contents($stream)));
        self::assertCount(4, $lines);
        self::assertStringEndsWith(' NOTICE GET /x{message}: boom', $lines[2]);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}[+-]\d\d:\d\d INFO User 7 signed in from 127\.0\.0\.1 \{"agent":"curl\/8","tags":\["a"\]\}$/',
            $lines[0],
        );
        self::assertStringEndsWith(' DEBUG plain', $lines[1]);
        self::assertStringEndsWith(' WARNING {missing} stays {"other":"café /path"}', $lines[3]);
    }

    public function testExceptionFollowsWithItsTrace(): void
    {
        $stream = fopen('php://memory', 'w+b');
        new Logger($stream)->error('Failed', ['exception' => new \RuntimeException('boom'), 'job' => 3]);
        $written = self::contents($stream);
        self::assertStringContainsString(" ERROR Failed {\"job\":3}\nRuntimeException: boom in " . __FILE__, $written);
        self::assertStringContainsString('#0 ', $written);
    }

    public function testMinimumLevel(): void
    {
        $stream = fopen('php://memory', 'w+b');
        $logger = new Logger($stream, LogLevel::WARNING);
        $logger->info('quiet');
        $logger->notice('quiet');
        $logger->warning('loud');
        $logger->emergency('louder');
        self::assertSame(2, substr_count(self::contents($stream), "\n"));
        self::assertStringNotContainsString('quiet', self::contents($stream));
    }

    public function testFileIsOpenedOnFirstRecordAndAppended(): void
    {
        $path = sys_get_temp_dir() . '/crab-log-' . bin2hex(random_bytes(6)) . '.log';
        try {
            $logger = new Logger($path);
            self::assertFileDoesNotExist($path);
            $logger->info('one');
            new Logger($path)->info('two');
            self::assertSame(2, substr_count(file_get_contents($path), "\n"));
        } finally {
            @unlink($path);
        }
    }

    public function testUnknownLevelsAreRejected(): void
    {
        try {
            new Logger(level: 'loud');
            self::fail('An unknown minimum level was accepted.');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(InvalidArgumentException::class);
        new Logger(fopen('php://memory', 'w+b'))->log('loud', 'message');
    }

    /**
     * @param resource $stream
     */
    private static function contents(mixed $stream): string
    {
        rewind($stream);
        return stream_get_contents($stream);
    }
}
