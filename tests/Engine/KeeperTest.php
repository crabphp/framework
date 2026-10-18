<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\Tests\Support\TestCase;

/**
 * A keeper and its generations as real processes; see Fixtures/keeper.php.
 */
final class KeeperTest extends TestCase
{
    private string $directory;
    private string $address;
    /**
     * @var resource|null
     */
    private mixed $keeper = null;

    protected function setUp(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('A keeper needs pcntl and posix.');
        }
        $this->directory = realpath(sys_get_temp_dir()) . '/crab-keeper-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->address = 'tcp://' . stream_socket_get_name($probe, false);
        fclose($probe);
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory)) {
            return;
        }
        if (is_resource($this->keeper)) {
            proc_terminate($this->keeper, SIGTERM);
            for ($waited = 0; $waited < 100 && proc_get_status($this->keeper)['running']; ++$waited) {
                usleep(50_000);
            }
            proc_terminate($this->keeper, SIGKILL);
            proc_close($this->keeper);
        }
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testAKeeperHoldsTheSocketWhileGenerationsComeAndGo(): void
    {
        $pidFile = $this->directory . '/service.pid';
        $log = $this->directory . '/service.log';
        $this->keeper = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/Fixtures/keeper.php',
                'keep',
                dirname(__DIR__, 2) . '/vendor/autoload.php',
                $pidFile,
                $log,
                $this->address,
                'one',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->directory,
        );
        self::assertSame(
            "ready\n",
            fgets($pipes[1]),
            'The keeper says it is ready only once a generation accepts connections.',
        );
        $keeper = (int) file_get_contents($pidFile);
        self::assertSame(proc_get_status($this->keeper)['pid'], $keeper);

        [$text, $first] = $this->get();
        self::assertSame('one', $text);
        self::assertNotSame($keeper, $first, 'The keeper does not serve; a process under it does.');

        self::assertSame(['ok' => true], array_intersect_key($this->replace('two'), ['ok' => 0]));
        [$text, $second] = $this->get();
        self::assertSame('two', $text);
        self::assertNotSame($first, $second);
        self::assertSame($keeper, (int) file_get_contents($pidFile), 'The service keeps its process id.');

        $failed = $this->replace('three', 'fail');
        self::assertSame(
            ['ok' => false, 'message' => 'this generation was told to fail'],
            array_intersect_key($failed, ['ok' => 0, 'message' => 0]),
        );
        self::assertSame(
            ['two', $second],
            $this->get(),
            'A generation that cannot start leaves the one that serves alone.',
        );

        // A generation that dies is started again; connections made meanwhile wait and are served.
        posix_kill($second, SIGKILL);
        $third = 1;
        for ($attempt = 0; $attempt < 100 && in_array($third, [1, $second], true); ++$attempt) {
            // Its workers, now without a parent, answer a little longer before they notice and stop.
            [$text, $third] = $this->get(patient: true);
            self::assertSame('two', $text, 'A new one is started as the last one that worked was.');
            usleep(50_000);
        }
        self::assertNotContains($third, [1, $second]);

        // SIGUSR1 reaches the generation, which replaces its workers.
        posix_kill($keeper, SIGUSR1);
        usleep(600_000);
        self::assertSame(['two', $third], $this->get());

        posix_kill($keeper, SIGTERM);
        for ($waited = 0; $waited < 200 && proc_get_status($this->keeper)['running']; ++$waited) {
            usleep(50_000);
        }
        $status = proc_get_status($this->keeper);
        self::assertSame([false, 0], [$status['running'], $status['exitcode']]);
        proc_close($this->keeper);
        $this->keeper = null;
        self::assertFalse(posix_kill($third, 0), 'Stopping the keeper stops what it kept.');
        self::assertFileDoesNotExist($pidFile);
        self::assertFalse(@stream_socket_client($this->address, $errno, $error, 1));

        $logged = (string) file_get_contents($log);
        foreach ([
            "[keeper {$keeper}] Serving on {$this->address}, generation pid {$first}.",
            "generation pid {$second}, taking over from {$first}.",
            "A new generation did not start, and pid {$second} goes on serving: this generation was told to fail",
            "Generation pid {$second} ended by itself with status",
            "Serving again, generation pid {$third}.",
            "[keeper {$keeper}] Stopped.",
        ] as $line) {
            self::assertStringContainsString($line, $logged);
        }
    }

    public function testAKeeperThatCannotStartSaysWhy(): void
    {
        $taken = stream_socket_server($this->address);
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $run = function (string $text, string $address) use ($autoload): array {
            $process = proc_open(
                [
                    PHP_BINARY,
                    __DIR__ . '/Fixtures/keeper.php',
                    'keep',
                    $autoload,
                    $this->directory . '/service.pid',
                    $this->directory . '/service.log',
                    $address,
                    $text,
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $this->directory,
            );
            $output = stream_get_contents($pipes[1]);
            return [proc_close($process), $output];
        };
        [$status, $output] = $run('one', $this->address);
        self::assertSame(1, $status);
        self::assertStringContainsString('failed: Could not bind', $output);
        self::assertFileDoesNotExist($this->directory . '/service.pid');
        fclose($taken);
    }

    /**
     * @return array<string, mixed> The keeper's answer.
     */
    private function replace(string $text, string ...$more): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/Fixtures/keeper.php',
                'replace',
                dirname(__DIR__, 2) . '/vendor/autoload.php',
                $this->directory . '/service.pid',
                $this->address,
                $text,
                ...$more,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->directory,
        );
        $output = stream_get_contents($pipes[1]);
        proc_close($process);
        return (array) json_decode(trim((string) $output), true);
    }

    /**
     * @param bool $patient Whether to allow for a generation that is being started.
     * @return array{string, int} The text served, and the process id of the generation that served it.
     */
    private function get(bool $patient = false): array
    {
        $connection = stream_socket_client($this->address, $errno, $error, 5);
        stream_set_timeout($connection, $patient ? 10 : 5);
        fwrite($connection, "GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        $response = (string) stream_get_contents($connection);
        fclose($connection);
        $body = substr($response, strrpos($response, "\r\n\r\n") + 4);
        [$text, $generation] = explode(' from ', $body) + [1 => '0'];
        return [$text, (int) $generation];
    }
}
