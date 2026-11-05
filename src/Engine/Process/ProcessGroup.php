<?php

declare(strict_types=1);

namespace Crab\Engine\Process;

/**
 * Starts a command in a process group of its own, so that signalling the
 * group stops everything the command started. Linux has the `setsid`
 * program; macOS has none, but always has perl, which can do the same.
 *
 * Subprocess and Process use it; a caller may ask whether groups are possible.
 */
final class ProcessGroup
{
    /**
     * Perl's setsid: a new session and group led by this process, then the
     * command in its place, with the same process id. Run by itself, as
     * proc_open starts it, it never leads a group already.
     */
    public const string PERL = 'use POSIX (); POSIX::setsid(); exec { $ARGV[0] } @ARGV or die "$ARGV[0]: $!\n";';

    /** @var list<string>|false|null */
    private static array|false|null $prefix = null;

    /**
     * The command run so, or null where a group cannot be made or signalled.
     *
     * @param list<string> $command
     * @return list<string>|null
     */
    public static function wrap(array $command): ?array
    {
        $prefix = self::$prefix ??= self::find();
        return $prefix === false ? null : [...$prefix, ...$command];
    }

    /**
     * @return list<string>|false
     */
    private static function find(): array|false
    {
        if (!function_exists('posix_kill')) {
            return false;
        }
        foreach (['/usr/bin/setsid', '/bin/setsid'] as $path) {
            if (is_executable($path)) {
                return [$path, '--'];
            }
        }
        if (is_executable('/usr/bin/perl')) {
            return ['/usr/bin/perl', '-e', self::PERL, '--'];
        }
        return false;
    }
}
