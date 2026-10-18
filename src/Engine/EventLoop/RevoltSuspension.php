<?php

declare(strict_types=1);

namespace Crab\Engine\EventLoop;

use Crab\Engine\Suspension;
use Revolt\EventLoop\Suspension as RevoltDriverSuspension;

final readonly class RevoltSuspension implements Suspension
{
    public function __construct(
        private RevoltDriverSuspension $suspension,
    ) {}

    public function suspend(): mixed
    {
        return $this->suspension->suspend();
    }

    public function resume(mixed $value = null): void
    {
        $this->suspension->resume($value);
    }

    public function throw(\Throwable $error): void
    {
        $this->suspension->throw($error);
    }
}
