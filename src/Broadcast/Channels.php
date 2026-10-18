<?php

declare(strict_types=1);

namespace Crab\Broadcast;

use Crab\Contracts\Broadcast\Carrier;
use Crab\Contracts\Broadcast\Channel;
use Crab\Contracts\WebSocket\Channels as ChannelsContract;
use Crab\Contracts\WebSocket\Recipient;
use Crab\Engine\EventLoop;
use Crab\Engine\FailureReporter;
use Crab\Engine\Network\WriteResult;

/**
 * A worker's memberships and ordered fan-out. One continuation writes a
 * bounded batch; senders hand off without waiting for clients to read.
 */
final class Channels implements ChannelsContract
{
    /**
     * @var array<string, array<int, Recipient>>
     */
    private array $channels = [];
    /**
     * @var array<int, array<string, string>>
     */
    private array $memberships = [];
    /**
     * @var array<string, Joining>
     */
    private array $joining = [];
    /**
     * Only recipients with backpressured output have a backlog.
     *
     * @var array<int, Backlog>
     */
    private array $backlogs = [];
    /**
     * @var \SplQueue<Delivery>
     */
    private \SplQueue $pending;
    private ?EventLoop $loop = null;
    private ?string $continuation = null;
    private int $pendingBytes = 0;
    private int $slowClients = 0;
    private bool $stopped = false;

    public function __construct(
        private readonly Carrier $carrier,
        private readonly int $maxChannels = 64,
        private readonly int $batch = 128,
        private readonly int $maxPendingBytes = 1_048_576,
        private readonly int $maxBacklogBytes = 524_288,
    ) {
        if ($maxChannels < 1 || $batch < 1 || $maxPendingBytes < 1 || $maxBacklogBytes < 1) {
            throw new \InvalidArgumentException(
                'Set broadcast.max_channels, broadcast.batch, broadcast.max_pending_bytes and broadcast.max_backlog_bytes to positive whole numbers.',
            );
        }
        $this->pending = new \SplQueue();
    }

    public function join(Recipient $socket, string $channel): void
    {
        new Channel($channel);
        if ($this->stopped) {
            throw new \LogicException('Broadcast has stopped; join channels in a running worker.');
        }
        $key = ':' . $channel;
        $id = spl_object_id($socket);
        if (isset($this->memberships[$id][$key])) {
            return;
        }
        if (count($this->memberships[$id] ?? []) >= $this->maxChannels) {
            throw new \LengthException(
                "This socket already joined {$this->maxChannels} channels. Leave one or increase broadcast.max_channels.",
            );
        }
        $first = !isset($this->channels[$key]);
        $this->channels[$key][$id] = $socket;
        $this->memberships[$id][$key] = $channel;
        $joining = $this->joining[$key] ?? null;
        try {
            if ($first) {
                $this->joining[$key] = $joining = new Joining();
                try {
                    $this->carrier->subscribe($channel, $this->receive(...), $this->gap(...));
                } catch (\Throwable $error) {
                    $joining->finish($error);
                    throw $error;
                }
                $joining->finish(null);
            } elseif ($joining !== null) {
                $joining->await();
            }
        } catch (\Throwable $error) {
            $this->leave($socket, $channel);
            throw $error;
        } finally {
            if ($first && ($this->joining[$key] ?? null) === $joining) {
                unset($this->joining[$key]);
            }
        }
        if (!isset($this->memberships[$id][$key]) && !isset($this->channels[$key])) {
            // A peer can disconnect while a Redis join awaits confirmation.
            $this->carrier->unsubscribe($channel);
        }
    }

    public function leave(Recipient $socket, string $channel): void
    {
        new Channel($channel);
        $key = ':' . $channel;
        $id = spl_object_id($socket);
        unset($this->memberships[$id][$key], $this->channels[$key][$id]);
        if (($this->memberships[$id] ?? []) === []) {
            unset($this->memberships[$id]);
            $this->removeBacklog($id);
        }
        if (isset($this->channels[$key]) && $this->channels[$key] === []) {
            unset($this->channels[$key]);
            $this->carrier->unsubscribe($channel);
        }
    }

    public function forget(Recipient $socket): void
    {
        foreach ($this->memberships[spl_object_id($socket)] ?? [] as $channel) {
            try {
                $this->leave($socket, $channel);
            } catch (\Throwable $error) {
                FailureReporter::report('Broadcast could not leave a channel; check carrier cleanup', $error);
            }
        }
    }

    private function receive(string $channel, string $message): void
    {
        $key = ':' . $channel;
        $sockets = $this->channels[$key] ?? [];
        if ($sockets === [] || $this->stopped) {
            return;
        }
        // Charge even empty payloads so a burst cannot queue unbounded objects.
        $bytes = strlen($message) + 256;
        if (($this->pendingBytes + $bytes) > $this->maxPendingBytes) {
            $this->gap();
            throw new \OverflowException(
                'Broadcast fan-out exceeded broadcast.max_pending_bytes; clients were closed to refetch state. Reduce the send rate or increase the bound.',
            );
        }
        $this->pending->enqueue(new Delivery($sockets, $message, $key));
        $this->pendingBytes += $bytes;
        $this->schedule();
    }

    private function schedule(): void
    {
        if ($this->continuation === null && !$this->pending->isEmpty()) {
            $this->loop ??= EventLoop::current();
            $this->continuation = $this->loop->defer($this->deliver(...));
        }
    }

    private function deliver(): void
    {
        $this->continuation = null;
        for ($written = 0; $written < $this->batch && !$this->pending->isEmpty(); ++$written) {
            $delivery = $this->pending->bottom();
            $socket = $delivery->take();
            if ($socket === null) {
                $this->pending->dequeue();
                $this->pendingBytes -= strlen($delivery->message) + 256;
                continue;
            }
            $id = spl_object_id($socket);
            if (!isset($this->channels[$delivery->channelKey][$id])) {
                continue;
            }
            try {
                if ($this->backlogs !== [] && isset($this->backlogs[$id])) {
                    $this->queue($socket, $delivery->message);
                    continue;
                }
                $result = $socket->broadcast($delivery->message);
                if ($result === WriteResult::Backpressured) {
                    $this->queue($socket, $delivery->message);
                } elseif ($result === WriteResult::Closed) {
                    $this->forget($socket);
                }
            } catch (\Throwable $error) {
                $this->retire($socket, 1011, 'Broadcast failed', $error);
            }
        }
        $this->schedule();
    }

    private function queue(Recipient $socket, string $message): void
    {
        $id = spl_object_id($socket);
        $backlog =
            $this->backlogs[$id] ??= new Backlog($socket, $this->maxBacklogBytes, $this->batch, $this->caughtUp(...));
        if (!$backlog->offer($message)) {
            ++$this->slowClients;
            $this->retire($socket, 1008, 'Read messages faster');
        }
    }

    private function caughtUp(Backlog $backlog, WriteResult $result, ?\Throwable $error): void
    {
        $socket = $backlog->socket;
        $this->removeBacklog(spl_object_id($socket));
        if ($error !== null) {
            $this->retire($socket, 1011, 'Broadcast failed', $error);
        } elseif ($result === WriteResult::Backpressured) {
            ++$this->slowClients;
            $this->retire($socket, 1008, 'Read messages faster');
        } elseif ($result === WriteResult::Closed) {
            $this->forget($socket);
        }
    }

    private function removeBacklog(int $id): void
    {
        $backlog = $this->backlogs[$id] ?? null;
        unset($this->backlogs[$id]);
        $backlog?->close();
    }

    /**
     * Close and membership release are independent cleanup steps. One
     * recipient's exception must never strand the remaining recipients.
     */
    private function retire(Recipient $socket, int $code, string $reason, ?\Throwable $failure = null): void
    {
        try {
            $socket->close($code, $reason);
        } catch (\Throwable $error) {
            $failure ??= $error;
        }
        $this->forget($socket);
        if ($failure !== null) {
            FailureReporter::report('Broadcast recipient failed; its memberships were released', $failure);
        }
    }

    /**
     * Lost updates are visible to clients as reconnects. They can then
     * refetch state instead of silently keeping an incomplete view.
     */
    private function gap(): void
    {
        $this->discard();
        $recipients = [];
        foreach ($this->channels as $sockets) {
            foreach ($sockets as $id => $socket) {
                $recipients[$id] = $socket;
            }
        }
        foreach ($recipients as $socket) {
            $this->retire($socket, 1012, 'Reconnect and refetch state');
        }
    }

    /**
     * Counts support diagnostics and leak checks without exposing sockets.
     *
     * @return array{channels: int, connections: int, slow_clients: int, pending_bytes: int, backlog_bytes: int}
     */
    public function stats(): array
    {
        $backlogBytes = 0;
        foreach ($this->backlogs as $backlog) {
            $backlogBytes += $backlog->bytes();
        }
        return [
            'channels' => count($this->channels),
            'connections' => count($this->memberships),
            'slow_clients' => $this->slowClients,
            'pending_bytes' => $this->pendingBytes,
            'backlog_bytes' => $backlogBytes,
        ];
    }

    public function close(): void
    {
        $this->stopped = true;
        $this->discard();
        $this->carrier->close();
        $this->channels = [];
        $this->memberships = [];
    }

    private function discard(): void
    {
        if ($this->continuation !== null) {
            $this->loop->cancel($this->continuation);
            $this->continuation = null;
        }
        $this->pending = new \SplQueue();
        $this->pendingBytes = 0;
        foreach (array_keys($this->backlogs) as $id) {
            $this->removeBacklog($id);
        }
    }
}
