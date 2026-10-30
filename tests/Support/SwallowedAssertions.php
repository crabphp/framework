<?php

declare(strict_types=1);

namespace Crab\Tests\Support;

use Crab\Engine\FailureReporter;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\IncompleteTest;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Fails the run when an assertion fails where the engine catches it: in a
 * worker's work, a task, a timer or a loop callback of this process. The
 * engine reports such a failure, and the application logs it, so without
 * this the test that made it would pass.
 *
 * It only observes: every report still goes where it would. A worker in
 * another process is out of reach: assert there on what it sent back.
 */
final class SwallowedAssertions implements Extension
{
    /**
     * @var list<string>
     */
    private static array $swallowed = [];

    private static string $test = '';

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscribers(
            new class implements PreparationStartedSubscriber {
                public function notify(PreparationStarted $event): void
                {
                    SwallowedAssertions::watch($event->test()->id());
                }
            },
            new class implements FinishedSubscriber {
                public function notify(Finished $event): void
                {
                    FailureReporter::observeWith(null);
                }
            },
            new class implements ExecutionFinishedSubscriber {
                public function notify(ExecutionFinished $event): void
                {
                    SwallowedAssertions::conclude();
                }
            },
        );
    }

    public static function watch(string $test): void
    {
        self::$test = $test;
        FailureReporter::observeWith(static function (string $what, ?\Throwable $error): void {
            for ($each = $error; $each !== null; $each = $each->getPrevious()) {
                // A skip is thrown as one too, and still reaches PHPUnit through the task's await.
                if (
                    !$each instanceof AssertionFailedError
                    || $each instanceof SkippedTest
                    || $each instanceof IncompleteTest
                ) {
                    continue;
                }
                self::$swallowed[] = self::$test . "\n    {$what}: " . trim($each->getMessage());
                return;
            }
        });
    }

    public static function conclude(): void
    {
        if (self::$swallowed === []) {
            return;
        }
        $lines =
            "\nAn assertion failed where the engine caught it, so its test passed:\n\n  "
            . implode("\n  ", self::$swallowed)
            . "\n\nAssert on what the work leaves behind, after it has run.\n";
        fwrite(STDERR, $lines);
        // PHPUnit has decided its exit status by now; this one is the process's last word.
        register_shutdown_function(static function (): never {
            exit(1);
        });
    }
}
