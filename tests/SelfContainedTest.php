<?php

declare(strict_types=1);

namespace Crab\Tests;

use Crab\Cua;
use Crab\Engine\Process\Interpreter;
use PHPUnit\Framework\TestCase;

/**
 * A self-contained executable, PHP and the application in one file, is
 * run again without a PHP binary before it, and launches a server's
 * command through itself in place of PHP's -r.
 */
final class SelfContainedTest extends TestCase
{
    public function testOrdinarilyThePhpBinaryRunsTheScript(): void
    {
        self::assertFalse(Interpreter::selfContained());
        self::assertSame([PHP_BINARY], Interpreter::prefix());
    }

    public function testTheLaunchArgumentRunsTheCommandInPlaceOfTheProcess(): void
    {
        foreach (['keep' => false, 'detach' => true] as $mode => $ownSession) {
            $script = sprintf(
                'require %s; exit(Crab\Cua::run("/no/such/app.php", [%s, %s, PHP_BINARY, "-r", "echo getmypid() === posix_getsid(0) ? \"own\" : \"shared\";"]));',
                var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
                var_export(Cua::LAUNCH, true),
                var_export($mode, true),
            );
            exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $output, $status);

            self::assertSame(0, $status, implode("\n", $output));
            self::assertSame($ownSession ? 'own' : 'shared', end($output), "With \"{$mode}\".");
            $output = [];
        }
    }
}
