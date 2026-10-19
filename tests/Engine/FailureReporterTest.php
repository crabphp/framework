<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\FailureReporter;
use PHPUnit\Framework\TestCase;

/**
 * What the engine writes to standard error when nothing else takes a report.
 */
final class FailureReporterTest extends TestCase
{
    public function testAReportOnStandardErrorNamesEachCallWithoutTheArgumentsItWasGiven(): void
    {
        $script = <<<'PHP'
            require $argv[1];
            $login = static function (string $email, string $typed): never {
                try {
                    (static fn(string $word): never => throw new \LogicException('inner'))('swordfish');
                } catch (\LogicException $error) {
                    throw new \RuntimeException('outer', 0, $error);
                }
            };
            try {
                $login('ann@example.com', 'hunter2');
            } catch (\RuntimeException $error) {
                Crab\Engine\FailureReporter::report('Task failed', $error);
            }
            PHP;
        $process = proc_open(
            [
                PHP_BINARY,
                '-d',
                'zend.exception_ignore_args=0',
                '-r',
                $script,
                dirname(__DIR__, 2) . '/vendor/autoload.php',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertSame('', $output);

        self::assertStringStartsWith('[Crab] Task failed: LogicException: inner in ', $errors);
        self::assertStringContainsString("\n\nNext RuntimeException: outer in ", $errors);
        self::assertStringContainsString("Stack trace:\n#0 ", $errors);
        self::assertMatchesRegularExpression('/\{closure[^\n]*\}\(\)\n/', $errors);
        self::assertMatchesRegularExpression('/\n#\d+ \{main\}\n$/', $errors);
        foreach (['hunter2', 'ann@example.com', 'swordfish'] as $argument) {
            self::assertStringNotContainsString($argument, $errors);
        }
    }

    public function testATraceIsItsFramesWithoutArguments(): void
    {
        $error = (static fn(string $word): \RuntimeException => new \RuntimeException('made'))('swordfish');

        $frames = FailureReporter::frames($error);

        self::assertMatchesRegularExpression(
            '/^#0 ' . preg_quote(__FILE__, '/') . '\(\d+\): [^\n]*\{closure[^\n]*\}\(\)$/',
            $frames[0],
        );
        self::assertStringEndsWith('->testATraceIsItsFramesWithoutArguments()', $frames[1]);
        self::assertSame('#' . (count($frames) - 1) . ' {main}', $frames[array_key_last($frames)]);
        self::assertStringNotContainsString('swordfish', implode("\n", $frames));
        self::assertStringContainsString("'swordfish'", $error->getTraceAsString());
    }
}
