<?php

declare(strict_types=1);

namespace Crab\Engine\Process;

/**
 * Sends a process into the background and lets whoever started it wait
 * to hear how that went.
 *
 * start() returns twice. In the process that called it, the launcher, it
 * returns an object to await() on: the launcher stays in the foreground
 * until the background process says it is ready or says why it is not.
 * In the background process it returns an object to say so with: ready()
 * or fail(). The background process has left the terminal behind: it is
 * no longer in its session, and everything it prints goes to a file.
 *
 *     $daemon = Daemon::start('/var/log/app.log');
 *     if (!$daemon->background) {
 *         [$started, $message] = $daemon->await(10.0);
 *         exit($started ? 0 : 1);
 *     }
 *     // ... start serving, then:
 *     $daemon->ready('listening');
 */
final class Daemon
{
    /**
     * The background process's standard streams, kept so that they stay open.
     *
     * @var list<resource>
     */
    private static array $streams = [];

    /**
     * @param resource|null $channel What the two processes talk over.
     * @param bool $background Whether this is the background process.
     */
    private function __construct(
        private mixed $channel,
        public readonly bool $background,
    ) {}

    /**
     * @param string $output The file the background process's output is appended to.
     */
    public static function start(string $output): self
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            throw new \RuntimeException('Running in the background needs PHP\'s pcntl and posix extensions.');
        }
        $directory = dirname($output);
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(
                "The directory for the background process's output, {$directory}, cannot be created.",
            );
        }
        if (@file_put_contents($output, '', FILE_APPEND) === false) {
            throw new \RuntimeException("The background process's output file, {$output}, cannot be written.");
        }
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new \RuntimeException('Could not create the stream to hear from the background process.');
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Could not fork into the background.');
        }
        if ($pid > 0) {
            fclose($pair[1]);
            // The first child only starts the second and exits at once.
            pcntl_waitpid($pid, $status);
            return new self($pair[0], false);
        }
        fclose($pair[0]);
        // A session of its own, so that closing the terminal does not end it;
        // and a second fork, so that it can never take a terminal back.
        if (posix_setsid() === -1 || ($pid = pcntl_fork()) === -1) {
            fwrite($pair[1], json_encode([false, 'Could not detach from the terminal.']) . "\n");
            exit(1);
        }
        if ($pid > 0) {
            exit(0);
        }
        self::redirect($output);
        return new self($pair[1], true);
    }

    /**
     * In the launcher: waits for the background process to report.
     *
     * @return array{bool, string} Whether it started, and what it said.
     */
    public function await(float $timeout): array
    {
        $deadline = microtime(true) + $timeout;
        $buffer = '';
        while (is_resource($this->channel)) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return [false, "It did not report within {$timeout} seconds."];
            }
            $read = [$this->channel];
            $write = $except = [];
            if (
                @stream_select($read, $write, $except, (int) $remaining, (int) (($remaining - (int) $remaining) * 1e6))
                < 1
            ) {
                continue;
            }
            $bytes = fread($this->channel, 8192);
            if ($bytes === false || $bytes === '') {
                if (feof($this->channel)) {
                    break;
                }
                continue;
            }
            $buffer .= $bytes;
            $end = strpos($buffer, "\n");
            if ($end !== false) {
                $report = json_decode(substr($buffer, 0, $end), true);
                $this->close();
                return (
                    is_array($report)
                        ? [(bool) $report[0], (string) $report[1]]
                        : [false, 'It sent a report that could not be read.']
                );
            }
        }
        $this->close();
        return [false, 'It ended before reporting.'];
    }

    /**
     * In the background process: tells the launcher it started.
     */
    public function ready(string $message = ''): void
    {
        $this->report(true, $message);
    }

    /**
     * In the background process: tells the launcher why it could not start.
     */
    public function fail(string $message): void
    {
        $this->report(false, $message);
    }

    private function report(bool $started, string $message): void
    {
        if (is_resource($this->channel)) {
            @fwrite($this->channel, json_encode([$started, $message], JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        }
        $this->close();
    }

    private function close(): void
    {
        if (is_resource($this->channel)) {
            fclose($this->channel);
        }
        $this->channel = null;
    }

    /**
     * Points standard input at nothing and standard output and error at
     * the file. Closing the three and opening three more works because a
     * newly opened file takes the lowest free number, which those are.
     */
    private static function redirect(string $output): void
    {
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        self::$streams = [fopen('/dev/null', 'rb'), fopen($output, 'ab'), fopen($output, 'ab')];
    }
}
