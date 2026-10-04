<?php

declare(strict_types=1);

namespace Crab\Engine\Network;

use Crab\Engine\EventLoop;
use Crab\Engine\FailureReporter;

final class UdpEndpoint
{
    private ?string $readWatcher = null;
    private ?string $writeWatcher = null;
    private \SplQueue $output;
    private int $queuedBytes = 0;
    private bool $closed = false;
    private bool $draining = false;
    private \Closure $handler;
    private array $closeCallbacks = [];
    private int $received = 0;
    private int $sent = 0;
    private int $dropped = 0;
    private int $errors = 0;

    public function __construct(
        private readonly EventLoop $loop,
        private mixed $socket,
        callable $handler,
        public readonly int $maxDatagramSize = 65507,
        private readonly int $maxQueueBytes = 1048576,
        private readonly int $maxQueuePackets = 1024,
    ) {
        if (!is_resource($socket) || get_resource_type($socket) !== 'stream' || $maxDatagramSize < 0 || $maxDatagramSize > 65507
            || $maxQueueBytes < 1 || $maxQueuePackets < 1) {
            throw new \InvalidArgumentException('Invalid UDP endpoint limits or socket.');
        }
        if (!stream_set_blocking($socket, false)) {
            throw new \InvalidArgumentException('UDP endpoint requires a nonblocking stream.');
        }
        $this->handler = $handler(...);
        $this->output = new \SplQueue();
        $this->readWatcher = $loop->onReadable($socket, fn() => $this->read());
    }

    /**
     * @return resource
     */
    public static function bind(string $address, array $options = []): mixed
    {
        if (!str_starts_with($address, 'udp://')) {
            throw new \InvalidArgumentException('UDP endpoints use udp:// addresses.');
        }
        $socket = @stream_socket_server(
            $address,
            $code,
            $message,
            STREAM_SERVER_BIND,
            stream_context_create(['socket' => $options]),
        );
        if ($socket === false) {
            throw new \RuntimeException("UDP bind failed: $message ($code)");
        }
        stream_set_blocking($socket, false);
        return $socket;
    }

    public function localAddress(): string
    {
        return $this->closed ? '' : (stream_socket_get_name($this->socket, false) ?: '');
    }

    public function onClose(callable $callback): self
    {
        if ($this->closed) {
            try { $callback($this); } catch (\Throwable $error) { FailureReporter::report('UDP close callback failed', $error); }
        } else {
            $this->closeCallbacks[] = $callback(...);
        }
        return $this;
    }

    public function send(string $payload, string $peer): bool
    {
        if ($this->closed || $this->draining) {
            return false;
        }
        $size = strlen($payload);
        if ($size > $this->maxDatagramSize) {
            throw new \LengthException('Datagram exceeds the configured size limit.');
        }
        if (!preg_match('/^(?:\[([^]]+)\]|([^:]+)):([0-9]+)$/D', $peer, $parts)
            || filter_var($parts[1] !== '' ? $parts[1] : $parts[2], FILTER_VALIDATE_IP, $parts[1] !== '' ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4) === false
            || (int) $parts[3] < 1 || (int) $parts[3] > 65535) {
            throw new \InvalidArgumentException('UDP peer must be a numeric IP:port address with a valid port.');
        }
        if ($this->output->count() >= $this->maxQueuePackets || $this->queuedBytes + $size > $this->maxQueueBytes) {
            ++$this->dropped;
            return false;
        }
        if ($this->output->isEmpty()) {
            $written = @stream_socket_sendto($this->socket, $payload, 0, $peer);
            if ($written === $size) {
                ++$this->sent;
                return true;
            }
            if ($written !== false && $written > 0) {
                ++$this->errors;
                return false;
            }
        }
        $this->output->enqueue([$payload, $peer]);
        $this->queuedBytes += $size;
        $this->writeWatcher ??= $this->loop->onWritable($this->socket, fn() => $this->flush());
        return true;
    }

    public function pause(): void
    {
        if ($this->readWatcher !== null) {
            $this->loop->disable($this->readWatcher);
        }
    }

    public function resume(): void
    {
        if (!$this->closed && !$this->draining && $this->readWatcher !== null) {
            $this->loop->enable($this->readWatcher);
        }
    }

    public function drain(): void
    {
        if ($this->closed) {
            return;
        }
        $this->draining = true;
        $this->pause();
        if ($this->output->isEmpty()) {
            $this->close();
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ([$this->readWatcher, $this->writeWatcher] as $watcher) {
            if ($watcher !== null) {
                $this->loop->cancel($watcher);
            }
        }
        $this->readWatcher = $this->writeWatcher = null;
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->output = new \SplQueue();
        $this->queuedBytes = 0;
        $callbacks = $this->closeCallbacks;
        $this->closeCallbacks = [];
        foreach ($callbacks as $callback) {
            try { $callback($this); } catch (\Throwable $error) { FailureReporter::report('UDP close callback failed', $error); }
        }
    }

    public function isClosed(): bool { return $this->closed; }

    public function stats(): array
    {
        return ['received' => $this->received, 'sent' => $this->sent, 'dropped' => $this->dropped,
            'errors' => $this->errors, 'queuedBytes' => $this->queuedBytes, 'queuedPackets' => $this->output->count()];
    }

    private function read(): void
    {
        for ($i = 0; $i < 64 && !$this->closed && !$this->draining; ++$i) {
            $peer = '';
            $payload = @stream_socket_recvfrom($this->socket, 65536, 0, $peer);
            if ($payload === false || $peer === '') {
                return;
            }
            ++$this->received;
            if (strlen($payload) > $this->maxDatagramSize) {
                ++$this->dropped;
                continue;
            }
            try {
                ($this->handler)($this, $payload, $peer);
            } catch (\Throwable $error) {
                ++$this->errors;
                FailureReporter::report('UDP handler failed', $error);
            }
            if ($this->readWatcher !== null && !$this->loop->isEnabled($this->readWatcher)) {
                return;
            }
        }
    }

    private function flush(): void
    {
        for ($i = 0; $i < 64 && !$this->output->isEmpty() && !$this->closed; ++$i) {
            [$payload, $peer] = $this->output->dequeue();
            $this->queuedBytes -= strlen($payload);
            $written = @stream_socket_sendto($this->socket, $payload, 0, $peer);
            if ($written === strlen($payload)) {
                ++$this->sent;
            } else {
                ++$this->errors;
                ++$this->dropped;
            }
        }
        if ($this->output->isEmpty() && $this->writeWatcher !== null) {
            $this->loop->cancel($this->writeWatcher);
            $this->writeWatcher = null;
            if ($this->draining) {
                $this->close();
            }
        }
    }
}
