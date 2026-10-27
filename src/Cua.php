<?php

declare(strict_types=1);

namespace Crab;

use Crab\Commands\Status;
use Crab\Commands\Stop;
use Crab\Console\Console;
use Crab\Console\Output;
use Crab\Contracts\Config\InvalidSettings;
use Crab\Contracts\Console\Output as OutputContract;
use Crab\Contracts\Container\MissingServices;

/**
 * What `php cua` runs: it loads the application app.php describes and
 * runs the command typed.
 *
 * When the application cannot be loaded, because of a syntax error or a
 * provider that throws, `status` and `stop` still run, from the files the
 * server keeps in storage/; see ServerFiles. A deploy of broken code
 * leaves the server in the background serving the code it started with,
 * and this keeps it in reach. Any other command says why the application
 * did not load.
 */
final class Cua
{
    /**
     * The commands that run without the application.
     */
    private const WITHOUT = ['status', 'stop'];

    /**
     * @param string $app The file that returns the application.
     * @param list<string> $arguments The words after the script: the command and what it is given.
     * @return int The exit status.
     */
    public static function run(string $app, array $arguments, ?OutputContract $output = null): int
    {
        try {
            $application = require $app;
        } catch (\Throwable $error) {
            return self::unloaded(dirname($app), $error, $arguments, $output);
        }
        if (!$application instanceof App) {
            ($output ?? new Output())->error(
                "{$app} returned "
                . get_debug_type($application)
                . '; it must return the '
                . App::class
                . ' it describes.',
            );
            return 1;
        }
        return $application->console->run($arguments, $output);
    }

    /**
     * Runs a command of an application that could not be loaded: `status`
     * or `stop`, on the files at their default places in its folder unless
     * --pid names another, or for anything else, why it did not load.
     *
     * @param list<string> $arguments
     */
    public static function unloaded(
        string $directory,
        \Throwable $error,
        array $arguments,
        ?OutputContract $output = null,
    ): int {
        $output ??= new Output();
        $why =
            $error instanceof InvalidSettings || $error instanceof MissingServices
                ? $error->getMessage()
                : $error::class
                . ': '
                . $error->getMessage()
                . ' ('
                . $error->getFile()
                . ':'
                . $error->getLine()
                . ')';
        $name = $arguments[0] ?? 'list';
        $asked = $name === 'help' ? $arguments[1] ?? '' : $name;
        if (!in_array($asked, self::WITHOUT, true)) {
            $output->error("The application cannot be loaded: {$why}");
            $output->error(
                'A server running in the background is not affected: it goes on serving the code it started with. Without the application, `status` and `stop` still run.',
            );
            return 1;
        }
        $files = ServerFiles::in($directory);
        $console = new Console();
        $console->add('status', new Status($files));
        $console->add('stop', new Stop($files));
        if ($name !== 'help') {
            $output->error("The application cannot be loaded, so this is from the files in storage/ alone: {$why}");
        }
        return $console->run($arguments, $output);
    }
}
