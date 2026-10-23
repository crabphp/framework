<?php

declare(strict_types=1);

namespace Crab\Engine\Tests\Support;

use Closure;
use Crab\Engine\EventLoop;
use Crab\Engine\FailureReporter;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Throwable;

abstract class TestCase extends PHPUnitTestCase
{
    /**
     * @var list<Closure>
     */
    private array $cleanups = [];
    private ?Throwable $callbackFailure = null;
    /**
     * What the engine reported during the test, as "what: message" lines,
     * collected here instead of being written to standard error.
     *
     * @var list<string>
     */
    protected array $reports = [];
    private ?Closure $previousReporter = null;

    #[Before]
    protected function collectFailureReports(): void
    {
        $this->reports = [];
        $this->previousReporter = FailureReporter::handleWith(function (string $what, ?Throwable $error): void {
            $this->reports[] = $error === null ? $what : $what . ': ' . $error->getMessage();
        });
    }

    #[After]
    protected function restoreFailureReporter(): void
    {
        FailureReporter::handleWith($this->previousReporter);
    }

    protected function deferCleanup(callable $cleanup): void
    {
        $this->cleanups[] = $cleanup(...);
    }

    /**
     * The engine deliberately catches application callback failures. Remember
     * test failures here so that an assertion inside a callback cannot pass silently.
     */
    protected function guardCallback(callable $callback): Closure
    {
        return function (mixed ...$arguments) use ($callback): mixed {
            try {
                return $callback(...$arguments);
            } catch (Throwable $error) {
                $this->callbackFailure ??= $error;
                throw $error;
            }
        };
    }

    protected function tearDown(): void
    {
        $cleanupFailure = null;
        while ($this->cleanups !== []) {
            $cleanup = array_pop($this->cleanups);
            try {
                $cleanup();
            } catch (Throwable $error) {
                $cleanupFailure ??= $error;
            }
        }
        // A callback failure or main-context suspension leaves the loop's
        // fiber suspended. Cancelling watchers alone does not finish it.
        $loop = EventLoop::current();
        if ($loop->isRunning()) {
            foreach ($loop->getIdentifiers() as $id) {
                $loop->cancel($id);
            }
            while ($loop->isRunning()) {
                $loop->stop();
                try {
                    $loop->run();
                } catch (Throwable $error) {
                    $cleanupFailure ??= $error;
                }
            }
        }
        parent::tearDown();
        $failure = $this->callbackFailure ?? $cleanupFailure;
        $this->callbackFailure = null;
        if ($failure !== null) {
            throw $failure;
        }
    }
}
