<?php

declare(strict_types=1);

namespace Crab\Engine\EventLoop;

/**
 * Signal watching through pcntl, for a FiberEventLoop backend that waits on
 * streams itself: a handler only queues the signal, and the backend calls
 * receivedSignals() after each wait to queue the watchers.
 *
 * @internal
 */
trait PcntlSignals
{
    /**
     * @var array<int, array<string, Watcher>>
     */
    private array $signalWatchers = [];

    /**
     * @var list<int> Signals received and not yet dispatched.
     */
    private array $signals = [];

    private bool $signalHandling = false;

    private function initSignals(): void
    {
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

    private function watchSignal(Watcher $watcher): void
    {
        $signal = (int) $watcher->subject;
        if (!isset($this->signalWatchers[$signal]) && !@pcntl_signal($signal, $this->receiveSignal(...))) {
            $error = error_get_last()['message'] ?? '';
            error_clear_last();
            throw new \RuntimeException("Cannot handle signal {$signal}. {$error}");
        }
        $this->signalWatchers[$signal][$watcher->id] = $watcher;
    }

    private function unwatchSignal(Watcher $watcher): void
    {
        $signal = (int) $watcher->subject;
        if (isset($this->signalWatchers[$signal])) {
            unset($this->signalWatchers[$signal][$watcher->id]);
            if ($this->signalWatchers[$signal] === []) {
                unset($this->signalWatchers[$signal]);
                @pcntl_signal($signal, SIG_DFL);
            }
        }
    }

    /**
     * Queues the watchers of every signal received so far; whether there was one.
     */
    private function receivedSignals(): bool
    {
        if (!$this->signalHandling) {
            return false;
        }
        pcntl_signal_dispatch();
        if ($this->signals === []) {
            return false;
        }
        foreach ($this->signals as $signal) {
            foreach ($this->signalWatchers[$signal] ?? [] as $watcher) {
                $this->ready($watcher);
            }
        }
        $this->signals = [];
        return true;
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
