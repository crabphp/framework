<?php

declare(strict_types=1);

namespace Crab\Engine\EventLoop;

use Io\Poll\Context;
use Io\Poll\Event;
use Io\Poll\FailedPollOperationException;
use Io\Poll\FailedPollWaitException;

/**
 * The loop backend on PHP 8.6's Io\Poll: epoll on Linux, kqueue on BSD and
 * macOS, with pcntl for signals. Unlike stream_select() it has no
 * FD_SETSIZE limit, and a wait costs what is ready rather than what is
 * watched. Each stream has one poll watcher, whose events are the union of
 * its read and write watchers. A closed stream leaves the poll context by
 * itself. A forked process takes a poll context of its own when it next
 * uses the loop, since the one it inherited is shared with its parent.
 */
final class PollEventLoop extends FiberEventLoop
{
    use PcntlSignals;

    private Context $context;

    /**
     * The process the poll context belongs to.
     */
    private int $pid;

    /**
     * @var array<int, \Io\Poll\Watcher> Each watched stream's poll watcher, by resource id.
     */
    private array $polled = [];

    /**
     * @var array<int, array<string, Watcher>>
     */
    private array $readWatchers = [];

    /**
     * @var array<int, array<string, Watcher>>
     */
    private array $writeWatchers = [];

    public function __construct()
    {
        if (!self::isSupported()) {
            throw new \RuntimeException('The poll loop needs PHP 8.6 or later, for Io\Poll.');
        }
        parent::__construct();
        $this->context = new Context();
        $this->pid = getmypid();
        $this->initSignals();
    }

    public static function isSupported(): bool
    {
        return class_exists(Context::class, false);
    }

    /**
     * The Io\Poll backend it waits with, such as Epoll or Kqueue.
     */
    public function backend(): string
    {
        return $this->context()->getBackend()->name;
    }

    protected function watch(Watcher $watcher): void
    {
        if ($watcher->type === Watcher::SIGNAL) {
            $this->watchSignal($watcher);
            return;
        }
        $stream = (int) $watcher->subject;
        if ($watcher->type === Watcher::READABLE) {
            $this->readWatchers[$stream][$watcher->id] = $watcher;
        } else {
            $this->writeWatchers[$stream][$watcher->id] = $watcher;
        }
        $this->sync($stream, $watcher->subject);
    }

    protected function unwatch(Watcher $watcher): void
    {
        if ($watcher->type === Watcher::SIGNAL) {
            $this->unwatchSignal($watcher);
            return;
        }
        $stream = (int) $watcher->subject;
        if ($watcher->type === Watcher::READABLE) {
            unset($this->readWatchers[$stream][$watcher->id]);
            if (($this->readWatchers[$stream] ?? null) === []) {
                unset($this->readWatchers[$stream]);
            }
        } else {
            unset($this->writeWatchers[$stream][$watcher->id]);
            if (($this->writeWatchers[$stream] ?? null) === []) {
                unset($this->writeWatchers[$stream]);
            }
        }
        $this->sync($stream, $watcher->subject);
    }

    protected function wait(float $timeout): void
    {
        // Before the signal check, to keep the time from it to the wait short.
        $context = $this->context();
        if ($this->receivedSignals()) {
            $timeout = 0.0;
        }
        try {
            $ready = $context->wait(
                $timeout < 0 ? null : \Time\Duration::fromNanoseconds((int) ($timeout * 1_000_000_000)),
            );
        } catch (FailedPollWaitException $error) {
            // A signal that cut the wait short is handled this turn.
            if ($error->getCode() === FailedPollOperationException::ERROR_INTERRUPTED) {
                $this->receivedSignals();
                return;
            }
            throw new \RuntimeException('Waiting on streams failed: ' . $error->getMessage(), 0, $error);
        }
        // Before the timers now due, which would otherwise run first.
        $this->receivedSignals();
        foreach ($ready as $polled) {
            $stream = $polled->getData();
            // An error or hang-up wakes both sides, as select() reports them.
            $failed = $polled->hasTriggered(Event::Error) || $polled->hasTriggered(Event::HangUp);
            if ($failed || $polled->hasTriggered(Event::Read) || $polled->hasTriggered(Event::ReadHangUp)) {
                foreach ($this->readWatchers[$stream] ?? [] as $watcher) {
                    $this->ready($watcher);
                }
            }
            if ($failed || $polled->hasTriggered(Event::Write)) {
                foreach ($this->writeWatchers[$stream] ?? [] as $watcher) {
                    $this->ready($watcher);
                }
            }
        }
    }

    /**
     * Makes the stream's poll watcher ask for what its watchers want, adding
     * or removing it as needed.
     *
     * @param resource $resource
     */
    private function sync(int $stream, mixed $resource): void
    {
        // In a forked process, before an inherited watcher is changed for the parent too.
        $context = $this->context();
        $events = [];
        if (isset($this->readWatchers[$stream])) {
            $events[] = Event::Read;
        }
        if (isset($this->writeWatchers[$stream])) {
            $events[] = Event::Write;
        }
        $polled = $this->polled[$stream] ?? null;
        if ($polled !== null && !$polled->isActive()) {
            // Its stream was closed, which removed it.
            unset($this->polled[$stream]);
            $polled = null;
        }
        if ($events === []) {
            if ($polled !== null) {
                unset($this->polled[$stream]);
                $polled->remove();
            }
            return;
        }
        if ($polled !== null) {
            $polled->modifyEvents($events);
            return;
        }
        $this->polled[$stream] = $context->add(new \StreamPollHandle($resource), $events, $stream);
    }

    /**
     * The poll context of this process. After a fork, the inherited one is
     * the parent's too: what either side adds wakes the other with a
     * watcher it does not have, and removing a stream removes it for both.
     * The child leaves it untouched and adds what it watches to its own.
     */
    private function context(): Context
    {
        if ($this->pid !== getmypid()) {
            $this->pid = getmypid();
            $this->context = new Context();
            $this->polled = [];
            foreach ($this->readWatchers + $this->writeWatchers as $stream => $watchers) {
                $resource = reset($watchers)->subject;
                // A closed stream's watchers are cancelled as they come up.
                if (is_resource($resource)) {
                    $this->sync($stream, $resource);
                }
            }
        }
        return $this->context;
    }
}
