<?php

declare(strict_types=1);

namespace Crab\Engine;

/**
 * A place where one fiber waits: suspend() returns what resume() is given,
 * or throws what throw() is given.
 */
interface Suspension
{
    public function suspend(): mixed;

    public function resume(mixed $value = null): void;

    public function throw(\Throwable $error): void;
}
