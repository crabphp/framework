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

    public function testATraceNamesEachCallWithoutTheArgumentsItWasGiven(): void
    {
        $script = <<<'PHP'
            require $argv[1];
            function login(string $email, string $typed): never
            {
                try {
                    (static fn(string $word): never => throw new LogicException('inner'))('swordfish');
                } catch (LogicException $error) {
                    throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused', 0, $error);
                }
            }
            try {
                login('ann@example.com', 'hunter2');
            } catch (RuntimeException $error) {
            }
            $stream = fopen('php://memory', 'w+b');
            new Crab\Log\Logger($stream)->error('POST /login failed', ['exception' => $error]);
            rewind($stream);
            echo json_encode(['written' => stream_get_contents($stream), 'arguments' => $error->getTrace()[0]['args']], JSON_THROW_ON_ERROR);
            fclose($stream);
            PHP;
        [$status, $output, $errors] = self::capture($script);
        self::assertSame(0, $status, $errors);
        self::assertSame('', $errors);
        $record = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $written = $record['written'];

        // The fixture contains arguments regardless of PHP's trace-text truncation setting.
        self::assertSame(['ann@example.com', 'hunter2'], $record['arguments']);
        self::assertStringNotContainsString('hunter2', $written);
        self::assertStringNotContainsString('ann@example.com', $written);
        self::assertStringNotContainsString('swordfish', $written);
        self::assertStringContainsString("\nLogicException: inner in Command line code", $written);
        self::assertStringContainsString(
            "\n\nNext RuntimeException: SQLSTATE[HY000] [2002] Connection refused in Command line code",
            $written,
        );
        self::assertMatchesRegularExpression(
            '/\n#0 Command line code\(\d+\): [^\n]*\{closure[^\n]*\}\(\)\n/',
            $written,
        );
        self::assertStringContainsString('login()', $written);
        self::assertStringContainsString("Stack trace:\n#0 ", $written);
        self::assertMatchesRegularExpression('/\n#\d+ \{main\}\n/', $written);
    }

    public function testAServerStartupFailureNamesItsCallsWithoutTheirArguments(): void
    {
        $script = <<<'PHP'
            require $argv[1];
            function startWithCredential(string $credential): never
            {
                $server = new Crab\Server(new Crab\App());
                $server->generation('tcp://127.0.0.1:8080', 1);
            }
            startWithCredential('trace_marker');
            PHP;
        // A plain file makes inheritance fail before any listener or worker starts.
        [$status, $output, $errors] = self::capture($script, [3 => ['file', '/dev/null', 'r']]);

        self::assertSame(1, $status, $errors);
        self::assertSame('', $errors);
        self::assertStringContainsString(
            'The server failed: RuntimeException: Descriptor 3 is not a listening socket',
            $output,
        );
        self::assertStringContainsString('Crab\\Server->generation()', $output);
        self::assertStringContainsString('startWithCredential()', $output);
        self::assertStringNotContainsString('trace_marker', $output);
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
     * Captures a failure with PHP retaining its original call arguments.
     *
     * @param array<int, array<string>> $descriptors
     * @return array{int, string, string}
     */
    private static function capture(string $script, array $descriptors = []): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                '-d',
                'zend.exception_ignore_args=0',
                '-r',
                $script,
                dirname(__DIR__, 2) . '/vendor/autoload.php',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']] + $descriptors,
            $pipes,
        );
        self::assertIsResource($process);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $errors = '';
        $deadline = hrtime(true) + 5_000_000_000;
        try {
            while (true) {
                $state = proc_get_status($process);
                $output .= stream_get_contents($pipes[1]);
                $errors .= stream_get_contents($pipes[2]);
                if (!$state['running']) {
                    $status = $state['exitcode'];
                    break;
                }
                if (hrtime(true) >= $deadline) {
                    proc_terminate($process, 9);
                    self::fail('Timed out waiting for the logger/server failure probe to exit.');
                }
                usleep(1_000);
            }
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        return [$status, $output, $errors];
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
