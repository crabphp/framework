<?php

declare(strict_types=1);

namespace Crab\Engine\Process;

/**
 * What a finished subprocess left behind: its exit code and what it wrote.
 */
final readonly class SubprocessResult
{
    /**
     * @param int|null $exitCode The code it exited with; null when it was killed, by a signal, its timeout or its output limit.
     * @param string $output Standard output and standard error together, in the order they arrived.
     * @param bool $timedOut Whether it was killed for running past its timeout.
     * @param bool $truncated Whether it was killed for writing more than its output limit; the output then ends at the limit.
     */
    public function __construct(
        public ?int $exitCode,
        public string $stdout,
        public string $stderr,
        public string $output,
        public bool $timedOut = false,
        public bool $truncated = false,
    ) {}

    /**
     * Whether it ran to its end and exited with zero.
     */
    public function ok(): bool
    {
        return $this->exitCode === 0;
    }
}
