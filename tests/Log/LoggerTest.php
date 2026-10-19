<?php

declare(strict_types=1);

namespace Crab\Tests\Log;

use Crab\App;
use Crab\Config\Config;
use Crab\Contracts\Clock\Clock;
use Crab\Log\Logger;
use Crab\Testing\FrozenClock;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class LoggerTest extends TestCase
{
    public function testEachRecordUsesTheClockGivenToTheLogger(): void
    {
        $stream = fopen('php://memory', 'w+b');
        $clock = new FrozenClock('2001-02-03T04:05:06.123456+07:00');
        $logger = new Logger($stream, clock: $clock);

        $logger->info('first');
        $clock->advance('+2 seconds');
        $logger->warning('second');

        self::assertSame(
            "2001-02-03T04:05:06.123+07:00 INFO first\n2001-02-03T04:05:08.123+07:00 WARNING second\n",
            self::contents($stream),
        );
        fclose($stream);
    }

    public function testTheApplicationPassesItsClockToTheLogger(): void
    {
        $path = sys_get_temp_dir() . '/crab-clock-log-' . bin2hex(random_bytes(6)) . '.log';
        try {
            $app = new App(new Config(['log' => ['path' => $path]]));
            $clock = new FrozenClock('2001-02-03T04:05:06.123456+07:00');
            $app->container->instance(Clock::class, $clock);

            $app->container->get(LoggerInterface::class)->info('application clock');

            self::assertSame("2001-02-03T04:05:06.123+07:00 INFO application clock\n", file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function testTheDrainFallbackLoggerUsesTheApplicationClock(): void
    {
        // Capture the fallback's stderr without starting a listening server.
        $code = <<<'PHP'
            require $argv[1];
            $app = new Crab\App();
            $app->container->instance(Crab\Contracts\Clock\Clock::class, new Crab\Testing\FrozenClock('2001-02-03T04:05:06Z'));
            $app->container->instance(Crab\Contracts\Log\Logger::class, new class extends Psr\Log\AbstractLogger implements Crab\Contracts\Log\Logger {
                public function log(mixed $level, string|Stringable $message, array $context = []): void
                {
                    throw new RuntimeException('log unavailable');
                }
            });
            Crab\Engine\FailureReporter::handleWith(static fn(): bool => true);
            $runtime = $app->runtime();
            $group = new ReflectionProperty($runtime, 'groups')->getValue($runtime)['http'];
            $worker = new Crab\Engine\Process\Worker(Crab\Engine\EventLoop::current(), $group, 0, ['tcp' => [], 'udp' => []], 5.0);
            // Exercise the registered stop callback with one request cut at the drain deadline.
            new ReflectionProperty($worker, 'cutConnections')->setValue($worker, 1);
            foreach ($group->stopCallbacks() as $stop) {
                $stop($worker);
            }
            PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $code, dirname(__DIR__, 2) . '/vendor/autoload.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        self::assertSame(0, $status, $errors);
        self::assertSame('', $output);
        self::assertMatchesRegularExpression(
            '/^2001-02-03T04:05:06\.000\+00:00 WARNING HTTP worker \d+ reached server\.drain; cut 1 connections\.\n$/',
            $errors,
        );
    }

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
