<?php

declare(strict_types=1);

namespace Crab;

use Crab\Contracts\Console\Command as CommandContract;
use Crab\Contracts\Console\Console;

/**
 * Declares commands on the current application's console, for the file
 * that lists them:
 *
 *     Command::add('hello', static function (Input $input, Output $output): int { ... }, 'Say hello');
 *
 * It is $app->console under a shorter name and nothing more. Commands are
 * declared once, as the application is built.
 */
final class Command
{
    /**
     * @param CommandContract|\Closure|string $command A command, a closure taking the input and output, or the name of a command class.
     * @param string $description Shown in the command list; used for a closure.
     */
    public static function add(
        string $name,
        CommandContract|\Closure|string $command,
        string $description = '',
    ): Console {
        return App::current()->console->add($name, $command, $description);
    }
}
