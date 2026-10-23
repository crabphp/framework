<?php

declare(strict_types=1);

namespace Crab\Engine\EventLoop;

/**
 * One timer, stream, signal or deferred callback of a StreamSelectEventLoop.
 *
 * @internal
 */
final class Watcher
{
    public const int DEFER = 0;
    public const int DELAY = 1;
    public const int REPEAT = 2;
    public const int READABLE = 3;
    public const int WRITABLE = 4;
    public const int SIGNAL = 5;

    public bool $enabled = true;
    public bool $referenced = true;

    /**
     * Whether the loop has started watching it; a watcher enabled during a
     * turn is not called before the next one.
     */
    public bool $invokable = false;

    /**
     * When a timer is next due, in seconds of the loop's clock.
     */
    public float $expiration = 0.0;

    /**
     * @param self::* $type
     * @param mixed $subject The stream of a stream watcher, the signal number of a signal watcher.
     * @param float $interval The seconds of a timer.
     */
    public function __construct(
        public readonly string $id,
        public readonly int $type,
        public readonly \Closure $callback,
        public readonly mixed $subject = null,
        public readonly float $interval = 0.0,
    ) {}
}
