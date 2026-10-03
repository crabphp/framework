<?php

declare(strict_types=1);

namespace Crab\Engine\Tests\Support;

use Closure;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Throwable;

abstract class TestCase extends PHPUnitTestCase
{
    /**
     * @var list<Closure>
     */
    private array $cleanups = [];
    private ?Throwable $callbackFailure = null;

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
        while ($cleanup = array_pop($this->cleanups)) {
            try {
                $cleanup();
            } catch (Throwable $error) {
                $cleanupFailure ??= $error;
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
