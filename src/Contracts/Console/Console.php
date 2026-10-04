<?php

declare(strict_types=1);

namespace Crab\Contracts\Console;

/**
 * Runs commands by name, and the way commands are added.
 */
interface Console
{
    /**
     * A command is a Command, a closure taking the input and output, or the
     * name of a Command class. The description is used for a closure.
     */
    public function add(string $name, Command|\Closure|string $command, string $description = ''): static;

    public function has(string $name): bool;

    /**
     * @param list<string> $arguments The command's name followed by its own words.
     * @return int The process exit status.
     */
    public function run(array $arguments, ?Output $output = null): int;
}
