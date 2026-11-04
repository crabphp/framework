<?php

declare(strict_types=1);

namespace Crab\Actors;

/**
 * A message in line for another process. It holds its place from the
 * moment it is sent: an ask's is published only once its reply has
 * somewhere to go, and those after it wait for it, so the actor takes
 * them in the order they were sent. One withdrawn before its turn is not
 * published.
 *
 * @internal
 */
final class Outgoing
{
    public bool $ready = false;
    public bool $withdrawn = false;
    public bool $published = false;

    /**
     * @param \Closure(): string $text The message as published, made when its turn comes.
     * @param \Closure(?\Throwable): void $done Told once it was published, or could not be.
     */
    public function __construct(
        public readonly string $channel,
        public readonly \Closure $text,
        public \Closure $done,
    ) {}
}
