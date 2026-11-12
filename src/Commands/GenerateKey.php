<?php

declare(strict_types=1);

namespace Crab\Commands;

use Crab\App;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

/**
 * Writes a new application key, APP_KEY, into the application's .env file,
 * which config/app.php reads as app.key.
 */
final readonly class GenerateKey implements Command
{
    public function __construct(
        private App $app,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Write a new application key, APP_KEY, into .env

            The key is 64 random hexadecimal characters, read as app.key by config/app.php. A key that is
            already set is kept unless --force is given: replacing it ends every cookie session.

            Options:
              --show    Print a new key and write nothing
              --force   Replace a key that is already set
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $key = bin2hex(random_bytes(32));
        if ($input->flag('show')) {
            $output->line($key);
            return 0;
        }
        if ($this->app->basePath === null) {
            $output->error(
                'The application has no folder to keep a .env file in. Build it with App::configure($basePath), or set APP_KEY yourself; php cua key:generate --show prints one.',
            );
            return 1;
        }
        $file = $this->app->path('.env');
        $text = is_file($file) ? @file_get_contents($file) : '';
        if ($text === false) {
            $output->error("{$file} cannot be read. Check its permissions.");
            return 1;
        }
        $line = '/^[ \t]*APP_KEY[ \t]*=[ \t]*(.*?)[ \t]*$/m';
        if (preg_match($line, $text, $match) === 1 && trim($match[1], '"\'') !== '' && !$input->flag('force')) {
            $output->error(
                "{$file} already sets APP_KEY. Keep it, or replace it with php cua key:generate --force, which ends every cookie session.",
            );
            return 1;
        }
        $written = preg_match($line, $text) === 1
            ? (string) preg_replace($line, 'APP_KEY=' . $key, $text, 1)
            : $text . ($text === '' || str_ends_with($text, "\n") ? '' : "\n") . 'APP_KEY=' . $key . "\n";
        // Written beside it and moved into place, so .env is never seen half written.
        $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $mode = is_file($file) ? fileperms($file) & 0o777 : 0o600;
        if (
            @file_put_contents($temporary, $written) !== strlen($written)
            || !@chmod($temporary, $mode)
            || !@rename($temporary, $file)
        ) {
            @unlink($temporary);
            $output->error("Could not write {$file}. Check the folder's permissions.");
            return 1;
        }
        if (@file_get_contents($file) !== $written) {
            $output->error(
                "{$file} does not hold the new key after writing it. Check the file and run the command again.",
            );
            return 1;
        }
        $output->line("Wrote a new APP_KEY into {$file}. A running server takes it when it restarts.");
        return 0;
    }
}
