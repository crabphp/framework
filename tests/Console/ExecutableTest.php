<?php

declare(strict_types=1);

namespace Crab\Tests\Console;

use PHPUnit\Framework\TestCase;

final class ExecutableTest extends TestCase
{
    public function testAMissingApplicationNamesThePathAndHowToCorrectIt(): void
    {
        $missing = sys_get_temp_dir() . '/missing-crab-' . bin2hex(random_bytes(6)) . '.php';
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/crab', $missing],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(1, proc_close($process));
        self::assertSame('', $output);
        self::assertStringContainsString($missing, $error);
        self::assertStringContainsString('existing application file', $error);
        self::assertStringContainsString('Crab\\App', $error);
        self::assertStringContainsString('Crab\\Engine\\Runtime', $error);
    }
}
