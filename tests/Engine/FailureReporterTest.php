<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

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
        [$output, $errors] = self::capture($script);
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
        $script = <<<'PHP'
            require $argv[1];
            function makeFailure(string $word): RuntimeException
            {
                return new RuntimeException('made');
            }
            $error = makeFailure('swordfish');
            echo json_encode(['frames' => Crab\Engine\FailureReporter::frames($error), 'arguments' => $error->getTrace()[0]['args']], JSON_THROW_ON_ERROR);
            PHP;
        [$output, $errors] = self::capture($script);
        self::assertSame('', $errors);
        $trace = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $frames = $trace['frames'];

        self::assertMatchesRegularExpression('/^#0 Command line code\(\d+\): makeFailure\(\)$/', $frames[0]);
        self::assertSame('#' . (count($frames) - 1) . ' {main}', $frames[array_key_last($frames)]);
        self::assertStringNotContainsString('swordfish', implode("\n", $frames));
        self::assertSame(['swordfish'], $trace['arguments']);
    }

    /**
     * @return array{string, string} Standard output and standard error.
     */
    private static function capture(string $script): array
    {
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
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $errors = '';
        $deadline = hrtime(true) + 5_000_000_000;
        try {
            while (true) {
                $state = proc_get_status($process);
                $output .= stream_get_contents($pipes[1]);
                $errors .= stream_get_contents($pipes[2]);
                if (!$state['running']) {
                    $status = $state['exitcode'];
                    break;
                }
                if (hrtime(true) >= $deadline) {
                    proc_terminate($process, 9);
                    self::fail('Timed out waiting for the failure reporter probe to exit.');
                }
                usleep(1_000);
            }
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        self::assertSame(0, $status, $errors);
        return [$output, $errors];
    }
}
