<?php

declare(strict_types=1);

namespace Crab\Engine\EventLoop;

/**
 * The enabled timers of a StreamSelectEventLoop, soonest first, as a binary
 * heap in an array. Ported from Revolt's TimerQueue (MIT).
 *
 * @internal
 */
final class TimerHeap
{
    /**
     * @var array<int, Watcher>
     */
    private array $timers = [];

    /**
     * @var array<string, int> The heap position of each timer, by id.
     */
    private array $positions = [];

    public function insert(Watcher $timer): void
    {
        $node = count($this->timers);
        $this->timers[$node] = $timer;
        $this->positions[$timer->id] = $node;
        $this->up($node);
    }

    public function remove(Watcher $timer): void
    {
        if (isset($this->positions[$timer->id])) {
            $this->removeAt($this->positions[$timer->id]);
        }
    }

    /**
     * Takes the soonest timer if it is due by $now.
     */
    public function extract(float $now): ?Watcher
    {
        if ($this->timers === [] || $this->timers[0]->expiration > $now) {
            return null;
        }
        $timer = $this->timers[0];
        $this->removeAt(0);
        return $timer;
    }

    /**
     * When the soonest timer is due, or null without timers.
     */
    public function peek(): ?float
    {
        return isset($this->timers[0]) ? $this->timers[0]->expiration : null;
    }

    private function up(int $node): void
    {
        $expiration = $this->timers[$node]->expiration;
        while ($node !== 0 && $expiration < $this->timers[$parent = ($node - 1) >> 1]->expiration) {
            $this->swap($node, $parent);
            $node = $parent;
        }
    }

    private function down(int $node): void
    {
        $length = count($this->timers);
        while (($child = ($node << 1) + 1) < $length) {
            $right = $child + 1;
            if ($right < $length && $this->timers[$right]->expiration < $this->timers[$child]->expiration) {
                $child = $right;
            }
            if ($this->timers[$child]->expiration >= $this->timers[$node]->expiration) {
                break;
            }
            $this->swap($node, $child);
            $node = $child;
        }
    }

    private function swap(int $left, int $right): void
    {
        $timer = $this->timers[$left];
        $this->timers[$left] = $this->timers[$right];
        $this->positions[$this->timers[$left]->id] = $left;
        $this->timers[$right] = $timer;
        $this->positions[$timer->id] = $right;
    }

    private function removeAt(int $node): void
    {
        $last = count($this->timers) - 1;
        unset($this->positions[$this->timers[$node]->id]);
        if ($node === $last) {
            unset($this->timers[$last]);
            return;
        }
        $moved = $this->timers[$node] = $this->timers[$last];
        $this->positions[$moved->id] = $node;
        unset($this->timers[$last]);
        if ($node !== 0 && $moved->expiration < $this->timers[($node - 1) >> 1]->expiration) {
            $this->up($node);
        } else {
            $this->down($node);
        }
    }
}
