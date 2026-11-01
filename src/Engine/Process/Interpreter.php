<?php

declare(strict_types=1);

namespace Crab\Engine\Process;

/**
 * How this PHP program is run again in a new process. Ordinarily that is
 * the PHP binary followed by the script. A self-contained executable, PHP
 * and the application in one file as static-php-cli's micro builds it, is
 * its own script: there is no PHP binary to name before it, and it takes
 * none of PHP's options, such as -r.
 */
final class Interpreter
{
    /**
     * Whether this program is a self-contained executable.
     */
    public static function selfContained(): bool
    {
        return PHP_SAPI === 'micro';
    }

    /**
     * What goes before the script on the command line: the PHP binary, or
     * nothing for a self-contained executable, whose script is itself.
     *
     * @return list<string>
     */
    public static function prefix(): array
    {
        return self::selfContained() ? [] : [PHP_BINARY];
    }
}
