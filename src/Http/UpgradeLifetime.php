<?php

declare(strict_types=1);

namespace Crab\Http;

/**
 * Shared by response clones: dropping the last unconverted response ends
 * its waiting action, without depending on garbage collection of that action.
 *
 * @internal
 */
final class UpgradeLifetime
{
    private bool $converted = false;

    public function __construct(
        private readonly \Closure $discard,
    ) {}

    public function converted(): void
    {
        $this->converted = true;
    }

    public function discard(): void
    {
        if (!$this->converted) {
            $this->converted = true;
            ($this->discard)();
        }
    }

    public function __destruct()
    {
        $this->discard();
    }
}
