<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use Crab\Engine\Tests\Support\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

final class TestCaseTest extends TestCase
{
    #[DataProvider('assertionContexts')]
    public function testTeardownFinishesAnInterruptedLoopAndPreservesTheOriginalAssertion(bool $inCallback): void
    {
        $loop = new StreamSelectEventLoop();
        EventLoop::install($loop);
        $fixture = new class('failWhileTheLoopIsSuspended') extends TestCase {
            public function failWhileTheLoopIsSuspended(StreamSelectEventLoop $loop, bool $inCallback): void
            {
                $this->deferCleanup(static function () use ($loop): void {
                    foreach ($loop->getIdentifiers() as $id) {
                        $loop->cancel($id);
                    }
                });
                if ($inCallback) {
                    $loop->defer($this->guardCallback(static function (): void {
                        self::fail('original assertion');
                    }));
                    $loop->run();
                } else {
                    $suspension = $loop->getSuspension();
                    $loop->defer(static fn() => $suspension->resume());
                    $suspension->suspend();
                    self::fail('original assertion');
                }
            }

            public function finish(): void
            {
                $this->tearDown();
            }
        };
        try {
            $original = null;
            try {
                $fixture->failWhileTheLoopIsSuspended($loop, $inCallback);
            } catch (Throwable $error) {
                $original = $error instanceof AssertionFailedError ? $error : $error->getPrevious();
            }
            self::assertInstanceOf(AssertionFailedError::class, $original);
            self::assertSame('original assertion', $original->getMessage());
            self::assertTrue($loop->isRunning(), 'Fixture did not reproduce an interrupted loop.');
            $cleanupFailure = null;
            try {
                $fixture->finish();
            } catch (Throwable $error) {
                $cleanupFailure = $error;
            }
            self::assertSame($inCallback ? $original : null, $cleanupFailure, 'Cleanup hid the original assertion.');
            self::assertFalse($loop->isRunning(), 'Teardown left the interrupted loop running.');
            $next = new StreamSelectEventLoop();
            EventLoop::install($next);
            $ran = false;
            $next->defer(static function () use (&$ran): void {
                $ran = true;
            });
            $next->run();
            self::assertTrue($ran, 'The next test could not run its own loop.');
        } finally {
            // Even a regression in teardown must not contaminate this test's successors.
            foreach ($loop->getIdentifiers() as $id) {
                $loop->cancel($id);
            }
            $loop->stop();
            if ($loop->isRunning()) {
                $loop->run();
            }
        }
    }

    public static function assertionContexts(): iterable
    {
        yield 'assertion inside a callback' => [true];
        yield 'assertion after a main-context suspension' => [false];
    }
}
