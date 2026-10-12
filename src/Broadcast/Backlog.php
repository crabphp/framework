<?php

declare(strict_types=1);

namespace Crab\Broadcast;

use Crab\Contracts\WebSocket\Recipient;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\Network\WriteResult;

/**
 * A bounded FIFO for one recipient that fell behind. Its single deadline
 * covers catching up, including new messages, without delaying neighbours.
 * Recipients that keep up never allocate this object, task or timer.
 */
final class Backlog
{
    /**
     * @var \SplQueue<string>
     */
    private readonly \SplQueue $messages;
    private readonly Cancellation $cancellation;
    private readonly EventLoop $loop;
    private readonly Task $task;
    private readonly string $timer;
    private int $bytes = 0;
    private bool $closed = false;

    /**
     * @param \Closure(self, WriteResult, ?\Throwable): void $finished Removes ownership and handles a failure.
     */
    public function __construct(
        public readonly Recipient $socket,
        private readonly int $maxBytes,
        private readonly int $batch,
        private readonly \Closure $finished,
    ) {
        $this->messages = new \SplQueue();
        $this->cancellation = new Cancellation();
        $this->loop = EventLoop::current();
        $this->timer = $this->loop->delay($socket->broadcastTimeout(), fn () => $this->cancellation->cancel());
        $this->task = Task::spawn($this->loop, $this->drain(...));
    }

    public function offer(string $message): bool
    {
        // Empty messages consume queue objects too; charge their metadata.
        $bytes = strlen($message) + 256;
        if ($this->bytes + $bytes > $this->maxBytes) {
            return false;
        }
        $this->messages->enqueue($message);
        $this->bytes += $bytes;
        return true;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    private function drain(): void
    {
        $result = WriteResult::Accepted;
        $error = null;
        try {
            $written = 0;
            while (!$this->closed) {
                if (!$this->socket->awaitBroadcastWritable($this->cancellation)) {
                    $result = WriteResult::Closed;
                    break;
                }
                if ($this->messages->isEmpty()) {
                    break;
                }
                $message = $this->messages->bottom();
                $result = $this->socket->broadcast($message);
                if ($result === WriteResult::Closed) {
                    break;
                }
                if ($result === WriteResult::Accepted) {
                    $this->messages->dequeue();
                    $this->bytes -= strlen($message) + 256;
                }
                if (++$written === $this->batch) {
                    $written = 0;
                    Async::sleep($this->loop, 0, $this->cancellation);
                }
            }
        } catch (CancelledException) {
            $result = WriteResult::Backpressured;
        } catch (\Throwable $failure) {
            $error = $failure;
        } finally {
            $this->loop->cancel($this->timer);
            if (!$this->closed) {
                ($this->finished)($this, $result, $error);
            }
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->loop->cancel($this->timer);
        $this->cancellation->cancel();
        $this->task->cancel();
        while (!$this->messages->isEmpty()) {
            $this->messages->dequeue();
        }
        $this->bytes = 0;
    }
}
