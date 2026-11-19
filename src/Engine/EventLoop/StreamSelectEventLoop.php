<?php

declare(strict_types=1);

namespace Crab\Engine\EventLoop;

/**
 * The pure-PHP loop backend: stream_select() for streams, pcntl for
 * signals. stream_select() watches descriptors below FD_SETSIZE, usually
 * 1024. Ported from Revolt's StreamSelectDriver (MIT); see FiberEventLoop.
 */
final class StreamSelectEventLoop extends FiberEventLoop
{
    use PcntlSignals;

    /**
     * @var array<int, resource>
     */
    private array $readStreams = [];

    /**
     * @var array<int, array<string, Watcher>>
     */
    private array $readWatchers = [];

    /**
     * @var array<int, resource>
     */
    private array $writeStreams = [];

    /**
     * @var array<int, array<string, Watcher>>
     */
    private array $writeWatchers = [];

    public function __construct()
    {
        parent::__construct();
        $this->initSignals();
    }

    protected function watch(Watcher $watcher): void
    {
        if ($watcher->type === Watcher::READABLE) {
            $this->readWatchers[(int) $watcher->subject][$watcher->id] = $watcher;
            $this->readStreams[(int) $watcher->subject] = $watcher->subject;
        } elseif ($watcher->type === Watcher::WRITABLE) {
            $this->writeWatchers[(int) $watcher->subject][$watcher->id] = $watcher;
            $this->writeStreams[(int) $watcher->subject] = $watcher->subject;
        } else {
            $this->watchSignal($watcher);
        }
    }

    protected function unwatch(Watcher $watcher): void
    {
        $id = $watcher->id;
        if ($watcher->type === Watcher::READABLE) {
            $stream = (int) $watcher->subject;
            unset($this->readWatchers[$stream][$id]);
            if (($this->readWatchers[$stream] ?? []) === []) {
                unset($this->readWatchers[$stream], $this->readStreams[$stream]);
            }
        } elseif ($watcher->type === Watcher::WRITABLE) {
            $stream = (int) $watcher->subject;
            unset($this->writeWatchers[$stream][$id]);
            if (($this->writeWatchers[$stream] ?? []) === []) {
                unset($this->writeWatchers[$stream], $this->writeStreams[$stream]);
            }
        } else {
            $this->unwatchSignal($watcher);
        }
    }

    protected function wait(float $timeout): void
    {
        if ($this->receivedSignals()) {
            $timeout = 0.0;
        }
        if ($this->readStreams === [] && $this->writeStreams === []) {
            if ($timeout < 0) {
                // Only signals are watched; one interrupts the sleep.
                usleep(PHP_INT_MAX);
            } elseif ($timeout > 0) {
                usleep((int) ($timeout * 1_000_000));
            }
            $this->receivedSignals();
            return;
        }
        $read = $this->readStreams;
        $write = $this->writeStreams;
        $except = null;
        $seconds = null;
        $microseconds = null;
        if ($timeout >= 0) {
            $seconds = (int) $timeout;
            $microseconds = (int) (($timeout - $seconds) * 1_000_000);
        }
        $count = @stream_select($read, $write, $except, $seconds, $microseconds);
        // A signal that cut the wait short is handled this turn, before the
        // timers now due, which would otherwise run first.
        $this->receivedSignals();
        if ($count === false) {
            $this->selectFailed();
            return;
        }
        if ($count === 0) {
            return;
        }
        foreach ($read as $stream) {
            foreach ($this->readWatchers[(int) $stream] ?? [] as $watcher) {
                $this->ready($watcher);
            }
        }
        foreach ($write as $stream) {
            foreach ($this->writeWatchers[(int) $stream] ?? [] as $watcher) {
                $this->ready($watcher);
            }
        }
    }

    private function selectFailed(): void
    {
        $message = error_get_last()['message'] ?? 'Unknown error during stream_select().';
        error_clear_last();
        if (stripos($message, 'unable to select [4]: ') !== false) {
            // EINTR: a signal arrived, and wait() has queued its watchers.
            return;
        }
        if (
            preg_match(
                '/FD_SETSIZE\. It is set to (\d+), but you have descriptors numbered at least as high as (\d+)/',
                $message,
                $match,
            ) === 1
        ) {
            $message =
                "stream_select() watches file descriptors below FD_SETSIZE ({$match[1]}), but descriptor {$match[2]} is open. "
                . 'Lower the number of connections a worker accepts, use a native loop backend, or look for descriptors that are never closed.';
        }
        throw new \RuntimeException($message);
    }
}
