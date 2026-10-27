<?php

declare(strict_types=1);

namespace Crab\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\ServerFiles;

/**
 * Stops the server in the background. It needs only the files the server
 * keeps, and runs when the application cannot be loaded; see Cua.
 */
final readonly class Stop implements Command
{
    public function __construct(
        private ServerFiles $files,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Stop the server running in the background

            Options:
              --timeout=15             Graceful wait in seconds; if omitted, read from the running server
              --force                  Cut requests and end every server process now
              --pid=storage/crab.pid   The server whose process id file this is; default "server.pid"

            It runs when the application cannot be loaded, as after a deploy of broken
            code, from the files the server keeps in storage/.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $seconds = self::timeout($input);
        try {
            $pid = self::files($this->files, $input)->stop($seconds, $input->flag('force'));
        } catch (\RuntimeException $error) {
            $output->error($error->getMessage());
            return 1;
        }
        $output->line($pid === null ? 'No server is running in the background.' : "Stopped the server, pid {$pid}.");
        return 0;
    }

    /**
     * The files of the server --pid names, or those given.
     */
    public static function files(ServerFiles $files, Input $input): ServerFiles
    {
        $pid = $input->option('pid');
        if ($pid === null) {
            return $files;
        }
        if (!is_string($pid) || $pid === '') {
            throw new \InvalidArgumentException(
                '--pid needs the path of a process id file; use --pid=storage/crab.pid.',
            );
        }
        return $files->withPid($pid);
    }

    /**
     * Read the explicit graceful wait before either stop or restart contacts the server.
     */
    public static function timeout(Input $input): ?float
    {
        $timeout = $input->option('timeout');
        if ($timeout === true) {
            throw new \InvalidArgumentException('--timeout needs a value; use --timeout=value.');
        }
        $seconds = is_numeric($timeout) ? (float) $timeout : null;
        if ($timeout !== null && ($seconds === null || !is_finite($seconds) || $seconds < 0)) {
            throw new \InvalidArgumentException(
                '--timeout must be a finite number of seconds at least zero; use --timeout=15.',
            );
        }
        return $seconds;
    }
}
