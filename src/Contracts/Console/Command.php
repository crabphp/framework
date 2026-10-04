<?php

declare(strict_types=1);

namespace Crab\Contracts\Console;

/**
 * Something the console can run by name.
 */
interface Command
{
    /**
     * The first line is shown in the command list; all of it by `help`.
     */
    public function description(): string;

    /**
     * @return int The process exit status, 0 for success.
     */
    public function handle(Input $input, Output $output): int;
}
