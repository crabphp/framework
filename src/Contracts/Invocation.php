<?php

declare(strict_types=1);

namespace Crab\Contracts;

/**
 * How this process was started, for a plugin whose declarations depend on
 * it: the command typed at the console, and whether the developer's
 * launcher, `dev`, started it. It is fixed before the application is
 * described and is the same for the whole process; it is never read from
 * a request or from app.debug.
 *
 * An application made in a test, or by anything other than `php cua`, has
 * no command and is not in development.
 */
final readonly class Invocation
{
    /**
     * @param string|null $command The command being run, "start" or "route:list"; null outside the console.
     * @param list<string> $arguments What the command was given, options included.
     * @param bool $development Whether this is `dev` itself or a server `dev` started.
     */
    public function __construct(
        public ?string $command = null,
        public array $arguments = [],
        public bool $development = false,
    ) {}

    /**
     * The value of an option given as --name=value, or null without one.
     */
    public function option(string $name): ?string
    {
        foreach ($this->arguments as $argument) {
            if (str_starts_with($argument, "--{$name}=")) {
                return substr($argument, strlen($name) + 3);
            }
        }
        return null;
    }
}
