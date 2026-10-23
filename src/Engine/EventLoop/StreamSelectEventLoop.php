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

    /**
     * @var array<int, array<string, Watcher>>
     */
    private array $signalWatchers = [];

    /**
     * @var list<int> Signals received and not yet dispatched.
     */
    private array $signals = [];

    private readonly bool $signalHandling;

    public function __construct()
    {
        parent::__construct();
        $this->signalHandling = function_exists('pcntl_signal') && function_exists('pcntl_signal_dispatch');
    }

    public function __destruct()
    {
        foreach ($this->signalWatchers as $watchers) {
            foreach ($watchers as $watcher) {
                $this->unwatch($watcher);
            }
        }
    }

    public function onSignal(int $signal, \Closure $callback): string
    {
        if (!$this->signalHandling) {
            throw new \RuntimeException('Signal handling requires the pcntl extension.');
        }
        return parent::onSignal($signal, $callback);
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
            $signal = (int) $watcher->subject;
            if (!isset($this->signalWatchers[$signal]) && !@pcntl_signal($signal, $this->receiveSignal(...))) {
                $error = error_get_last()['message'] ?? '';
                error_clear_last();
                throw new \RuntimeException("Cannot handle signal {$signal}. {$error}");
            }
            $this->signalWatchers[$signal][$watcher->id] = $watcher;
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
            $signal = (int) $watcher->subject;
            if (isset($this->signalWatchers[$signal])) {
                unset($this->signalWatchers[$signal][$id]);
                if ($this->signalWatchers[$signal] === []) {
                    unset($this->signalWatchers[$signal]);
                    @pcntl_signal($signal, SIG_DFL);
                }
            }
        }
    }

    protected function wait(float $timeout): void
    {
        if ($this->signalHandling) {
            pcntl_signal_dispatch();
            if ($this->signals !== []) {
                $this->dispatchSignals();
                $timeout = 0.0;
            }
        }
        if ($this->readStreams === [] && $this->writeStreams === []) {
            if ($timeout < 0) {
                // Only signals are watched; one interrupts the sleep.
                usleep(PHP_INT_MAX);
            } elseif ($timeout > 0) {
                usleep((int) ($timeout * 1_000_000));
            }
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

    private function dispatchSignals(): void
    {
        foreach ($this->signals as $signal) {
            foreach ($this->signalWatchers[$signal] ?? [] as $watcher) {
                $this->ready($watcher);
            }
        }
        $this->signals = [];
    }

    private function selectFailed(): void
    {
        $message = error_get_last()['message'] ?? 'Unknown error during stream_select().';
        error_clear_last();
        if (stripos($message, 'unable to select [4]: ') !== false) {
            // EINTR: a signal arrived; it is dispatched on the next turn.
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

    /**
     * Only queues the signal: pcntl_signal_dispatch() blocks signals while
     * it runs, so a callback must not suspend inside it.
     */
    private function receiveSignal(int $signal): void
    {
        $this->signals[] = $signal;
    }
}
